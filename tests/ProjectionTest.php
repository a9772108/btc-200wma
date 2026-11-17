<?php

declare(strict_types=1);

/**
 * Tests for the 200-week average projection â€” plain PHP.
 *
 *   php tests/ProjectionTest.php
 *
 * The property that matters is the counter-intuitive one: with a flat assumed
 * price the average does NOT flatten. It moves, in the direction of whichever
 * prices are leaving the window. A projection that simply held the average
 * constant would look tidier and be wrong.
 */

require dirname(__DIR__) . '/src/App.php';

use Btc200Wma\Projection;

$failures = 0;
$checks = 0;

function check(string $label, mixed $actual, mixed $expected): void
{
    global $failures, $checks;
    $checks++;

    $ok = is_float($expected) && is_float($actual)
        ? abs($actual - $expected) < 1e-9
        : $actual === $expected;

    if ($ok) {
        echo "  ok   {$label}\n";
        return;
    }

    $failures++;
    printf("  FAIL %s\n       expected: %s\n       actual:   %s\n",
        $label,
        var_export($expected, true),
        var_export($actual, true)
    );
}

function makeSeries(int $days, float $price): array
{
    $out = [];
    $start = new DateTimeImmutable('2020-01-01');

    for ($i = 0; $i < $days; $i++) {
        $out[] = [
            'date' => $start->modify("+{$i} days")->format('Y-m-d'),
            'usd'  => $price,
        ];
    }

    return $out;
}

$projection = new Projection();

echo "empty input\n";

$empty = $projection->project([], 30);
check('no points', $empty['points'], []);
check('states there is no data', $empty['assumption'], 'no data');

echo "\nthe assumption is carried, not hidden\n";

$flat = $projection->project(makeSeries(1500, 100.0), 10);
check('assumption is stated in words', $flat['assumption'], 'price holds at the latest close');
check('assumed price is the latest close', $flat['assumed_price'], 100.0);
check('projects the requested number of days', count($flat['points']), 10);
check('window is weeks * 7', $flat['window_days'], 1400);

echo "\nflat history, flat assumption: the average holds\n";

// Every observation in the window is 100, so appending 100 changes nothing.
foreach ($flat['points'] as $p) {
    check('average stays 100 on ' . $p['date'], $p['ma'], 100.0);
}

check('ratio is 1.0 when price equals average', $flat['points'][0]['ratio'], 1.0);

echo "\ncheap prices leaving the window PUSH THE AVERAGE UP\n";

// 1,400 days at 100, then 400 days at 200 so the window is half cheap, half
// expensive and the latest close is 200. Each projected day drops a 100 and
// adds a 200, so the mean must rise.
$rising = array_merge(makeSeries(1400, 100.0), []);
$start = new DateTimeImmutable('2023-11-05');

for ($i = 0; $i < 400; $i++) {
    $rising[] = ['date' => $start->modify("+{$i} days")->format('Y-m-d'), 'usd' => 200.0];
}

$r = $projection->project($rising, 100);

$first = $r['points'][0]['ma'];
$last  = $r['points'][99]['ma'];

check('projected average rises', $last > $first, true);
printf("     first %.4f -> last %.4f over 100 days\n", $first, $last);

// Exactly: the window holds 1000 x 100 and 400 x 200, price assumed at 200.
// After 400 projected days the 100s have all aged out.
$long = $projection->project($rising, 1000);
check('after a full window the average reaches the assumed price', abs($long['points'][999]['ma'] - 200.0) < 1e-9, true);

echo "\nexpensive prices leaving the window PULL THE AVERAGE DOWN\n";

// Mirror image: the window holds 1000 x 200 and 400 x 100, with price assumed at
// 100. Each projected day retires a 200 and admits a 100, so the mean falls.
$falling = [];
$s = new DateTimeImmutable('2022-01-01');
for ($i = 0; $i < 1000; $i++) {
    $falling[] = ['date' => $s->modify("+{$i} days")->format('Y-m-d'), 'usd' => 200.0];
}
$s2 = new DateTimeImmutable('2024-09-27');
for ($i = 0; $i < 400; $i++) {
    $falling[] = ['date' => $s2->modify("+{$i} days")->format('Y-m-d'), 'usd' => 100.0];
}

$f = $projection->project($falling, 100);
check('projected average falls', $f['points'][99]['ma'] < $f['points'][0]['ma'], true);
printf("     first %.4f -> last %.4f over 100 days\n", $f['points'][0]['ma'], $f['points'][99]['ma']);

echo "\nratio reporting\n";

// Price below the average must report a ratio under 1.
$mixed = [];
$s3 = new DateTimeImmutable('2022-01-01');
for ($i = 0; $i < 1400; $i++) {
    $mixed[] = ['date' => $s3->modify("+{$i} days")->format('Y-m-d'), 'usd' => 200.0];
}
$s4 = new DateTimeImmutable('2025-11-01');
for ($i = 0; $i < 10; $i++) {
    $mixed[] = ['date' => $s4->modify("+{$i} days")->format('Y-m-d'), 'usd' => 100.0];
}

$m = $projection->project($mixed, 5);
check('ratio below 1 when price under the average', $m['points'][0]['ratio'] < 1.0, true);

echo "\nzero days returns no points but still reports the assumption\n";

$zero = $projection->project(makeSeries(1500, 100.0), 0);
check('no points requested', count($zero['points']), 0);
check('assumption still present', $zero['assumption'], 'price holds at the latest close');

echo "\n----------------------------------------------------------------\n";
echo ($failures === 0 ? 'PASS' : 'FAIL') . " â€” {$checks} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);

