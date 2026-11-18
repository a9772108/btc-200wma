<?php

declare(strict_types=1);

/**
 * Tests for peak-vs-200-week-average analysis â€” plain PHP.
 *
 *   php tests/PeakJourneyTest.php
 *
 * The failure that matters here is a plausible number built on a missing
 * average: dividing by null, or quietly reporting the 2011 peak as "0Ã—", would
 * put a wrong figure in a table people read as fact.
 */

require dirname(__DIR__) . '/src/App.php';

use Btc200Wma\PeakJourney;

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

function day(int $offset): string
{
    return (new DateTimeImmutable('2020-01-01'))->modify("+{$offset} days")->format('Y-m-d');
}

$journey = new PeakJourney();

echo "a peak before the average exists\n";

// Peak on day 5, average only from day 3 onwards is not enough â€” null here.
$earlyPeak = [['date' => day(5), 'usd' => 100.0, 'confirmed' => true]];
$earlySeries = [];
for ($d = 0; $d <= 20; $d++) {
    $earlySeries[] = ['date' => day($d), 'usd' => 10.0, 'ma' => null];
}

$r = $journey->annotate($earlyPeak, $earlySeries);
check('one entry returned', count($r), 1);
check('multiple is null, not zero', $r[0]['multiple'], null);
check('journey is null', $r[0]['days_above'], null);
check('peak is kept rather than dropped', $r[0]['usd'], 100.0);

echo "\na simple rise above the average\n";

// Price at 10 with average 10 (at/below) for days 0-9; above from day 10.
// Peak at day 20 at 40. Average on day 20 is 8, so multiple = 5.
$series = [];
for ($d = 0; $d <= 30; $d++) {
    $series[] = [
        'date' => day($d),
        'usd'  => $d < 10 ? 10.0 : ($d === 20 ? 40.0 : 20.0),
        'ma'   => $d < 10 ? 10.0 : 8.0,
    ];
}

$peaks = [['date' => day(20), 'usd' => 40.0, 'confirmed' => true]];
$a = $journey->annotate($peaks, $series)[0];

check('multiple is peak over average', abs($a['multiple'] - 5.0) < 1e-9, true);
check('peak average carried through', $a['ma'], 8.0);
check('crossed above on the first day above', $a['crossed_above'], day(10));
// Days 10 through 20 inclusive: 11 days above the average.
check('days above counts from the crossing to the peak', $a['days_above'], 11);

echo "\ntouching the average resets the journey\n";

// Price dips back to the average at day 15, so the journey restarts there.
$series2 = [];
for ($d = 0; $d <= 30; $d++) {
    $usd = 20.0;
    if ($d < 5) { $usd = 10.0; }
    if ($d === 15) { $usd = 8.0; }      // exactly at the average
    if ($d === 25) { $usd = 40.0; }

    $series2[] = ['date' => day($d), 'usd' => $usd, 'ma' => 8.0];
}

$p2 = [['date' => day(25), 'usd' => 40.0, 'confirmed' => true]];
$b = $journey->annotate($p2, $series2)[0];

check('journey measured from the touch, not the first rise', $b['days_above'], 10);
check('crossed above just after the touch', $b['crossed_above'], day(16));

echo "\nalways above the average since it began\n";

$series3 = [];
for ($d = 0; $d <= 30; $d++) {
    $series3[] = ['date' => day($d), 'usd' => 20.0, 'ma' => 10.0];
}

$p3 = [['date' => day(20), 'usd' => 20.0, 'confirmed' => true]];
$c = $journey->annotate($p3, $series3)[0];

check('multiple still computed', abs($c['multiple'] - 2.0) < 1e-9, true);
check('no crossing to measure from', $c['days_above'], null);
check('no invented crossing date', $c['crossed_above'], null);

echo "\ndays below the average across a cycle\n";

// Two peaks 100 days apart. Days 1-49 above the average, days 50-100 below.
// The cycle window runs from the day after the first peak to the second.
$cycSeries = [];
for ($d = 0; $d <= 100; $d++) {
    $cycSeries[] = [
        'date' => day($d),
        'usd'  => $d < 50 ? 20.0 : 5.0,
        'ma'   => 10.0,
    ];
}

$cycPeaks = [
    ['date' => day(0),  'usd' => 20.0, 'confirmed' => true],
    ['date' => day(100), 'usd' => 20.0, 'confirmed' => true],
];

$c = $journey->annotate($cycPeaks, $cycSeries);
check('first peak has no cycle to measure', $c[0]['cycle_days_below'], null);
check('cycle length counted', $c[1]['cycle_days'], 100);
// Days 50 through 100 inclusive are below: 51 of the 100 counted days.
check('days below counted across the cycle', $c[1]['cycle_days_below'], 51);

// With no average data in the window, null rather than a misleading zero.
$noMa = [];
for ($d = 0; $d <= 100; $d++) {
    $noMa[] = ['date' => day($d), 'usd' => 20.0, 'ma' => null];
}
$nm = $journey->annotate($cycPeaks, $noMa);
check('no average in the window means null', $nm[1]['cycle_days_below'], null);

echo "\nconfirmed flag survives the annotation\n";

$p4 = [['date' => day(20), 'usd' => 40.0, 'confirmed' => false]];
check('unconfirmed peak stays unconfirmed', $journey->annotate($p4, $series)[0]['confirmed'], false);

echo "\norder is preserved\n";

$multi = [
    ['date' => day(20), 'usd' => 40.0, 'confirmed' => true],
    ['date' => day(25), 'usd' => 30.0, 'confirmed' => true],
];
$m = $journey->annotate($multi, $series);
check('two entries', count($m), 2);
check('first date first', $m[0]['date'], day(20));
check('second date second', $m[1]['date'], day(25));

echo "\n----------------------------------------------------------------\n";
echo ($failures === 0 ? 'PASS' : 'FAIL') . " â€” {$checks} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
