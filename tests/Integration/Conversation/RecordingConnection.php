<?php

declare(strict_types=1);

namespace App\Tests\Integration\Conversation;

use Doctrine\DBAL\Connection;

/**
 * A real connection that keeps every fetchAllAssociative query in the order it ran, with its parameters, and every row
 * the database returned to them, so a test can prove what left the database, not only what a read model passed on.
 */
final class RecordingConnection extends Connection
{
    /** @var list<array<string, mixed>> */
    public array $returnedRows = [];
    /** @var list<array{sql: string, params: array<int<0, max>|string, mixed>}> */
    public array $sentQueries = [];

    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        $rows = parent::fetchAllAssociative($query, $params, $types);
        $this->sentQueries[] = ['sql' => $query, 'params' => $params];
        $this->returnedRows = [...$this->returnedRows, ...$rows];

        return $rows;
    }
}
