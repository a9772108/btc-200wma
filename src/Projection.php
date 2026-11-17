<?php

declare(strict_types=1);

namespace Btc200Wma;

/**
 * A mechanical projection of the 200-week average — NOT a price forecast.
 *
 * This class cannot tell you where Bitcoin will trade. What it can do is exact
 * arithmetic: a moving average is a lagged mean, so given an assumption about
 * future prices the average itself follows deterministically. The assumption
 * used here is the simplest one that can be stated in a sentence:
 *
 *   "the price holds at its latest close."
 *
 * Under that assumption the average keeps changing anyway, because the window
 * retires old observations and admits the assumed price. That produces a result
 * many people find counter-intuitive and worth seeing:
 *
 *   When the window is currently discarding prices BELOW the current price, the
 *   average RISES even in a flat market. In November 2026 the window is shedding
 *   2022-23's lows, so a flat $65.3k carries the average from $67.9k toward
 *   $77k over the following year — price falls further behind, not closer.
 *
 * So "1x the average" is not a fixed level to aim at. It moves, and its
 * direction depends on which prices are leaving the window.
 *
 * Both average definitions are supported by appending synthetic days and
 * running the SAME MovingAverage used for the chart, rather than reimplementing
 * the arithmetic here. A projected line that quietly used a different formula
 * from the line it continues would be worse than no projection at all.
 */
final class Projection
{
    /** Days projected forward by default. */
    public const DEFAULT_DAYS = 730;

    public function __construct(private readonly int $weeks = 200)
    {
    }

    /**
     * @param list<array{date:string, usd:float}> $daily oldest first
     *
     * @return array{
     *     assumption:string,
     *     assumed_price:float,
     *     from:string,
     *     window_days:int,
     *     points:list<array{date:string, ma:float, ratio:float}>
     * }
     */
    public function project(array $daily, int $days = self::DEFAULT_DAYS, bool $dailyMode = false): array
    {
        $n = count($daily);
        $window = $this->weeks * 7;

        $assumed = $n === 0 ? 0.0 : (float) $daily[$n - 1]['usd'];
        $lastDate = $n === 0 ? '' : (string) $daily[$n - 1]['date'];
        $days = max(0, $days);

        if ($n === 0 || $days === 0) {
            return [
                'assumption'    => $n === 0 ? 'no data' : 'price holds at the latest close',
                'assumed_price' => $assumed,
                'from'          => $lastDate,
                'window_days'   => $window,
                'points'        => [],
            ];
        }

        // Extend the series with flat synthetic days, then run the real average
        // over the whole thing. Using MovingAverage rather than a local rolling
        // sum keeps the projection continuous with the plotted line, whichever
        // definition is selected.
        $extended = $daily;
        $start = new \DateTimeImmutable($lastDate);

        for ($i = 1; $i <= $days; $i++) {
            $extended[] = [
                'date' => $start->modify("+{$i} days")->format('Y-m-d'),
                'usd'  => $assumed,
            ];
        }

        $series = (new MovingAverage($this->weeks))->apply($extended, $dailyMode);
        $future = array_slice($series, $n);

        $points = [];

        foreach ($future as $row) {
            if ($row['ma'] === null) {
                continue;
            }

            $points[] = [
                'date'  => $row['date'],
                'ma'    => (float) $row['ma'],
                // How many times the assumed price is of the projected average.
                // Below 1.0 means price is under the average.
                'ratio' => $row['ma'] > 0.0 ? $assumed / (float) $row['ma'] : 0.0,
            ];
        }

        return [
            'assumption'    => 'price holds at the latest close',
            'assumed_price' => $assumed,
            'from'          => $lastDate,
            'window_days'   => $window,
            'points'        => $points,
        ];
    }
}
