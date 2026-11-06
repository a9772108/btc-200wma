<?php

declare(strict_types=1);

/**
 * Tests for the halving schedule and the next-halving projection.
 *
 *   php tests/HalvingsTest.php
 */

require dirname(__DIR__) . '/src/App.php';

use Btc200Wma\Halvings;

$failures = 0;
$checks = 0;

function check(string $label, mixed $actual, mixed $expected): void
{
    global $failures, $checks;
    $checks++;

    if ($actual === $expected) {
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

echo "next halving block\n";
check('one interval past the last recorded era', Halvings::nextBlock(), 1_050_000);
check('below the next boundary', Halvings::nextBlock(974_866), 1_050_000);
check('just after a halving', Halvings::nextBlock(840_001), 1_050_000);
check('exactly on a boundary rolls forward', Halvings::nextBlock(840_000), 1_050_000);

echo "\nprojection\n";
// 75,134 blocks remaining x 600s = 45,080,400s = 521.76 days.
$asOf = new DateTimeImmutable('2026-11-06 23:37:16');
check('projected date', Halvings::projectNext(974_866, $asOf), '2028-04-11');

// With no observed height there is no honest date to give.
check('no height means no date', Halvings::projectNext(null, $asOf), null);
check('no observation time means no date', Halvings::projectNext(974_866, null), null);
check('zero height is rejected', Halvings::projectNext(0, $asOf), null);

echo "\ncombined schedule\n";
$all = Halvings::all(974_866, $asOf);
check('four past plus one next', count($all), 5);

$past = array_values(array_filter($all, static fn ($h) => $h['estimated'] === false));
$next = array_values(array_filter($all, static fn ($h) => $h['estimated'] === true));

check('four historical halvings', count($past), 4);
check('exactly one projection', count($next), 1);

check('2012 date', $past[0]['date'], '2012-11-28');
check('2016 date', $past[1]['date'], '2016-07-09');
check('2020 date', $past[2]['date'], '2020-05-11');
check('2024 date', $past[3]['date'], '2024-04-20');
check('next block', $next[0]['block'], 1_050_000);
check('next is flagged as an estimate', $next[0]['estimated'], true);

// Every historical halving is exactly one interval after the previous one.
$intervals = true;
foreach ($past as $i => $h) {
    if ($i > 0 && $h['block'] - $past[$i - 1]['block'] !== Halvings::INTERVAL) {
        $intervals = false;
    }
}
check('historical halvings are one interval apart', $intervals, true);

echo "\nwithout a chain reading\n";
// Falling back must still list history, but must not invent a future date.
$noChain = Halvings::all(null, null);
check('history still listed', count($noChain), 4);
check('no projection without a reading', count(array_filter(
    $noChain,
    static fn ($h) => $h['estimated'] === true
)), 0);

echo "\n";
if ($failures === 0) {
    echo "PASS — {$checks} checks\n";
    exit(0);
}

echo "FAILED — {$failures} of {$checks} checks\n";
exit(1);
