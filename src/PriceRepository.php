<?php

declare(strict_types=1);

namespace Btc200Wma;

use PDO;

/**
 * Reads and writes the daily price series.
 */
final class PriceRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Every stored day, oldest first.
     *
     * @return list<array{date:string, usd:float}>
     */
    public function all(): array
    {
        $sql = 'SELECT price_date, usd FROM prices ORDER BY price_date ASC';
        $rows = $this->pdo->query($sql)->fetchAll();

        return array_map(
            static fn (array $r): array => [
                'date' => (string) $r['price_date'],
                'usd'  => (float) $r['usd'],
            ],
            $rows
        );
    }

    /**
     * Insert or update a batch of days in one statement.
     *
     * @param list<array{date:string, usd:float}> $rows
     *
     * @return int number of rows written
     */
    public function upsertMany(array $rows, string $source): int
    {
        if ($rows === []) {
            return 0;
        }

        // Chunked so a full history import stays well under max_allowed_packet.
        $chunkSize = 500;
        $written = 0;

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?)'));
            $sql = "INSERT INTO prices (price_date, usd, source) VALUES {$placeholders}
                    ON DUPLICATE KEY UPDATE usd = VALUES(usd), source = VALUES(source)";

            $params = [];
            foreach ($chunk as $row) {
                $params[] = $row['date'];
                $params[] = $row['usd'];
                $params[] = $source;
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $written += $stmt->rowCount();
        }

        return $written;
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM prices')->fetchColumn();
    }

    /** @return array{first:?string, last:?string} */
    public function range(): array
    {
        $row = $this->pdo->query(
            'SELECT MIN(price_date) AS first, MAX(price_date) AS last FROM prices'
        )->fetch();

        return [
            'first' => $row['first'] !== null ? (string) $row['first'] : null,
            'last'  => $row['last'] !== null ? (string) $row['last'] : null,
        ];
    }

    /** @return array{low:?string, high:?string} */
    public function priceRange(): array
    {
        $row = $this->pdo->query(
            'SELECT MIN(usd) AS low, MAX(usd) AS high FROM prices'
        )->fetch();

        return [
            'low'  => $row['low'] !== null ? (string) $row['low'] : null,
            'high' => $row['high'] !== null ? (string) $row['high'] : null,
        ];
    }

    public function startImportRun(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO import_runs (started_at, status) VALUES (NOW(), ?)'
        );
        $stmt->execute(['running']);

        return (int) $this->pdo->lastInsertId();
    }

    public function finishImportRun(int $id, int $seen, int $stored, string $status, string $message): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE import_runs
                SET finished_at = NOW(), rows_seen = ?, rows_stored = ?, status = ?, message = ?
              WHERE id = ?'
        );
        $stmt->execute([$seen, $stored, $status, $message, $id]);
    }

    /** @return list<array<string,mixed>> */
    public function recentImports(int $limit = 5): array
    {
        $limit = max(1, min($limit, 50));
        $sql = "SELECT * FROM import_runs ORDER BY id DESC LIMIT {$limit}";

        return $this->pdo->query($sql)->fetchAll();
    }
}
