<?php

declare(strict_types=1);

/**
 * Tests for cycle-peak detection — plain PHP.
 *
 *   php tests/CyclePeaksTest.php
 *
 * The quiet failure this guards against is a peak that LOOKS right: a smaller
 * window happily promotes mid-cycle rallies to "cycle tops", and a chart that
 * marks 2019 as a cycle top is wrong in a way nobody notices without counting.
 */

require dirname(__DIR__) . '/src/App.php';

use Btc200Wma\CyclePeaks;

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

/** Build a series from [dayOffset => price] pairs over a yearly span. */
function build(array $prices): array
{
    $out = [];
    $start = new DateTimeImmutable('2018-01-01');
    $prev = -1;

    ksort($prices);

    foreach ($prices as $offset => $price) {
        for ($d = $prev + 1; $d <= $offset; $d++) {
            $out[] = ['date' => $start->modify("+{$d} days")->format('Y-m-d'), 'usd' => (float) $price];
        }
        $prev = $offset;
    }

    return $out;
}

$detector = new CyclePeaks();

echo "empty and single-point input\n";
check('empty series has no peaks', $detector->detect([]), []);
check('one day is a peak but unconfirmed', count($detector->detect([['date' => '2020-01-01', 'usd' => 5.0]])), 1);
check('that peak is flagged unconfirmed', $detector->detect([['date' => '2020-01-01', 'usd' => 5.0]])[0]['confirmed'], false);

echo "\na clean rise and fall\n";

// Flat 10s, a spike to 100 at day 500, flat 10s again, 1,400 days total.
$prices = [];
for ($d = 0; $d < 1400; $d++) {
    $prices[$d] = $d === 500 ? 100.0 : 10.0;
}

$spike = $detector->detect(build($prices), 100);
check('one peak found', count($spike), 1);
check('peak value', $spike[0]['usd'], 100.0);
check('peak date', $spike[0]['date'], (new DateTimeImmutable('2018-01-01'))->modify('+500 days')->format('Y-m-d'));
check('peak with data on both sides is confirmed', $spike[0]['confirmed'], true);

echo "\nthe window decides what counts as a cycle top\n";

// Two tops 300 days apart: a lower one at day 300, a higher one at day 600.
// Whether both survive depends entirely on the window, which is the point.
$two = [];
for ($d = 0; $d < 1800; $d++) {
    $two[$d] = 10.0;
}
$two[300] = 80.0;
$two[600] = 100.0;

$wide = $detector->detect(build($two), 500);
check('a 500-day window sees only the higher top', count($wide), 1);
check('and it is the higher one', $wide[0]['usd'], 100.0);

$narrow = $detector->detect(build($two), 200);
check('a 200-day window sees both', count($narrow), 2);
check('first of the two is the 80 top', $narrow[0]['usd'], 80.0);
check('second is the 100 top', $narrow[1]['usd'], 100.0);

echo "\nplateaus collapse to one peak\n";

$flatTop = [];
for ($d = 0; $d < 600; $d++) {
    $flatTop[$d] = 10.0;
}
$flatTop[200] = 50.0;
$flatTop[201] = 50.0;
$flatTop[202] = 50.0;

$plateau = $detector->detect(build($flatTop), 100);
check('three equal adjacent highs are one peak', count($plateau), 1);
check('keeping the first day', $plateau[0]['date'], (new DateTimeImmutable('2018-01-01'))->modify('+200 days')->format('Y-m-d'));

echo "\nconfirmation needs a full window of later data\n";

// A high on the last day has no future data to contradict it.
$recent = [];
for ($d = 0; $d < 500; $d++) {
    $recent[$d] = $d === 499 ? 99.0 : 10.0;
}

$tail = $detector->detect(build($recent), 100);
check('peak at the very end is found', count($tail), 1);
check('but is not confirmed', $tail[0]['confirmed'], false);

// A high exactly one full window from the end IS confirmable.
$edge = [];
for ($d = 0; $d < 700; $d++) {
    $edge[$d] = $d === 599 ? 99.0 : 10.0;
}

$ok = $detector->detect(build($edge), 100);
check('peak with a full window after it is confirmed', $ok[0]['confirmed'], true);

echo "a recovery to a previous level is not a peak\n";

// Price sits at 10, dips to 5, then climbs back to 10 and stays there. The day
// it returns to 10 is not a top: nothing ahead exceeds it, so a purely
// forward-looking rule would mark it. Rule 2 (must exceed the past window)
// is what rejects it.
$recovery = [];
for ($d = 0; $d < 900; $d++) {
    $recovery[$d] = 10.0;
}
$recovery[100] = 5.0;

$r = $detector->detect(build($recovery), 150);
check('climbing back to the old level is not a peak', count($r), 0);

echo "\ndown-then-up is not a peak\n";

$valley = [];
for ($d = 0; $d < 900; $d++) {
    $valley[$d] = 10.0;
}
$valley[100] = 5.0;      // a trough, not a peak
$valley[400] = 90.0;     // the real peak

$v = $detector->detect(build($valley), 150);
check('only the high is reported', count($v), 1);
check('the trough never appears', $v[0]['usd'], 90.0);

echo "\n----------------------------------------------------------------\n";
echo ($failures === 0 ? 'PASS' : 'FAIL') . " — {$checks} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
