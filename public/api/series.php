<?php

declare(strict_types=1);

/**
 * JSON API: GET /api/series.php
 *
 *   ?since=YYYY    only return days from that year onward
 *   ?range=day     only the latest stored day (1D view)
 *   ?mode=weekly   default. 200 weekly closes (canonical)
 *   ?mode=daily    straight 1,400-day rolling mean
 *
 * Returns dates, prices and the average, plus the stats the page displays.
 * The average is nullable for the leading stretch where 200 weeks of data do
 * not exist yet — the client is expected to break the line there, not plot 0.
 */

require dirname(__DIR__, 2) . '/src/App.php';

use Btc200Wma\App;
use Btc200Wma\Halvings;
use Btc200Wma\MovingAverage;
use Btc200Wma\PiCycle;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $since = isset($_GET['since']) ? (int) $_GET['since'] : 0;
    $mode = ($_GET['mode'] ?? 'weekly') === 'daily' ? 'daily' : 'weekly';
    $weeks = App::maWeeks();

    // Relative windows are measured back from the last stored day, not from
    // today: the source can lag, and anchoring to "now" would show a window
    // with a gap at the right-hand edge.
    $rangeDays = match (strtolower((string) ($_GET['range'] ?? ''))) {
        'day'   => 1,
        'week'  => 7,
        'month' => 30,
        'year'  => 365,
        default => 0,
    };

    $repo = App::prices();
    $daily = $repo->all();

    if ($daily === []) {
        http_response_code(409);
        echo json_encode([
            'error' => 'No price data stored yet. Run: php bin/import.php',
        ]);
        exit;
    }

    $series = (new MovingAverage($weeks))->apply($daily, $mode === 'daily');

    // Stats describe the whole series, not the zoomed window, so the headline
    // number does not change when the user zooms in.
    $withMa = array_values(array_filter($series, static fn ($r) => $r['ma'] !== null));

    if ($withMa === []) {
        http_response_code(409);
        echo json_encode(['error' => "Not enough history to compute a {$weeks}-week average."]);
        exit;
    }

    $last = end($withMa);
    $belowDays = 0;
    foreach ($withMa as $row) {
        if ($row['usd'] < $row['ma']) {
            $belowDays++;
        }
    }

    if ($rangeDays > 0) {
        $cutoff = (new DateTimeImmutable($last['date']))
            ->modify('-' . ($rangeDays - 1) . ' days')
            ->format('Y-m-d');

        $series = array_values(array_filter(
            $series,
            static fn ($r): bool => $r['date'] >= $cutoff
        ));
    } elseif ($since > 0) {
        $series = array_values(array_filter(
            $series,
            static fn ($r): bool => (int) substr($r['date'], 0, 4) >= $since
        ));
    }

    $fmt = static fn (float $v): float => round($v, 2);

    // Halvings are chain facts, not price data, so they are not windowed: the
    // client decides which ones fall inside the visible x-range.
    $meta = App::meta();
    $halvings = Halvings::all($meta->chainHeight(), $meta->chainHeightAt());

    // Pi Cycle is computed from the FULL series and then windowed like the
    // price, so a 1Y view shows the same averages it would in the full view.
    $piCycle  = new PiCycle();
    $piSeries = $piCycle->apply($daily);
    $piCrosses = $piCycle->topCrosses($piSeries);

    if ($rangeDays > 0 || $since > 0) {
        $piByDate = [];
        foreach ($piSeries as $row) {
            $piByDate[$row['date']] = $row;
        }

        $piSeries = [];
        foreach ($series as $row) {
            $piSeries[] = $piByDate[$row['date']] ?? ['date' => $row['date'], 'fast' => null, 'slow' => null];
        }
    }

    $piLast = null;
    for ($i = count($piSeries) - 1; $i >= 0; $i--) {
        if ($piSeries[$i]['fast'] !== null && $piSeries[$i]['slow'] !== null) {
            $piLast = $piSeries[$i];
            break;
        }
    }

    echo json_encode([
        'mode'       => $mode,
        'weeks'      => $weeks,
        'range'      => $rangeDays > 0 ? ($_GET['range'] ?? '') : 'all',
        'halvings'   => $halvings,
        'from'       => $withMa[0]['date'],
        'to'         => $last['date'],
        'labels'     => array_column($series, 'date'),
        'price'      => array_map(static fn ($r): float => $fmt($r['usd']), $series),
        'ma'         => array_map(
            static fn ($r) => $r['ma'] === null ? null : $fmt($r['ma']),
            $series
        ),
        'pi'         => [
            'fast_days' => PiCycle::FAST_DAYS,
            'slow_days' => PiCycle::SLOW_DAYS,
            'multiple'  => PiCycle::MULTIPLE,
            'fast'      => array_map(
                static fn ($r) => $r['fast'] === null ? null : $fmt($r['fast']),
                $piSeries
            ),
            'slow'      => array_map(
                static fn ($r) => $r['slow'] === null ? null : $fmt($r['slow']),
                $piSeries
            ),
            // Crossings are not windowed: the client decides which fall inside
            // the visible range, the same way it handles halvings.
            'crosses'   => $piCrosses,
            'fast_now'  => $piLast === null ? null : $fmt($piLast['fast']),
            'slow_now'  => $piLast === null ? null : $fmt($piLast['slow']),
            'gap_pct'   => ($piLast === null || $piLast['slow'] == 0.0)
                ? null
                : round(($piLast['fast'] / $piLast['slow'] - 1) * 100, 2),
        ],
        'stats'      => [
            'price'       => $fmt($last['usd']),
            'ma'          => $fmt($last['ma']),
            'ratio'       => round($last['usd'] / $last['ma'], 4),
            'below_pct'   => round($belowDays / count($withMa) * 100, 2),
            'points'      => count($withMa),
        ],
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
