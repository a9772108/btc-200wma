<?php

declare(strict_types=1);

/**
 * Copy this file to config.php and fill in real credentials.
 * config.php is git-ignored so secrets never reach the repository.
 */
return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'btc_200wma',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],

    // 200 weeks = 1,400 days. Changing this changes the whole analysis.
    'ma_weeks' => 200,

    'source' => [
        // Public chart API, no key required.
        'url'     => 'https://api.blockchain.info/charts/market-price?timespan=all&format=json&sampled=false',
        'timeout' => 90,
        'agent'   => 'btc-200wma/1.0 (+https://github.com/)',

        // Current block height, used only to project the next halving.
        'height_url' => 'https://blockchain.info/q/getblockcount',
    ],
];
