<?php

declare(strict_types=1);

namespace App\Tests\Integration\Conversation;

use Doctrine\DBAL\Connection;

/**
 * A real connection that keeps the last fetchAllAssociative query, how many it ran and every row the database returned
 * to them, so a test can prove what left the database, not only what a read model passed on.
 */
final class RecordingConnection extends Connection
{
    /** @var list<array<string, mixed>> */
    public array $returnedRows = [];
    public ?string $lastSql = null;
    /** @var array<int<0, max>|string, mixed> */
    public array $lastParams = [];
    public int $fetchAllCount = 0;

    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        $rows = parent::fetchAllAssociative($query, $params, $types);
        ++$this->fetchAllCount;
        $this->lastSql = $query;
        $this->lastParams = $params;
        $this->returnedRows = [...$this->returnedRows, ...$rows];

        return $rows;
    }
}
