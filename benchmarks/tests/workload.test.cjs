// Pure selection checks and bounded fake HTTP checks; no k6 or network traffic.
const fs = require('node:fs'), vm = require('node:vm');
const assert = require('node:assert/strict');
const { test } = require('node:test');
const source = fs.readFileSync('benchmarks/k6/workload.js', 'utf8')
  .replace(/^import .*;\n/gm, '').replace(/export /g, '');
const profiles = JSON.parse(fs.readFileSync('benchmarks/config/pilot.json', 'utf8')).profiles;

function fixtureTenants(count = 3, ids = [7, 42, 9001]) {
  return Array.from({ length: count }, (_, t) => ({
    id: t + 1, slug: `bench-${t + 1}`, ids: ids.map(id => id + t * 100000),
    user: Array.from({ length: 128 }, (_, slot) => `user:${t + 1}:${slot}`),
    staff: Array.from({ length: 128 }, (_, slot) => `staff:${t + 1}:${slot}`),
  }));
}
function harness(mode, { statuses = [], tenants = fixtureTenants(), env = {}, response } = {}) {
  const samples = {}, calls = [];
  class Metric {
    constructor(name) { this.name = name; }
    add(value, tags) { (samples[this.name] ??= []).push({ value, tags }); }
  }
  const ctx = {
    __ENV: { MODE: mode, FIXTURE: 'fixture', SUMMARY: 'summary', ...env },
    open: () => JSON.stringify({ tenants, expires_at: new Date(Date.now() + 3600000).toISOString() }),
    SharedArray: function (_, fn) { return fn(); }, Counter: Metric, Trend: Metric, Date,
    exec: { vu: { idInTest: 1 }, scenario: { name: 'main', iterationInTest: 0, startTime: Date.now() } },
    check: (r, checks) => Object.values(checks).every(fn => fn(r)),
    fail: message => { throw Error(message); },
    http: { request(method, url, body, opts) {
      const call = { method, url, body, opts }; calls.push(call);
      const tenantId = Number(opts.headers.Authorization.split(':')[1]);
      const t = tenants.find(t => t.id === tenantId);
      const status = statuses[calls.length - 1] ?? ({ POST: 201, PATCH: 200, DELETE: 204 }[method] ?? 200);
      let data = method === 'POST' ? { id: 9000001, tenant_id: tenantId }
        : url.includes('?') ? t.ids.slice(0, 20).map(id => ({ id, tenant_id: tenantId }))
        : { id: Number(url.split('/').at(-1)), tenant_id: tenantId };
      if (response) data = response(data, call);
      return { status, json: () => ({ data }) };
    } },
  };
  vm.createContext(ctx); vm.runInContext(source, ctx);
  ctx.options = vm.runInContext('options', ctx); ctx.setup();
  const invoke = (entry, index, vu = 1) => {
    ctx.exec.scenario.iterationInTest = index; ctx.exec.vu.idInTest = vu;
    const start = calls.length; ctx[entry](); return calls.slice(start);
  };
  const count = name => (samples[name] ?? []).reduce((sum, p) => sum + p.value, 0);
  return { ctx, calls, samples, invoke, count };
}

