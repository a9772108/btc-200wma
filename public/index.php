<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/App.php';

use Btc200Wma\App;

/**
 * URL path this app is served under, so links work both at a domain root and
 * in a subdirectory such as /btc-200wma/.
 */
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
if ($base === '.') {
    $base = '';
}
$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$error = null;
$weeks = 200;
$lastImport = null;
$rowCount = 0;

try {
    $weeks = App::maWeeks();
    $repo = App::prices();
    $rowCount = $repo->count();
    $lastImport = $repo->recentImports(1)[0] ?? null;
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bitcoin vs its <?= (int) $weeks ?>-week moving average</title>
    <link rel="stylesheet" href="<?= $e($base) ?>/css/style.css">
    <script>window.BTC_BASE = <?= json_encode($base) ?>;</script>
</head>
<body>
<main>
    <header class="head">
        <h1>Bitcoin vs its <?= (int) $weeks ?>-week moving average</h1>
        <p class="sub" id="sub">Loading price history…</p>
    </header>

    <?php if ($error !== null): ?>
        <div class="notice error">
            <strong>Setup incomplete.</strong>
            <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
            <pre>mysql -u root &lt; database/schema.sql
php bin/import.php</pre>
        </div>
    <?php elseif ($rowCount === 0): ?>
        <div class="notice warn">
            <strong>No price data yet.</strong>
            <p>Import the history, then reload this page:</p>
            <pre>php bin/import.php</pre>
        </div>
    <?php endif; ?>

    <section class="controls" aria-label="Chart controls">
        <div class="seg" role="group" aria-label="Average definition">
            <button type="button" class="seg-btn is-active" data-mode="weekly">200 weekly closes</button>
            <button type="button" class="seg-btn" data-mode="daily">1,400-day mean</button>
        </div>
        <div class="seg" role="group" aria-label="Time range">
            <button type="button" class="seg-btn is-active" data-range="all">All</button>
            <button type="button" class="seg-btn" data-range="year">1Y</button>
            <button type="button" class="seg-btn" data-range="month">1M</button>
            <button type="button" class="seg-btn" data-range="week">1W</button>
            <button type="button" class="seg-btn" data-since="2020">2020+</button>
            <button type="button" class="seg-btn" data-since="2022">2022+</button>
        </div>
        <label class="toggle">
            <input type="checkbox" id="log" checked>
            <span>Log scale</span>
        </label>
        <label class="toggle">
            <input type="checkbox" id="halvings" checked>
            <span>Halvings</span>
        </label>
    </section>

    <p class="status" id="status"></p>

    <figure class="chart-wrap">
        <canvas id="chart" aria-label="Bitcoin price against its <?= (int) $weeks ?>-week moving average" role="img"></canvas>
    </figure>

    <dl class="stats" id="stats"></dl>

    <footer class="foot">
        <p>
            Source: blockchain.info daily market price, stored in MySQL.
            Average is the mean of the last <?= (int) $weeks ?> weekly closes
            (<?= (int) $weeks * 7 ?> days); the alternate mode is a straight
            <?= (int) $weeks * 7 ?>-day rolling mean.
            <span id="freshness"></span>
        </p>
        <p class="muted">
            Prices are daily averages, not exchange closes, and the source can lag
            spot by a day or two. The average only exists once <?= (int) $weeks ?>
            weeks of history do.
        </p>
    </footer>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="<?= $e($base) ?>/js/app.js"></script>
</body>
</html>
