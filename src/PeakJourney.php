<?php

declare(strict_types=1);

namespace Btc200Wma;

/**
 * How far each cycle peak ran above the 200-week average, and how long it took
 * to get there from the average.
 *
 * Two numbers per peak, answering the same question from different sides:
 *
 *  - the MULTIPLE — peak price divided by the 200-week average on that day. How
 *    many times the average the top was.
 *  - the JOURNEY — days spent continuously above the average before the peak,
 *    starting from the last day price was at or below it.
 *
 * A limitation that cannot be engineered away, only reported: the 200-week
 * average needs 1,400 days of history, so it does not exist before mid-2014.
 * The 2011 and 2013 peaks predate it and have no multiple. They are returned
 * with nulls rather than dropped, so a caller shows them as unavailable instead
 * of silently omitting two of the five tops.
 */
final class PeakJourney
{
    /**
     * @param list<array{date:string, usd:float, confirmed:bool}> $peaks
     * @param list<array{date:string, usd:float, ma:?float}>      $series from MovingAverage
     *
     * @return list<array{
     *     date:string, usd:float, confirmed:bool,
     *     ma:?float, multiple:?float,
     *     days_above:?int, crossed_above:?string
     * }>
     */
    public function annotate(array $peaks, array $series): array
    {
        $byDate = [];
        foreach ($series as $i => $row) {
            $byDate[$row['date']] = $i;
        }

        $out = [];

        foreach ($peaks as $peak) {
            $i = $byDate[$peak['date']] ?? null;
            $ma = $i === null ? null : $series[$i]['ma'];

            $entry = [
                'date'         => $peak['date'],
                'usd'          => $peak['usd'],
                'confirmed'    => $peak['confirmed'],
                'ma'           => $ma,
                'multiple'     => ($ma === null || $ma <= 0.0) ? null : $peak['usd'] / $ma,
                'days_above'   => null,
                'crossed_above' => null,
            ];

            // Only a peak that falls inside the averaged range can have a
            // journey measured against it.
            if ($i !== null && $ma !== null) {
                $lastBelow = $this->lastDayAtOrBelow($series, $i);

                if ($lastBelow !== null) {
                    // The crossing itself is the following day; the count runs
                    // from there to the peak, inclusive.
                    $entry['days_above']    = $i - $lastBelow;
                    $entry['crossed_above'] = $series[$lastBelow + 1]['date'] ?? null;
                }
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Index of the most recent day at or below the average, walking back from
     * $from. Null when price has been above the average for as long as the
     * average has existed — there is no crossing to measure from.
     *
     * @param list<array{date:string, usd:float, ma:?float}> $series
     */
    private function lastDayAtOrBelow(array $series, int $from): ?int
    {
        for ($k = $from; $k >= 0; $k--) {
            $ma = $series[$k]['ma'];

            if ($ma === null) {
                // Ran past the start of the averaged range without finding the
                // price back at the average.
                return null;
            }

            if ($series[$k]['usd'] <= $ma) {
                return $k;
            }
        }

        return null;
    }
}
