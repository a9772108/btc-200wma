<?php

declare(strict_types=1);

namespace Btc200Wma;

use DateTimeImmutable;

/**
 * The 200-week moving average, computed from a daily price series.
 *
 * The canonical definition is the mean of the last 200 WEEKLY CLOSES, not the
 * mean of the last 1,400 daily prices. The two differ slightly; both modes are
 * offered so the choice is explicit rather than hidden.
 *
 * A weekly close is Sunday's price. The trailing partial week is discarded —
 * an unfinished week is not a close.
 */
final class MovingAverage
{
    public function __construct(private readonly int $weeks = 200)
    {
    }

    /**
     * @param list<array{date:string, usd:float}> $daily oldest first
     *
     * @return list<array{date:string, usd:float, ma:?float}> same length as input
     */
    public function apply(array $daily, bool $dailyMode = false): array
    {
        if ($daily === []) {
            return [];
        }

        return $dailyMode ? $this->byDailyWindow($daily) : $this->byWeeklyCloses($daily);
    }

    /** Straight rolling mean over weeks*7 calendar days. */
    private function byDailyWindow(array $daily): array
    {
        $window = $this->weeks * 7;
        $sum = 0.0;
        $queue = [];
        $out = [];

        foreach ($daily as $row) {
            $queue[] = $row['usd'];
            $sum += $row['usd'];

            if (count($queue) > $window) {
                $sum -= array_shift($queue);
            }

            $out[] = [
                'date' => $row['date'],
                'usd'  => $row['usd'],
                'ma'   => count($queue) === $window ? $sum / $window : null,
            ];
        }

        return $out;
    }

    /** Mean of the last N weekly closes, held flat across each week. */
    private function byWeeklyCloses(array $daily): array
    {
        // 1. Collect Sunday closes. The last calendar day may be mid-week, so
        //    drop any week that has not finished by discarding the tail that
        //    belongs to the current, incomplete week.
        $closes = [];              // weekKey => ['date' => string, 'usd' => float]

        // A week runs Monday..Sunday, so its key is the Sunday it ENDS on.
        // The final week is incomplete unless the series happens to end on a
        // Sunday, and an unfinished week is not a close.
        $lastComplete = $this->weekEndingSunday(new DateTimeImmutable(end($daily)['date']));
        $lastIsSunday = (int) (new DateTimeImmutable(end($daily)['date']))->format('N') === 7;
        if (!$lastIsSunday) {
            $lastComplete = $lastComplete->modify('-7 days');
        }

        foreach ($daily as $row) {
            $sunday = $this->weekEndingSunday(new DateTimeImmutable($row['date']));
            if ($sunday > $lastComplete) {
                continue;          // part of the unfinished week
            }
            $key = $sunday->format('Y-m-d');
            $closes[$key] = ['date' => $key, 'usd' => $row['usd']];
        }

        ksort($closes);
        $closes = array_values($closes);

        // 2. Rolling mean over the closes.
        $meanBySunday = [];
        $sum = 0.0;
        $queue = [];

        foreach ($closes as $c) {
            $queue[] = $c['usd'];
            $sum += $c['usd'];

            if (count($queue) > $this->weeks) {
                $sum -= array_shift($queue);
            }

            if (count($queue) === $this->weeks) {
                $meanBySunday[$c['date']] = $sum / $this->weeks;
            }
        }

        // 3. Project each weekly mean back onto every day of that week.
        $out = [];
        $carry = null;

        foreach ($daily as $row) {
            $sunday = $this->weekEndingSunday(new DateTimeImmutable($row['date']))->format('Y-m-d');

            if (isset($meanBySunday[$sunday])) {
                $carry = $meanBySunday[$sunday];
            }

            $out[] = [
                'date' => $row['date'],
                'usd'  => $row['usd'],
                'ma'   => $carry,
            ];
        }

        return $out;
    }

    /**
     * The Sunday that ENDS the Monday..Sunday week containing $d.
     *
     * ISO-8601 numbers Monday as 1 and Sunday as 7, so the distance forward to
     * the week's Sunday is (7 - N) days, or zero on a Sunday.
     */
    private function weekEndingSunday(DateTimeImmutable $d): DateTimeImmutable
    {
        $iso = (int) $d->format('N');
        $daysForward = (7 - $iso) % 7;

        return $daysForward === 0 ? $d : $d->modify("+{$daysForward} days");
    }
}
