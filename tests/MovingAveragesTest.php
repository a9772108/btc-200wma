<?php

declare(strict_types=1);

/**
 * Tests for the 50/200-day averages and cross detection — plain PHP.
 *
 *   php tests/MovingAveragesTest.php
 *
 * The failure this guards against is reporting a LEVEL as a crossing: "price is
 * below the 200DMA" is a state that lasts months, while "death cross" is the
 * single day it changed. Confusing the two quietly inflates the signal count
 * from a handful to thousands.
 */

require dirname(__DIR__) . '/src/App.php';

use Btc200Wma\MovingAverages;

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

function series(array $rows): array
{
    return $rows;
}

$ma = new MovingAverages();

echo "windows are in days, and in the right order\n";

$flat = [];
$start = new DateTimeImmutable('2020-01-01');
for ($i = 0; $i < 260; $i++) {
    $flat[] = ['date' => $start->modify("+{$i} days")->format('Y-m-d'), 'usd' => 100.0];
}

$out = $ma->apply($flat);

check('length matches input', count($out), 260);
check('fast null on day 49', $out[48]['fast'], null);
check('fast appears on day 50', $out[49]['fast'], 100.0);
check('slow null on day 199', $out[198]['slow'], null);
check('slow appears on day 200', $out[199]['slow'], 100.0);

// Rising series: 50DMA of 1..50 = 25.5, 200DMA of 1..200 = 100.5.
$rising = [];
for ($i = 0; $i < 260; $i++) {
    $rising[] = ['date' => $start->modify("+{$i} days")->format('Y-m-d'), 'usd' => (float) ($i + 1)];
}

$r = $ma->apply($rising);
check('50DMA of 1..50 is 25.5', $r[49]['fast'], 25.5);
check('200DMA of 1..200 is 100.5', $r[199]['slow'], 100.5);

echo "\nwise 50-day average lags less than the 200-day\n";

// A step up: the 50DMA reacts long before the 200DMA.
$step = [];
for ($i = 0; $i < 400; $i++) {
    $step[] = [
        'date' => $start->modify("+{$i} days")->format('Y-m-d'),
        'usd'  => $i < 200 ? 100.0 : 300.0,
    ];
}

$s = $ma->apply($step);
check('50DMA fully reacts within 50 days of the step', $s[249]['fast'], 300.0);
check('200DMA takes the full 200 days', abs($s[249]['slow'] - 300.0) > 1.0, true);
check('200DMA has fully reacted 200 days after the step', $s[399]['slow'], 300.0);

echo "\ndeath and golden crosses\n";

$manual = [
    ['date' => '2024-01-01', 'fast' => 120.0, 'slow' => 100.0],  // above
    ['date' => '2024-01-02', 'fast' => 110.0, 'slow' => 100.0],  // above
    ['date' => '2024-01-03', 'fast' => 95.0,  'slow' => 100.0],  // crosses below -> death
    ['date' => '2024-01-04', 'fast' => 90.0,  'slow' => 100.0],  // stays below
    ['date' => '2024-01-05', 'fast' => 105.0, 'slow' => 100.0],  // crosses above -> golden
];

$c = $ma->crosses($manual);
check('one death cross', $c['death'], ['2024-01-03']);
check('one golden cross', $c['golden'], ['2024-01-05']);

$staysBelow = [
    ['date' => '2024-02-01', 'fast' => 90.0, 'slow' => 100.0],
    ['date' => '2024-02-02', 'fast' => 80.0, 'slow' => 100.0],
    ['date' => '2024-02-03', 'fast' => 70.0, 'slow' => 100.0],
];
check('being below is not a repeating signal', $ma->crosses($staysBelow), ['death' => [], 'golden' => []]);

$touching = [
    ['date' => '2024-03-01', 'fast' => 100.0, 'slow' => 100.0],  // equal, not below
    ['date' => '2024-03-02', 'fast' => 99.0,  'slow' => 100.0],  // now below
];
check('touching then falling counts as a death cross', $ma->crosses($touching)['death'], ['2024-03-02']);

$gaps = [
    ['date' => '2024-04-01', 'fast' => null, 'slow' => null],
    ['date' => '2024-04-02', 'fast' => 120.0, 'slow' => 100.0],
    ['date' => '2024-04-03', 'fast' => null, 'slow' => null],
    ['date' => '2024-04-04', 'fast' => 90.0, 'slow' => 100.0],
];
check('nulls are skipped, state carries across them', $ma->crosses($gaps)['death'], ['2024-04-04']);

$startsBelow = [
    ['date' => '2024-05-01', 'fast' => 90.0, 'slow' => 100.0],
    ['date' => '2024-05-02', 'fast' => 80.0, 'slow' => 100.0],
];
check('already below on the first comparable day is not a crossing', $ma->crosses($startsBelow)['death'], []);

echo "\n----------------------------------------------------------------\n";
echo ($failures === 0 ? 'PASS' : 'FAIL') . " — {$checks} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
