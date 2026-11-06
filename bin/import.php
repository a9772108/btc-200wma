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
use Btc200Wma\ChainState;
use Btc200Wma\Halvings;
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

    // The next halving is projected from the current height, so refresh it
    // alongside the prices. A failure here must not fail the import.
    $chain = new ChainState(App::meta(), App::config()['source']);
    $state = $chain->refresh();

    if ($state === null) {
        fwrite(STDERR, "chain height: unavailable, next halving will not be projected\n");
        exit(0);
    }

    $next = Halvings::projectNext(
        $state['height'],
        new DateTimeImmutable($state['at'])
    );

    printf(
        "chain   : height %d at %s UTC\nnext    : block %d, projected %s%s\n",
        $state['height'],
        $state['at'],
        Halvings::nextBlock($state['height']),
        $next ?? 'unknown',
        $next === null ? '' : ' (estimate)'
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'import failed: ' . $e->getMessage() . "\n");
    exit(1);
}
