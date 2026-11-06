<?php

declare(strict_types=1);

/**
 * JSON API: GET /api/series.php
 *
 *   ?since=YYYY    only return days from that year onward
 *   ?mode=weekly   default. 200 weekly closes (canonical)
 *   ?mode=daily    straight 1,400-day rolling mean
 *
 * Returns dates, prices and the average, plus the stats the page displays.
 * The average is nullable for the leading stretch where 200 weeks of data do
 * not exist yet — the client is expected to break the line there, not plot 0.
 */

require dirname(__DIR__, 2) . '/src/App.php';

use Btc200Wma\App;
use Btc200Wma\MovingAverage;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $since = isset($_GET['since']) ? (int) $_GET['since'] : 0;
    $mode = ($_GET['mode'] ?? 'weekly') === 'daily' ? 'daily' : 'weekly';
    $weeks = App::maWeeks();

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

    if ($since > 0) {
        $series = array_values(array_filter(
            $series,
            static fn ($r): bool => (int) substr($r['date'], 0, 4) >= $since
        ));
    }

    $fmt = static fn (float $v): float => round($v, 2);

    echo json_encode([
        'mode'       => $mode,
        'weeks'      => $weeks,
        'from'       => $withMa[0]['date'],
        'to'         => $last['date'],
        'labels'     => array_column($series, 'date'),
        'price'      => array_map(static fn ($r): float => $fmt($r['usd']), $series),
        'ma'         => array_map(
            static fn ($r) => $r['ma'] === null ? null : $fmt($r['ma']),
            $series
        ),
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
