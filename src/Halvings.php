<?php

declare(strict_types=1);

namespace Btc200Wma;

use DateTimeImmutable;

/**
 * Bitcoin halvings.
 *
 * Past dates are facts, taken from the timestamp of the first block of each
 * reward era (mempool.space):
 *
 *   210,000   2012-11-28 15:24 UTC
 *   420,000   2016-07-09 16:46 UTC
 *   630,000   2020-05-11 19:23 UTC
 *   840,000   2024-04-20 00:09 UTC   (the 19th in US timezones)
 *
 * The next one is a projection, not a fact. It happens at block 1,050,000, and
 * the date depends on how fast blocks are found, so it is always marked as an
 * estimate and carries the height and date it was derived from.
 */
final class Halvings
{
    /** Block reward halves every 210,000 blocks. */
    public const INTERVAL = 210_000;

    /** The first era began at block 0; the nth halving is at n * INTERVAL. */
    public const PAST = [
        ['block' => 210_000, 'date' => '2012-11-28'],
        ['block' => 420_000, 'date' => '2016-07-09'],
        ['block' => 630_000, 'date' => '2020-05-11'],
        ['block' => 840_000, 'date' => '2024-04-20'],
    ];

    /** Average block interval used for projection, in seconds. */
    private const SECONDS_PER_BLOCK = 600;

    /**
     * @param int|null               $height current chain height, if known
     * @param DateTimeImmutable|null $asOf   when that height was observed
     *
     * @return list<array{block:int, date:string, estimated:bool}>
     */
    public static function all(?int $height = null, ?DateTimeImmutable $asOf = null): array
    {
        $out = [];

        foreach (self::PAST as $h) {
            $out[] = [
                'block'     => $h['block'],
                'date'      => $h['date'],
                'estimated' => false,
            ];
        }

        $nextBlock = self::nextBlock();
        $nextDate = self::projectNext($height, $asOf);

        if ($nextDate !== null) {
            $out[] = [
                'block'     => $nextBlock,
                'date'      => $nextDate,
                'estimated' => true,
            ];
        }

        return $out;
    }

    /** The block height of the next halving. */
    public static function nextBlock(?int $height = null): int
    {
        if ($height === null) {
            // One past the last recorded era.
            $last = self::PAST[count(self::PAST) - 1]['block'];

            return $last + self::INTERVAL;
        }

        return (intdiv($height, self::INTERVAL) + 1) * self::INTERVAL;
    }

    /**
     * Project the date of the next halving from the current height.
     *
     * Returns null when the height is unknown, because a date invented from
     * nothing would look authoritative while being wrong.
     */
    public static function projectNext(?int $height, ?DateTimeImmutable $asOf): ?string
    {
        if ($height === null || $asOf === null || $height <= 0) {
            return null;
        }

        $remaining = self::nextBlock($height) - $height;
        if ($remaining <= 0) {
            return null;
        }

        $seconds = $remaining * self::SECONDS_PER_BLOCK;

        return $asOf->modify('+' . $seconds . ' seconds')->format('Y-m-d');
    }
}
