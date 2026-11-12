<?php
// Pure deterministic payload; independent of Faker, locale and PRNG implementation.
function fixturePayload(int $tenant, int $ordinal): array
{
    $key = "20261102:$tenant:$ordinal";
    $title = substr(str_pad("Record $key ", 80, 'x'), 0, 80);
    $content = $ordinal % 10 === 0 ? null : substr(str_repeat(hash('sha256', $key), 16), 0, 1024);
    return ['title' => $title, 'content' => $content];
}
