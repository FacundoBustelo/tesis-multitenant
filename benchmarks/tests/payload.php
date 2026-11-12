<?php
require __DIR__.'/../fixtures/data.php';
function expect(bool $ok): void { if (!$ok) throw new RuntimeException('Payload assertion failed'); }
expect(fixturePayload(1,1) === fixturePayload(1,1));
expect(fixturePayload(1,1) !== fixturePayload(2,1));
for ($i=1; $i<=100; $i++) {
    $p=fixturePayload(1,$i);
    expect(strlen($p['title'])===80);
    expect($i%10===0 ? $p['content']===null : strlen($p['content'])===1024);
}
echo "202 deterministic payload assertions passed\n";
