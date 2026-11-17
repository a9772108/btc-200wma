<?php

declare(strict_types=1);

namespace Btc200Wma;

/**
 * The Pi Cycle Top indicator.
 *
 * Two lines, both built from daily closes:
 *
 *   fast = 111-day simple moving average
 *   slow = 2 x the 350-day simple moving average
 *
 * Historically, when the 111DMA rises through 2 x 350DMA it has coincided with
 * Bitcoin cycle highs within a few days. The name comes from 350 / 111 = 3.153,
 * which is the closest you get to pi by dividing 350 by a whole number.
 *
 * Two details that are easy to get wrong, and both are deliberate here:
 *
 *  - The multiple applies to the PRICE VALUE of the 350DMA, not to the number
 *    of days. "350DMA x 2" is twice the average price, not a 700-day average.
 *  - The signal is a CROSSING, not a level. 111DMA sitting above 2 x 350DMA is
 *    the after-effect; the event is the day it moved from at-or-below to above.
 *
 * Definition taken from the indicator's own documentation (lookintobitcoin.com,
 * retrieved 2026-11-16).
 */
final class PiCycle
{
    public const FAST_DAYS   = 111;
    public const SLOW_DAYS   = 350;
    public const MULTIPLE    = 2.0;

    /**
     * @param list<array{date:string, usd:float}> $daily oldest first
     *
     * @return list<array{date:string, fast:?float, slow:?float}>
     *         same length as input; both values null until enough history exists
     */
    public function apply(array $daily): array
    {
        $fast = $this->rollingMean($daily, self::FAST_DAYS);
        $slow = $this->rollingMean($daily, self::SLOW_DAYS);

        $out = [];

        foreach ($daily as $i => $row) {
            $out[] = [
                'date' => $row['date'],
                'fast' => $fast[$i],
                'slow' => $slow[$i] === null ? null : $slow[$i] * self::MULTIPLE,
            ];
        }

        return $out;
    }

    /**
     * Dates on which the fast line crossed from at-or-below the slow line to
     * above it — the cycle-top signal.
     *
     * @param list<array{date:string, fast:?float, slow:?float}> $series
     *
     * @return list<string> ISO dates, oldest first
     */
    public function topCrosses(array $series): array
    {
        $crosses = [];
        $previous = null;

        foreach ($series as $row) {
            if ($row['fast'] === null || $row['slow'] === null) {
                continue;
            }

            $above = $row['fast'] > $row['slow'];

            // Only a genuine transition counts: the first comparable day has no
            // earlier state to have crossed from.
            if ($previous !== null && $above && $previous === false) {
                $crosses[] = $row['date'];
            }

            $previous = $above;
        }

        return $crosses;
    }

    /**
     * Rolling simple mean over $window days; null until the window is full.
     *
     * Sums and subtracts rather than re-adding the window, so a 6,000-row
     * series stays O(n) instead of O(n x window).
     *
     * @param list<array{date:string, usd:float}> $daily
     *
     * @return list<?float>
     */
    private function rollingMean(array $daily, int $window): array
    {
        $out = [];
        $sum = 0.0;
        $queue = [];

        foreach ($daily as $row) {
            $queue[] = (float) $row['usd'];
            $sum += (float) $row['usd'];

            if (count($queue) > $window) {
                $sum -= array_shift($queue);
            }

            $out[] = count($queue) === $window ? $sum / $window : null;
        }

        return $out;
    }
}
