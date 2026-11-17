<?php

declare(strict_types=1);

/**
 * Tests for the Pi Cycle indicator — plain PHP, no PHPUnit.
 *
 *   php tests/PiCycleTest.php
 *
 * The expensive mistake this guards against is the multiple: it applies to the
 * PRICE VALUE of the 350-day average, not to the window length. Getting that
 * wrong still produces a plausible-looking chart, just the wrong one.
 */

require dirname(__DIR__) . '/src/App.php';

use Btc200Wma\PiCycle;

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

/** A flat series: every price the same, so both averages are known exactly. */
function flat(int $days, float $price): array
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

echo "rolling windows\n";

$series = (new PiCycle())->apply(flat(400, 100.0));

check('length matches input', count($series), 400);
check('first day has no fast value', $series[0]['fast'], null);
check('fast is null on day 110', $series[109]['fast'], null);
check('fast appears on day 111', $series[110]['fast'], 100.0);
check('slow is null on day 349', $series[348]['slow'], null);

// 2 x the 350-day AVERAGE PRICE, i.e. 200 here. If the multiple were applied to
// the window instead, this would be a 700-day mean and still null.
check('slow appears on day 350 as 2x the price', $series[349]['slow'], 200.0);
check('slow stays 2x on a flat series', $series[399]['slow'], 200.0);

echo "\nthe multiple is of price, not of days\n";

$prices = [];
$start = new DateTimeImmutable('2020-01-01');
for ($i = 0; $i < 500; $i++) {
    $prices[] = ['date' => $start->modify("+{$i} days")->format('Y-m-d'), 'usd' => (float) ($i + 1)];
}
$s = (new PiCycle())->apply($prices);

// 350DMA on day 350 (index 349) covers prices 1..350, mean 175.5, doubled 351.0.
check('350DMA of 1..350 is 175.5', $s[349]['slow'], 351.0);
// 111DMA on day 111 (index 110) covers prices 1..111, mean 56.0.
check('111DMA of 1..111 is 56.0', $s[110]['fast'], 56.0);

echo "\ncrossing detection\n";

$pi = new PiCycle();

$manual = [
    ['date' => '2024-01-01', 'fast' => 10.0, 'slow' => 20.0],  // below
    ['date' => '2024-01-02', 'fast' => 15.0, 'slow' => 20.0],  // below
    ['date' => '2024-01-03', 'fast' => 21.0, 'slow' => 20.0],  // crosses above
    ['date' => '2024-01-04', 'fast' => 25.0, 'slow' => 20.0],  // stays above
    ['date' => '2024-01-05', 'fast' => 18.0, 'slow' => 20.0],  // back below
    ['date' => '2024-01-06', 'fast' => 22.0, 'slow' => 20.0],  // crosses again
];

check('finds both crossings', $pi->topCrosses($manual), ['2024-01-03', '2024-01-06']);
check('staying above is not a new crossing', count($pi->topCrosses(array_slice($manual, 0, 4))), 1);

$touching = [
    ['date' => '2024-02-01', 'fast' => 20.0, 'slow' => 20.0],  // equal, not above
    ['date' => '2024-02-02', 'fast' => 20.5, 'slow' => 20.0],  // now above
];
check('touching then rising counts as a crossing', $pi->topCrosses($touching), ['2024-02-02']);

$startingAbove = [
    ['date' => '2024-03-01', 'fast' => 30.0, 'slow' => 20.0],
    ['date' => '2024-03-02', 'fast' => 31.0, 'slow' => 20.0],
];
check('already above at the first comparable day is not a crossing', $pi->topCrosses($startingAbove), []);

$withGaps = [
    ['date' => '2024-04-01', 'fast' => null, 'slow' => null],
    ['date' => '2024-04-02', 'fast' => 10.0, 'slow' => 20.0],
    ['date' => '2024-04-03', 'fast' => null, 'slow' => 20.0],
    ['date' => '2024-04-04', 'fast' => 25.0, 'slow' => 20.0],
];
check('nulls are skipped, and state carries across them', $pi->topCrosses($withGaps), ['2024-04-04']);

echo "\n----------------------------------------------------------------\n";
echo ($failures === 0 ? 'PASS' : 'FAIL') . " — {$checks} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
