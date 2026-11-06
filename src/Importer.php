<?php

declare(strict_types=1);

namespace Btc200Wma;

use RuntimeException;

/**
 * Pulls the full daily price history and reconciles it into MySQL.
 *
 * The source reports a daily average, not a close, and begins with a run of
 * zero rows before the first real price. Zeros are dropped: they are "no data",
 * not "$0", and would drag any average computed over them.
 */
final class Importer
{
    public function __construct(
        private readonly PriceRepository $prices,
        private readonly array $source,
    ) {
    }

    /**
     * @return array{seen:int, stored:int, first:?string, last:?string}
     */
    public function run(): array
    {
        $runId = $this->prices->startImportRun();

        try {
            $raw = $this->download();
            $rows = $this->normalise($raw);

            if ($rows === []) {
                throw new RuntimeException('source returned no usable rows');
            }

            $stored = $this->prices->upsertMany($rows, 'blockchain.info');

            $first = $rows[0]['date'];
            $last = end($rows)['date'];

            $this->prices->finishImportRun(
                $runId,
                count($raw),
                $stored,
                'ok',
                sprintf('%d rows kept, %s to %s', count($rows), $first, $last)
            );

            return [
                'seen'   => count($raw),
                'stored' => $stored,
                'first'  => $first,
                'last'   => $last,
            ];
        } catch (\Throwable $e) {
            $this->prices->finishImportRun($runId, 0, 0, 'error', $e->getMessage());
            throw $e;
        }
    }

    /** @return list<array{x:int, y:float}> */
    private function download(): array
    {
        $url = (string) ($this->source['url'] ?? '');
        if ($url === '') {
            throw new RuntimeException('no source URL configured');
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => 'User-Agent: ' . ($this->source['agent'] ?? 'btc-200wma/1.0'),
                'timeout'       => (int) ($this->source['timeout'] ?? 90),
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false || $body === '') {
            throw new RuntimeException('could not download price history');
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['values']) || !is_array($decoded['values'])) {
            throw new RuntimeException('unexpected response shape from the source');
        }

        return array_values(array_filter(
            $decoded['values'],
            static fn ($v): bool => is_array($v) && isset($v['x'], $v['y'])
        ));
    }

    /**
     * @param list<array{x:int, y:float}> $raw
     *
     * @return list<array{date:string, usd:float}>
     */
    private function normalise(array $raw): array
    {
        $byDate = [];

        foreach ($raw as $point) {
            $price = (float) $point['y'];
            if ($price <= 0.0) {
                continue;  // pre-history placeholder rows
            }

            $date = gmdate('Y-m-d', (int) $point['x']);

            // If the source ever repeats a day, keep the last one seen.
            $byDate[$date] = ['date' => $date, 'usd' => $price];
        }

        ksort($byDate);

        // Guarantee one row per calendar day: gaps are forward-filled so the
        // rolling window counts days rather than observations.
        $filled = [];
        $prev = null;

        foreach ($byDate as $date => $row) {
            if ($prev !== null) {
                $cursor = (new \DateTimeImmutable($prev))->modify('+1 day');
                $target = new \DateTimeImmutable($date);

                while ($cursor < $target) {
                    $filled[] = ['date' => $cursor->format('Y-m-d'), 'usd' => $row['usd']];
                    $cursor = $cursor->modify('+1 day');
                }
            }

            $filled[] = $row;
            $prev = $date;
        }

        // Sanity: the calendar span must equal the row count, or the series has
        // a hole that would silently distort every average downstream.
        if ($filled !== []) {
            $span = (new \DateTimeImmutable($filled[0]['date']))
                ->diff(new \DateTimeImmutable(end($filled)['date']))
                ->days + 1;

            if ($span !== count($filled)) {
                throw new RuntimeException(
                    sprintf('series has gaps: %d days spanned but %d rows produced', $span, count($filled))
                );
            }
        }

        return $filled;
    }
}
