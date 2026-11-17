<?php

declare(strict_types=1);

namespace Btc200Wma;

/**
 * Market-cycle peaks, detected from the price series rather than hardcoded.
 *
 * A "cycle top" has no formal definition, so this class states its own and
 * applies it consistently. A day is a peak when all three hold:
 *
 *   1. its print is higher than the previous day's, and
 *   2. it is a new all-time high, and
 *   3. no day within the following $window days trades higher.
 *
 * Put plainly: **a record high that went unbeaten for a year.** That is not an
 * arbitrary formula — every Bitcoin cycle top satisfied it, which is why the
 * rule reproduces them and rejects everything else.
 *
 * Each rule earns its place, and each was added because a simpler version drew
 * a chart that looked plausible and was wrong:
 *
 *  - Rule 1 collapses a plateau to one peak, on the day the price arrived,
 *    instead of one peak per day.
 *  - Rule 2 rejects a recovery. Climbing back to a level the price already sat
 *    at is not a top, and a purely forward-looking test would mark it, because
 *    nothing ahead happens to exceed it.
 *  - Rule 3 is the prominence test: it stops mid-cycle rallies being promoted to
 *    cycle tops.
 *
 * With a 365-day window this finds June 2011, December 2013, December 2017,
 * November 2021 and October 2025, and rejects the April 2013 and April 2021
 * double tops (beaten within the year) and the June 2019 bounce to $12.9k
 * (never a record high).
 *
 * Two limits worth knowing, both surfaced rather than hidden:
 *
 *  - Rule 3 looks forward, so a peak inside the last $window days cannot be
 *    confirmed: it may still be exceeded. Such peaks are reported with
 *    confirmed = false rather than left off, so a forming top stays visible
 *    without being presented as settled history.
 *  - These are daily AVERAGE prices, so the date of a top can differ by a day
 *    or so from sources quoting intraday highs. The value is the highest print
 *    in this dataset, not the highest tick ever traded.
 */
final class CyclePeaks
{
    /** Days ahead that must not exceed the peak. One year. */
    public const WINDOW_DAYS = 365;

    /**
     * @param list<array{date:string, usd:float}> $daily oldest first
     *
     * @return list<array{date:string, usd:float, confirmed:bool}> oldest first
     */
    public function detect(array $daily, int $window = self::WINDOW_DAYS): array
    {
        $n = count($daily);

        if ($n === 0) {
            return [];
        }

        if ($window < 1) {
            $window = 1;
        }

        $values = [];
        foreach ($daily as $row) {
            $values[] = (float) $row['usd'];
        }

        // Range-maximum lookup. "Is anything higher in the next $window days?"
        // must be answered over exactly that span: a plain suffix maximum covers
        // the whole remaining history, which silently ignores the window and
        // rejects real peaks that are exceeded years later.
        $rangeMax = $this->buildSparseTable($values);

        $prefixMax = [];
        $running = -INF;
        foreach ($values as $i => $v) {
            $running = max($running, $v);
            $prefixMax[$i] = $running;
        }

        $peaks = [];

        foreach ($values as $i => $value) {
            // Rule 1: rose into the day. Equal counts as "not risen".
            $roseInto = $i === 0
                ? ($n === 1 || $value > $values[1])
                : $value > $values[$i - 1];

            if (!$roseInto) {
                continue;
            }

            // Rule 2: a new all-time high.
            if ($i > 0 && $prefixMax[$i - 1] >= $value) {
                continue;
            }

            // Rule 3: nothing higher from tomorrow to the window's end.
            $end = min($n - 1, $i + $window);

            if ($i < $end && $rangeMax($i + 1, $end) > $value) {
                continue;
            }

            $peaks[] = [
                'date'      => (string) $daily[$i]['date'],
                'usd'       => $value,
                // Confirmed only when a full window of later data exists to have
                // failed to exceed it.
                'confirmed' => ($i + $window) < $n,
            ];
        }

        return $peaks;
    }

    /**
     * Sparse table for O(1) range maxima.
     *
     * The obvious alternative — scanning the window per day — is O(n x window),
     * over two million comparisons per request on this data. A sparse table
     * builds in O(n log n) and then answers any range in constant time.
     *
     * @param list<float> $values
     *
     * @return callable(int,int):float
     */
    private function buildSparseTable(array $values): callable
    {
        $n = count($values);

        $log = array_fill(0, max(2, $n + 1), 0);
        for ($i = 2; $i <= $n; $i++) {
            $log[$i] = $log[intdiv($i, 2)] + 1;
        }

        $table = [$values];

        for ($k = 1; (1 << $k) <= $n; $k++) {
            $previous = $table[$k - 1];
            $half = 1 << ($k - 1);
            $span = 1 << $k;
            $row = [];

            for ($i = 0; $i + $span <= $n; $i++) {
                $row[$i] = max($previous[$i], $previous[$i + $half]);
            }

            $table[$k] = $row;
        }

        return static function (int $left, int $right) use ($table, $log): float {
            if ($left > $right) {
                return -INF;
            }

            $k = $log[$right - $left + 1];
            $span = 1 << $k;

            return max($table[$k][$left], $table[$k][$right - $span + 1]);
        };
    }
}
