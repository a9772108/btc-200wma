<?php

declare(strict_types=1);

namespace Btc200Wma;

/**
 * The 50-day and 200-day simple moving averages, and the crosses between them.
 *
 * A "death cross" is the 50DMA crossing BELOW the 200DMA; a "golden cross" is
 * the 50DMA crossing ABOVE it. Both are widely watched, and both are easy to
 * report wrongly:
 *
 *  - The event is a CROSSING, not a level. 50DMA sitting below 200DMA is the
 *    after-effect; the day it moved from at-or-above to below is the event.
 *    Reporting every day below as a "death cross" would turn two decades of
 *    chart into twenty thousand signals.
 *  - These are computed on DAILY closes, unlike the 200-WEEK average elsewhere
 *    in this app. 50 and 200 here mean days.
 */
final class MovingAverages
{
    public const FAST_DAYS = 50;
    public const SLOW_DAYS = 200;

    /**
     * @param list<array{date:string, usd:float}> $daily oldest first
     *
     * @return list<array{date:string, fast:?float, slow:?float}>
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
                'slow' => $slow[$i],
            ];
        }

        return $out;
    }

    /**
     * Crossings between the fast and slow lines, split by direction.
     *
     * The relation is tracked as a sign (+1 above, 0 equal, -1 below) rather
     * than a boolean. A boolean loses the case that matters most: the two
     * averages converge, touch, then separate. "Equal then below" is a death
     * cross, but as a boolean both days read "not above" and the signal is
     * silently dropped — which is exactly the day the lines cross.
     *
     * @param list<array{date:string, fast:?float, slow:?float}> $series
     *
     * @return array{death:list<string>, golden:list<string>} ISO dates, oldest first
     */
    public function crosses(array $series): array
    {
        $death  = [];
        $golden = [];
        $previous = null;   // +1 fast above, 0 equal, -1 fast below

        foreach ($series as $row) {
            if ($row['fast'] === null || $row['slow'] === null) {
                continue;
            }

            $relation = $row['fast'] <=> $row['slow'];

            // The first comparable day has no earlier state to have crossed
            // from, so it cannot be a crossing however it sits.
            if ($previous !== null && $relation !== 0) {
                if ($relation < 0 && $previous >= 0) {
                    $death[] = $row['date'];
                } elseif ($relation > 0 && $previous <= 0) {
                    $golden[] = $row['date'];
                }
            }

            $previous = $relation;
        }

        return ['death' => $death, 'golden' => $golden];
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
