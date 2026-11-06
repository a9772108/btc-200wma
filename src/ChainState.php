<?php

declare(strict_types=1);

namespace Btc200Wma;

use DateTimeImmutable;
use RuntimeException;

/**
 * Reads the current block height and records it, so the next-halving projection
 * has a real observation behind it.
 */
final class ChainState
{
    public function __construct(
        private readonly Meta $meta,
        private readonly array $source,
    ) {
    }

    /**
     * Fetch the height and store it with the time it was read.
     *
     * @return array{height:int, at:string}|null null when the lookup fails
     */
    public function refresh(): ?array
    {
        $url = (string) ($this->source['height_url'] ?? '');
        if ($url === '') {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => 'User-Agent: ' . ($this->source['agent'] ?? 'btc-200wma/1.0'),
                'timeout'       => (int) ($this->source['timeout'] ?? 30),
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }

        $height = trim($body);
        if (!ctype_digit($height)) {
            return null;
        }

        // Record in UTC so the projection is timezone-free.
        $at = (new DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $this->meta->set('chain_height', $height);
        $this->meta->set('chain_height_at', $at);

        return ['height' => (int) $height, 'at' => $at];
    }
}
