import http from 'k6/http';
import { check, fail } from 'k6';
import { Counter, Trend } from 'k6/metrics';
import { SharedArray } from 'k6/data';
import exec from 'k6/execution';

const fixture = JSON.parse(open(__ENV.FIXTURE));
const tenants = new SharedArray('tenants', () => fixture.tenants);
const cycleTime = new Trend('write_cycle_ms', true);
const started = new Counter('cycles_started');
const completed = new Counter('cycles_completed');
const incomplete = new Counter('cycles_incomplete');
const failures = new Counter('operation_errors');
const integrity = new Counter('integrity_errors');
const unitsStarted = new Counter('work_units_started');
const elapsed = new Trend('observed_request_ms', true);
const scenarioStart = new Trend('scenario_start_epoch_ms');
const seenScenarios = {};
function admission() {
  unitsStarted.add(1, { window: Date.now() < exec.scenario.startTime + Number(duration.slice(0,-1))*1000 ? 'measurement' : 'drain' });
  if (!seenScenarios[exec.scenario.name]) { scenarioStart.add(exec.scenario.startTime); seenScenarios[exec.scenario.name]=true; }
}
const mode = __ENV.MODE || 'R';
const base = 'http://nginx/api/v1';
const max = Number(__ENV.MAX_VUS || 128);
const duration = mode === 'auth' ? '600s' : (__ENV.DURATION || '120s');
const closed = { executor: 'constant-vus', vus: Number(__ENV.VUS || 1), duration, gracefulStop: '30s' };
const arrival = (rate, fn) => ({ executor: 'constant-arrival-rate', exec: fn, rate, timeUnit: '1s', duration, preAllocatedVUs: max, maxVUs: max, gracefulStop: '30s' });
let scenarios = { main: __ENV.RATE ? arrival(Number(__ENV.RATE), 'work') : { ...closed, exec: 'work' } };
if (mode === 'auth') scenarios = { auth: { executor: 'constant-arrival-rate', exec: 'auth', rate: 1, timeUnit: '10s', duration: '600s', preAllocatedVUs: 1, maxVUs: 1, gracefulStop: '30s' } };
if (mode === 'interference') {
  scenarios = { victim: arrival(Number(__ENV.VICTIM_RATE || 1), 'victim') };
  if (Number(__ENV.NOISE_RATE || 0) > 0) scenarios.noise = arrival(Number(__ENV.NOISE_RATE), 'noise');
}
if (mode === 'warmup') scenarios = { warmup: { executor: 'shared-iterations', exec: 'warmup', vus: 1, iterations: tenants.length * Number(__ENV.WARMUP_REQUESTS || 20), maxDuration: '600s' } };
export const options = { scenarios, summaryTrendStats: ['count','avg','med','p(95)','p(99)','max'], thresholds: { operation_errors: ['count==0'], integrity_errors: [{ threshold:'count==0', abortOnFail:true }] }, systemTags: ['status','method','name','scenario','expected_response'], tags: { phase: mode === 'warmup' ? 'warmup' : 'measurement' } };
function integrityFailure(name) { failures.add(1,{name}); integrity.add(1,{name}); }
function request(method, path, token, data, name, expected) {
  const start = Date.now();
  const r = http.request(method, base + path, data === undefined ? null : JSON.stringify(data), { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json' }, tags: { name, window: Date.now() < exec.scenario.startTime + Number(duration.slice(0,-1))*1000 ? 'measurement' : 'drain' }, timeout: '30s' });
  elapsed.add(Date.now()-start, { name, window: Date.now() < exec.scenario.startTime + Number(duration.slice(0,-1))*1000 ? 'measurement' : 'drain' });
  let body; try { body = r.json(); } catch (_) { body = null; }
  const ok = check(r, { 'expected response': () => r.status === expected && (expected === 204 || !!body?.data) });
  if (!ok) failures.add(1, { name, status: String(r.status) });
  return { r, body, ok };
}
function chosen(index) { return tenants[index % tenants.length]; }
function token(t, staff=false) { return t[staff ? 'staff' : 'user'][(exec.vu.idInTest - 1) % max]; }
function rReadOrdinal(index, tenantCount) {
  const group = Math.floor(index / 4);
  return { tenantIndex: group % tenantCount, localIndex: 4 * Math.floor(group / tenantCount) + index % 4 };
}
function mixedReadOrdinal(index, tenantCount, offset) { return 12 * Math.floor(index / tenantCount) + offset; }
function readSelection(ids, localIndex) {
  // User list/show, then Staff list/show on the same existing document.
  return { staff: localIndex % 4 >= 2, isList: localIndex % 2 === 0, id: ids[Math.floor(localIndex / 4) % ids.length] };
}
function read(t, index) {
  const { staff, isList, id } = readSelection(t.ids, index);
  if (isList) {
    const result=request('GET','/records?per_page=20',token(t,staff),undefined,'list',200);
    if(result.ok && (!Array.isArray(result.body.data) || result.body.data.some(r=>Number(r.tenant_id)!==Number(t.id)))) integrityFailure('tenant_isolation');
  }
  else {
    const result = request('GET',`/records/${id}`,token(t,staff),undefined,'show',200);
    if (result.ok && (Number(result.body.data.id) !== Number(id) || Number(result.body.data.tenant_id) !== Number(t.id))) integrityFailure('identity');
  }
}
function write(t) {
  started.add(1); const start=Date.now(); let done=false;
  try {
    const create=request('POST','/records',token(t,true),{title:`bench-write-${exec.vu.idInTest}-${exec.scenario.iterationInTest}`,content:'x'.repeat(1024)},'POST',201);
    const id=create.body?.data?.id;
    if (!create.ok || !Number.isInteger(id)) return;
    if (Number(create.body.data.tenant_id)!==Number(t.id)) { integrityFailure('write_identity'); return; }
    const patch=request('PATCH',`/records/${id}`,token(t,true),{content:'y'.repeat(1024)},'PATCH',200);
    if (patch.ok && (Number(patch.body.data.id)!==id || Number(patch.body.data.tenant_id)!==Number(t.id))) integrityFailure('write_identity');
    const remove=request('DELETE',`/records/${id}`,token(t,true),undefined,'DELETE',204);
    done=patch.ok && remove.ok;
  } finally { cycleTime.add(Date.now()-start,{outcome:done?'complete':'incomplete',window:Date.now() < exec.scenario.startTime + Number(duration.slice(0,-1))*1000 ? 'measurement' : 'drain'}); if(done) completed.add(1); else incomplete.add(1); }
}
export function setup() {
  if (!fixture.tenants.length || Date.parse(fixture.expires_at)-Date.now()<900000) fail('Missing dataset or token lifetime <15 minutes');
  if (tenants.some(t=>t.staff.length<max || t.user.length<max || !t.ids.length)) fail('Insufficient tokens or IDs');
}
export function work() { admission();
  const i=exec.scenario.iterationInTest;
  const ordinal=mode==='R' ? rReadOrdinal(i, tenants.length) : null;
  const t=chosen(ordinal ? ordinal.tenantIndex : i);
  if(mode==='W') write(t);
  else if(mode==='M') { for(let j=0;j<12;j++) read(t,mixedReadOrdinal(i,tenants.length,j)); write(t); }
  else if(mode==='me') request('GET','/auth/me',token(t),undefined,'me',200);
  else read(t,ordinal ? ordinal.localIndex : i);
}
export function warmup() { admission(); const i=exec.scenario.iterationInTest; const count=Number(__ENV.WARMUP_REQUESTS||20); read(chosen(Math.floor(i/count)),i%count); }
export function victim() { admission(); read(tenants[Number(__ENV.VICTIM || 0)],exec.scenario.iterationInTest); }
export function noise() { admission(); const t=tenants[Number(__ENV.NOISE_TENANT || 1)]; if(__ENV.NOISE_MODE==='W') write(t); else read(t,exec.scenario.iterationInTest); }
export function auth() { admission();
  const t=chosen(exec.scenario.iterationInTest); const before=Date.now();
  const r=http.post(base+'/auth/staff/login',JSON.stringify({tenant:t.slug,email:'actor@example.test',password:'Benchmark-only-2026!'}),{headers:{'Content-Type':'application/json',Accept:'application/json'},tags:{name:'login'},timeout:'30s'});
  elapsed.add(Date.now()-before,{name:'login'});
  let bearer; try { bearer=r.json().token; } catch(_) {}
  if (!check(r,{'login':()=>r.status===200 && !!bearer})) failures.add(1,{name:'login'});
  else request('POST','/auth/logout',bearer,undefined,'logout',204);
}
export function handleSummary(data) { return { [__ENV.SUMMARY]: JSON.stringify(data,null,2) }; }
