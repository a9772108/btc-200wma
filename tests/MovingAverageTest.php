<?php

declare(strict_types=1);

/**
 * Tests for the moving average — plain PHP, no PHPUnit needed.
 *
 *   php tests/MovingAverageTest.php
 *
 * These pin down the two things that are easy to get wrong: which Sunday a
 * week belongs to, and whether the unfinished trailing week is excluded.
 */

require dirname(__DIR__) . '/src/App.php';

use Btc200Wma\MovingAverage;

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

/** @return list<array{date:string, usd:float}> */
function synthetic(string $start, array $prices): array
{
    $rows = [];
    $d = new DateTimeImmutable($start);

    foreach ($prices as $i => $p) {
        $rows[] = [
            'date' => ($i === 0 ? $d : $d->modify("+{$i} days"))->format('Y-m-d'),
            'usd'  => (float) $p,
        ];
    }

    return $rows;
}

// 2024-01-01 is a Monday, so a 21-day run is exactly three Mon..Sun weeks.
$prices = range(1, 21);

echo "weekly closes, 2-week window\n";
$series = (new MovingAverage(2))->apply(synthetic('2024-01-01', $prices), dailyMode: false);

// Sundays close at 7, 14, 21.
// First average exists once two closes do: mean(7,14) = 10.5, for 8..14 Jan.
check('first day has no average', $series[0]['ma'], null);
check('last day of week 1 has no average', $series[6]['ma'], null);
check('first day of week 2 gets mean(7,14)', $series[7]['ma'], 10.5);
check('last day of week 2 keeps mean(7,14)', $series[13]['ma'], 10.5);
check('first day of week 3 gets mean(14,21)', $series[14]['ma'], 17.5);
check('last day of week 3 keeps mean(14,21)', $series[20]['ma'], 17.5);

echo "\nunfinished trailing week is excluded\n";
// Same series plus Mon..Wed of a fourth week. Those three days must keep the
// last COMPLETE week's average, not start averaging an unfinished week.
$extended = array_merge($prices, [22, 23, 24]);
$series2 = (new MovingAverage(2))->apply(synthetic('2024-01-01', $extended), dailyMode: false);

check('Monday of the partial week carries the last complete average',
    $series2[21]['ma'], 17.5);
check('Wednesday of the partial week carries the same',
    $series2[23]['ma'], 17.5);

echo "\ncomplete series ending on a Sunday keeps that week\n";
$series3 = (new MovingAverage(2))->apply(synthetic('2024-01-01', $prices), dailyMode: false);
check('final Sunday included', $series3[20]['ma'], 17.5);

echo "\ndaily-window mode\n";
// 1-week window = 7 days: a straight rolling mean over the calendar.
$series4 = (new MovingAverage(1))->apply(synthetic('2024-01-01', $prices), dailyMode: true);
check('first 6 days have no average', $series4[5]['ma'], null);
check('day 7 = mean(1..7)', $series4[6]['ma'], 4.0);
check('day 8 = mean(2..8)', $series4[7]['ma'], 5.0);
check('last day = mean(15..21)', $series4[20]['ma'], 18.0);

echo "\nweek boundary is Monday-aligned\n";
// 2024-01-07 is a Sunday, so that single run of 7 days is exactly one week
// with one close (7). A 1-week average is therefore that close, carried across
// the days of the week it summarises.
$sunOnly = (new MovingAverage(1))->apply(synthetic('2024-01-01', range(1, 7)), dailyMode: false);
check('Sunday is the week close', $sunOnly[6]['ma'], 7.0);
check('earlier days of the same week carry it', $sunOnly[5]['ma'], 7.0);

// A week whose Sunday has not arrived yet must not produce a close at all.
$monToSat = (new MovingAverage(1))->apply(synthetic('2024-01-01', range(1, 6)), dailyMode: false);
check('week with no Sunday has no average', $monToSat[5]['ma'], null);

// Two weeks needed but only one provided.
$oneWeek = (new MovingAverage(2))->apply(synthetic('2024-01-01', range(1, 7)), dailyMode: false);
check('not enough closes yet', $oneWeek[6]['ma'], null);

echo "\n";
if ($failures === 0) {
    echo "PASS — {$checks} checks\n";
    exit(0);
}

echo "FAILED — {$failures} of {$checks} checks\n";
exit(1);
