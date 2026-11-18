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
use Btc200Wma\CyclePeaks;
use Btc200Wma\Halvings;
use Btc200Wma\MovingAverage;
use Btc200Wma\MovingAverages;
use Btc200Wma\PeakJourney;
use Btc200Wma\PiCycle;
use Btc200Wma\Projection;

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

    // Keep the unwindowed series: the peak analysis needs each peak's average on
    // its own day, which a 1W window would not contain.
    $seriesFull = $series;

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

    // Pi Cycle and the 50/200-day averages are computed from the FULL series
    // and then windowed like the price, so a 1Y view shows the same averages it
    // would in the full view. Deriving them from the already-windowed prices
    // would need 200 days the window does not contain and leave the first
    // stretch null.
    $windowByDate = static function (array $full, array $windowed): array {
        $byDate = [];
        foreach ($full as $row) {
            $byDate[$row['date']] = $row;
        }

        $out = [];
        foreach ($windowed as $row) {
            $out[] = $byDate[$row['date']] ?? ['date' => $row['date'], 'fast' => null, 'slow' => null];
        }

        return $out;
    };

    $lastComputed = static function (array $series): ?array {
        for ($i = count($series) - 1; $i >= 0; $i--) {
            if ($series[$i]['fast'] !== null && $series[$i]['slow'] !== null) {
                return $series[$i];
            }
        }

        return null;
    };

    $piCycle   = new PiCycle();
    $piFull    = $piCycle->apply($daily);
    $piCrosses = $piCycle->topCrosses($piFull);
    $piSeries  = $windowByDate($piFull, $series);
    $piLast    = $lastComputed($piSeries);

    $dma         = new MovingAverages();
    $dmaFull     = $dma->apply($daily);
    $dmaCrosses  = $dma->crosses($dmaFull);
    $dmaSeries   = $windowByDate($dmaFull, $series);
    $dmaLast     = $lastComputed($dmaSeries);

    // Cycle peaks are derived from the full series and, like the other markers,
    // are not windowed: the client decides which fall inside the visible range.
    $peaks = (new CyclePeaks())->detect($daily);

    // Annotate each peak with its multiple over the 200-week average and the
    // length of the run above it. Peaks older than the average itself keep
    // nulls; the client shows those as unavailable rather than zero.
    $peakRows = (new PeakJourney())->annotate($peaks, $seriesFull);

    // A mechanical projection of the average, not a price forecast. The client
    // draws it beyond the last real day; the assumption travels with it so it
    // cannot be presented as more than arithmetic.
    $projection = (new Projection($weeks))->project($daily, 730, $mode === 'daily');

    // The most recent cross decides the current regime. Both lists are oldest
    // first, so the later of the two last entries wins.
    $dmaState      = null;
    $dmaStateSince = null;
    $lastDeath     = $dmaCrosses['death'] === [] ? null : end($dmaCrosses['death']);
    $lastGolden    = $dmaCrosses['golden'] === [] ? null : end($dmaCrosses['golden']);

    if ($lastDeath !== null || $lastGolden !== null) {
        if ($lastDeath !== null && ($lastGolden === null || $lastDeath > $lastGolden)) {
            $dmaState = 'death';
            $dmaStateSince = $lastDeath;
        } else {
            $dmaState = 'golden';
            $dmaStateSince = $lastGolden;
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
        'dma'        => [
            'fast_days' => MovingAverages::FAST_DAYS,
            'slow_days' => MovingAverages::SLOW_DAYS,
            'fast'      => array_map(
                static fn ($r) => $r['fast'] === null ? null : $fmt($r['fast']),
                $dmaSeries
            ),
            'slow'      => array_map(
                static fn ($r) => $r['slow'] === null ? null : $fmt($r['slow']),
                $dmaSeries
            ),
            // Crossings are not windowed, for the same reason as halvings: the
            // client decides which markers fall inside the visible range.
            'crosses'   => $dmaCrosses,
            'fast_now'  => $dmaLast === null ? null : $fmt($dmaLast['fast']),
            'slow_now'  => $dmaLast === null ? null : $fmt($dmaLast['slow']),
            'state'     => $dmaState,          // 'death' | 'golden' | null
            'state_since' => $dmaStateSince,
            // Where the latest close sits relative to each line, as a percent.
            // Negative means below. This is the "price below the 50/200" part,
            // which is a standing state rather than an event.
            'price_vs_fast_pct' => ($dmaLast === null || $dmaLast['fast'] == 0.0)
                ? null
                : round(($last['usd'] / $dmaLast['fast'] - 1) * 100, 2),
            'price_vs_slow_pct' => ($dmaLast === null || $dmaLast['slow'] == 0.0)
                ? null
                : round(($last['usd'] / $dmaLast['slow'] - 1) * 100, 2),
        ],
        'peaks'      => [
            'window_days' => CyclePeaks::WINDOW_DAYS,
            // Not windowed: the client decides which markers fall in view.
            'items'       => array_map(
                static fn (array $p): array => [
                    'date'      => $p['date'],
                    'usd'       => $fmt($p['usd']),
                    'confirmed' => $p['confirmed'],
                ],
                $peaks
            ),
            // How far each peak ran above the 200-week average, and how long it
            // spent continuously above it beforehand. Peaks older than the
            // average keep nulls rather than a fabricated ratio.
            'vs_average'  => array_map(
                static fn (array $p): array => [
                    'date'          => $p['date'],
                    'usd'           => $fmt($p['usd']),
                    'confirmed'     => $p['confirmed'],
                    'ma'            => $p['ma'] === null ? null : $fmt($p['ma']),
                    'multiple'      => $p['multiple'] === null ? null : round($p['multiple'], 2),
                    'days_above'    => $p['days_above'],
                    'crossed_above' => $p['crossed_above'],
                    // The bear phase: days price closed under the average between
                    // the previous top and this one.
                    'cycle_days'    => $p['cycle_days'],
                    'cycle_days_below' => $p['cycle_days_below'],
                ],
                $peakRows
            ),
        ],
        'projection' => [
            'assumption'    => $projection['assumption'],
            'assumed_price' => $fmt($projection['assumed_price']),
            'from'          => $projection['from'],
            'window_days'   => $projection['window_days'],
            'labels'        => array_column($projection['points'], 'date'),
            'ma'            => array_map(
                static fn (array $p): float => $fmt($p['ma']),
                $projection['points']
            ),
            'ratio'         => array_map(
                static fn (array $p): float => round($p['ratio'], 4),
                $projection['points']
            ),
        ],
        'stats'      => [            'price'       => $fmt($last['usd']),
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