// These loops invoke only pure selection functions, never HTTP mocks.
for (const [profile, [T, N]] of Object.entries(profiles)) {
  test(`arithmetic R ${profile}: full coverage, both roles, equal frequencies`, () => {
    const h = harness('R'), ids = Array.from({ length: N }, (_, n) => 7 + n * 13);
    const seen = Array.from({ length: T }, () => new Uint8Array(2 * N));
    const lists = new Uint32Array(T), shows = new Uint32Array(T);
    for (let i = 0; i < 4 * T * N; i++) {
      const { tenantIndex, localIndex } = h.ctx.rReadOrdinal(i, T);
      assert(tenantIndex >= 0 && tenantIndex < T);
      const selected = h.ctx.readSelection(ids, localIndex);
      if (selected.isList) lists[tenantIndex]++;
      else {
        shows[tenantIndex]++;
        const ordinal = (selected.id - 7) / 13;
        assert(Number.isInteger(ordinal) && ordinal >= 0 && ordinal < N);
        seen[tenantIndex][2 * ordinal + Number(selected.staff)]++;
      }
    }
    for (let t = 0; t < T; t++) {
      assert.equal(lists[t], 2 * N); assert.equal(shows[t], 2 * N);
      assert(seen[t].every(n => n === 1));
    }
    assert.equal(h.calls.length, 0);
  });
  test(`arithmetic M ${profile}: full coverage, both roles, equal frequencies`, () => {
    const h = harness('M'), ids = Array.from({ length: N }, (_, n) => 11 + n * 17);
    const gcd = (a, b) => b ? gcd(b, a % b) : a;
    const blocksPerTenant = N / gcd(N, 3), expected = 3 / gcd(N, 3);
    const seen = Array.from({ length: T }, () => new Uint8Array(2 * N));
    for (let i = 0; i < T * blocksPerTenant; i++) {
      for (let j = 0; j < 12; j++) {
        const selected = h.ctx.readSelection(ids, h.ctx.mixedReadOrdinal(i, T, j));
        if (!selected.isList) {
          const ordinal = (selected.id - 11) / 17;
          assert(Number.isInteger(ordinal) && ordinal >= 0 && ordinal < N);
          seen[i % T][2 * ordinal + Number(selected.staff)]++;
        }
      }
    }
    for (const tenant of seen) assert(tenant.every(n => n === expected));
    assert.equal(h.calls.length, 0);
  });
}
test('arithmetic: equivalent logical agenda with different physical IDs', () => {
  const { ctx } = harness('R'), a = [7, 42, 9001], b = [101, 600, 888888];
  for (let i = 0; i < 36; i++) {
    const x = ctx.readSelection(a, i), y = ctx.readSelection(b, i);
    assert.equal(x.staff, y.staff); assert.equal(x.isList, y.isList);
    if (!x.isList) assert.equal(a.indexOf(x.id), b.indexOf(y.id));
  }
});
test('HTTP R: one request per iteration, four-position agenda, discontinuous IDs', () => {
  const h = harness('R');
  for (let i = 0; i < 36; i++) {
    const calls = h.invoke('work', i); assert.equal(calls.length, 1);
    const call = calls[0], tenant = Math.floor(i / 4) % 3 + 1;
    assert.equal(call.method, 'GET');
    assert.equal(call.opts.headers.Authorization, `Bearer ${i % 4 < 2 ? 'user' : 'staff'}:${tenant}:0`);
    assert.equal(call.opts.tags.name, i % 2 ? 'show' : 'list');
    if (i % 2 === 0) assert(call.url.endsWith('/records?per_page=20'));
    if (i % 2) assert(call.url.endsWith(`/${[7, 42, 9001][Math.floor(i / 12)] + (tenant - 1) * 100000}`));
  }
  assert.equal(h.calls.length, 36); assert.equal(h.count('operation_errors'), 0);
});
for (const mode of ['R', 'M']) {
  test(`HTTP ${mode}: VU assignment cannot change tenant, role or document`, () => {
    const a = harness(mode), b = harness(mode);
    const signature = c => [c.url, c.opts.tags.name, c.opts.headers.Authorization.split(':').slice(0, 2).join(':')];
    for (const i of [35, 0, 18, 3, 1001, 19, 2]) {
      const x = a.invoke('work', i, 1), y = b.invoke('work', i, i % 128 + 1);
      assert.deepEqual(x.map(signature), y.map(signature));
    }
  });
}
test('HTTP M: twelve reads on three documents with both roles, three writes, balanced tenants', () => {
  const h = harness('M', { tenants: fixtureTenants(3, [7, 42, 9001, 17003, 60007]) });
  for (let i = 0; i < 6; i++) {
    const calls = h.invoke('work', i, i + 1), tenant = i % 3 + 1;
    assert.equal(calls.length, 15);
    assert.deepEqual(calls.slice(12).map(c => c.method), ['POST', 'PATCH', 'DELETE']);
    assert(calls.every(c => Number(c.opts.headers.Authorization.split(':')[1]) === tenant));
    const shows = calls.slice(0, 12).filter(c => c.opts.tags.name === 'show');
    assert.equal(shows.length, 6);
    for (let n = 0; n < 3; n++) {
      assert.equal(shows[2 * n].url, shows[2 * n + 1].url);
      const expected = [[7, 42, 9001], [17003, 60007, 7]][Math.floor(i / 3)][n];
      assert(shows[2 * n].url.endsWith(`/${expected + (tenant - 1) * 100000}`));
      assert(shows[2 * n].opts.headers.Authorization.startsWith('Bearer user:'));
      assert(shows[2 * n + 1].opts.headers.Authorization.startsWith('Bearer staff:'));
    }
    assert.equal(new Set(shows.map(c => c.url)).size, 3);
  }
  assert.equal(h.count('cycles_completed'), 6); assert.equal(h.count('operation_errors'), 0);
});
for (const requests of [2, 20]) {
  test(`HTTP warmup: ${requests} requests per tenant with local ordinal`, () => {
    const ids = [7, 42, 9001, 17003, 60007];
    const h = harness('warmup', { tenants: fixtureTenants(3, ids), env: { WARMUP_REQUESTS: String(requests) } });
    assert.equal(h.ctx.options.scenarios.warmup.iterations, 3 * requests);
    assert.equal(h.ctx.options.scenarios.warmup.vus, 1);
    for (let t = 0; t < 3; t++) for (let j = 0; j < requests; j++) {
      const calls = h.invoke('warmup', t * requests + j); assert.equal(calls.length, 1);
      const call = calls[0];
      assert.equal(call.opts.headers.Authorization, `Bearer ${j % 4 < 2 ? 'user' : 'staff'}:${t + 1}:0`);
      assert.equal(call.opts.tags.name, j % 2 ? 'show' : 'list');
      if (j % 2) assert(call.url.endsWith(`/${ids[Math.floor(j / 4) % ids.length] + t * 100000}`));
    }
    assert.equal(h.calls.length, 3 * requests); assert.equal(h.count('operation_errors'), 0);
  });
}
for (const [entry, env, tenant] of [
  ['victim', {}, 1], ['noise', { NOISE_MODE: 'R' }, 2],
  ['victim', { VICTIM: '2' }, 3], ['noise', { NOISE_MODE: 'R', NOISE_TENANT: '0' }, 1],
]) {
  test(`HTTP interference ${entry} tenant ${tenant}: complete local read period`, () => {
    const h = harness('interference', { env }), seen = new Map();
    for (let i = 0; i < 12; i++) {
      const calls = h.invoke(entry, i); assert.equal(calls.length, 1);
      const call = calls[0]; assert.equal(call.opts.headers.Authorization.split(':')[1], String(tenant));
      if (call.opts.tags.name === 'show') {
        const key = `${call.url}:${call.opts.headers.Authorization.split(':')[0]}`;
        seen.set(key, (seen.get(key) ?? 0) + 1);
      }
    }
    assert.equal(seen.size, 6); assert([...seen.values()].every(n => n === 1));
    assert.equal(h.count('operation_errors'), 0);
  });
}
for (const [label, index, response] of [
  ['foreign list tenant', 0, data => data.map(r => ({ ...r, tenant_id: 999 }))],
  ['foreign show tenant', 1, data => ({ ...data, tenant_id: 999 })],
  ['wrong show ID', 1, data => ({ ...data, id: 1234567 })],
  ['invalid list shape', 0, () => ({ id: 7, tenant_id: 1 })],
]) {
  test(`HTTP isolation: detects ${label}`, () => {
    const h = harness('R', { response }); h.invoke('work', index);
    assert.equal(h.count('operation_errors'), 1);
  });
}
test('HTTP W: successful POST/PATCH/DELETE and one cycle sample', () => {
  const h = harness('W'); h.invoke('work', 0);
  assert.deepEqual(h.calls.map(c => c.method), ['POST', 'PATCH', 'DELETE']);
  assert.equal(h.count('cycles_started'), 1); assert.equal(h.count('cycles_completed'), 1);
  assert.equal(h.samples.write_cycle_ms.length, 1); assert.equal(h.count('operation_errors'), 0);
});
test('HTTP W: failed POST leaves incomplete cycle', () => {
  const h = harness('W', { statuses: [500] }); h.invoke('work', 0);
  assert.equal(h.calls.length, 1); assert.equal(h.count('cycles_incomplete'), 1);
  assert.equal(h.count('cycles_completed'), 0); assert.equal(h.count('operation_errors'), 1);
});
test('HTTP W: failed PATCH still attempts DELETE', () => {
  const h = harness('W', { statuses: [201, 500, 204] }); h.invoke('work', 0);
  assert.deepEqual(h.calls.map(c => c.method), ['POST', 'PATCH', 'DELETE']);
  assert.equal(h.count('cycles_incomplete'), 1); assert.equal(h.count('operation_errors'), 1);
});
test('HTTP W: failed DELETE leaves incomplete cycle', () => {
  const h = harness('W', { statuses: [201, 200, 500] }); h.invoke('work', 0);
  assert.equal(h.count('cycles_incomplete'), 1); assert.equal(h.count('cycles_completed'), 0);
});
test('HTTP interference: write perturbator preserves cycle and fixed tenant', () => {
  const h = harness('interference', { env: { NOISE_MODE: 'W' } }); h.invoke('noise', 0);
  assert.deepEqual(h.calls.map(c => c.method), ['POST', 'PATCH', 'DELETE']);
  assert(h.calls.every(c => c.opts.headers.Authorization.startsWith('Bearer staff:2:')));
  assert.equal(h.count('cycles_completed'), 1);
});
test('setup still refuses an empty ID inventory', () => {
  assert.throws(() => harness('R', { tenants: fixtureTenants(1, []) }), /Insufficient/);
});
