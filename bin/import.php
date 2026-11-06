<?php

declare(strict_types=1);

/**
 * CLI importer.
 *
 *   php bin/import.php
 *
 * Safe to run on a schedule — rows are upserted by date, so re-running only
 * refreshes recent values and fills any new days.
 */

require dirname(__DIR__) . '/src/App.php';

use Btc200Wma\App;
use Btc200Wma\Importer;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI only.\n");
}

try {
    $importer = new Importer(App::prices(), App::config()['source']);
    $result = $importer->run();

    printf(
        "imported: %d rows seen, %d written\nrange   : %s to %s\n",
        $result['seen'],
        $result['stored'],
        $result['first'] ?? '?',
        $result['last'] ?? '?'
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'import failed: ' . $e->getMessage() . "\n");
    exit(1);
}
