<?php

declare(strict_types=1);

namespace App\Review\Api;

/**
 * Where the next page of a person's notebooks starts: after the last notebook shown, by the list's order (last used
 * first, then id highest first). It is not signed: it holds only what the owner already saw, and a forged one only
 * moves where the page starts, as the list still reads only the owner's own notebooks.
 */
final readonly class NotebookCursor
{
    private const string CANONICAL_UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    /**
     * @param \DateTimeImmutable $lastUsedAt the last shown notebook's lastUsedAt, to the microsecond as stored
     * @param string             $notebookId the last shown notebook's id
     *
     * @throws \InvalidArgumentException when the notebook id is not a canonical lowercase UUIDv7
     */
    public function __construct(public \DateTimeImmutable $lastUsedAt, public string $notebookId)
    {
        if (1 !== preg_match(self::CANONICAL_UUID_V7, $notebookId)) {
            throw new \InvalidArgumentException('A notebook cursor\'s notebook id must be a canonical lowercase UUIDv7.');
        }
    }
}
