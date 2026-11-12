<?php
// Bounded HTTP checks only. Tokens and login payloads never leave this process.
require __DIR__.'/data.php';
$checks = [];
set_exception_handler(static function (Throwable $error) use (&$checks): void {
    echo json_encode(['checks'=>$checks, 'passed'=>false, 'error'=>$error->getMessage()], JSON_THROW_ON_ERROR);
    exit(1);
});
function verify(bool $ok, string $label): void
{
    if (!$ok) throw new RuntimeException($label);
}
function request(string $method, string $path, int $expected, ?string $token = null, ?array $payload = null): ?array
{
    global $checks;
    $headers = "Accept: application/json\r\nContent-Type: application/json\r\n";
    if ($token !== null) $headers .= "Authorization: Bearer $token\r\n";
    $context = stream_context_create(['http'=>['method'=>$method, 'header'=>$headers,
        'content'=>$payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR),
        'ignore_errors'=>true, 'timeout'=>15]]);
    $body = file_get_contents('http://nginx/api/v1'.$path, false, $context);
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    $status = (int)($match[1] ?? 0);
    $checks[] = ['method'=>$method, 'path'=>$path, 'status'=>$status, 'expected'=>$expected];
    verify($status === $expected, "$method $path: expected $expected, received $status");
    return $status === 204 ? null : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
}
request('GET', '/records', 401);
for ($t=1; $t<=3; $t++) {
    foreach (['users'=>'user', 'staff'=>'staff'] as $route=>$type) {
        $auth = request('POST', "/auth/$route/login", 200, null,
            ['tenant'=>"bench-$t", 'email'=>'actor@example.test', 'password'=>'Benchmark-only-2026!']);
        $token = $auth['token'];
        $me = request('GET', '/auth/me', 200, $token);
        verify($me['actor_type'] === $type && $me['data']['tenant_id'] === $t, 'Actor identity mismatch');
        $list = request('GET', '/records?per_page=20', 200, $token);
        verify(count($list['data']) === 5, 'Expected five records');
        foreach ($list['data'] as $r) verify($r['tenant_id'] === $t, 'Tenant isolation failed');
        $id = ($t-1)*5+1;
        $show = request('GET', "/records/$id", 200, $token);
        $expected = fixturePayload($t, 1);
        verify($show['data']['id'] === $id && $show['data']['title'] === $expected['title']
            && $show['data']['content'] === $expected['content'], 'Fixture payload mismatch');
        $foreign = ($t % 3)*5+1;
        request('GET', "/records/$foreign", 404, $token);
        if ($type === 'user') request('POST', '/records', 403, $token, ['title'=>'forbidden']);
        if ($type === 'staff') {
            $created = request('POST', '/records', 201, $token, ['title'=>'bench-write-integration', 'content'=>'temporary']);
            $new = $created['data']['id'];
            verify($new === 15+$t, 'Record sequence not at baseline');
            request('PATCH', "/records/$new", 200, $token, ['content'=>'changed']);
            request('DELETE', "/records/$new", 204, $token);
            // Leave both content drift and a missing row for the restore check.
            request('PATCH', "/records/$id", 200, $token, ['content'=>'restore-must-recover-this']);
            request('DELETE', '/records/'.($id+1), 204, $token);
        }
        request('POST', '/auth/logout', 204, $token);
    }
}
echo json_encode(['checks'=>$checks, 'requests'=>count($checks), 'passed'=>true], JSON_THROW_ON_ERROR);
