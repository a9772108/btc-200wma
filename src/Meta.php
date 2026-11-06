<?php

declare(strict_types=1);

namespace Btc200Wma;

use PDO;

/**
 * Small key/value store for facts that are not price data: the observed chain
 * height and when it was read.
 */
final class Meta
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT meta_value FROM meta WHERE meta_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function set(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO meta (meta_key, meta_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)'
        );
        $stmt->execute([$key, $value]);
    }

    /** Chain height as an int, or null when it has never been read. */
    public function chainHeight(): ?int
    {
        $v = $this->get('chain_height');

        return ($v === null || !ctype_digit($v)) ? null : (int) $v;
    }

    /** When the stored chain height was read. */
    public function chainHeightAt(): ?\DateTimeImmutable
    {
        $v = $this->get('chain_height_at');
        if ($v === null || $v === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($v);
        } catch (\Exception) {
            return null;
        }
    }
}
