<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI\Media;

use App\Review\Application\RoundUploadTarget;
use App\Review\Application\RoundUploadTargets;
use App\Review\Domain\RoundId;

/**
 * Like DbalRoundUploadTargets: an id in any case, null for a malformed id or a round it has not, and a LogicException
 * for the round it is told cannot be read, as when no transaction is open. Records every id it is asked for.
 */
final class InMemoryRoundUploadTargets implements RoundUploadTargets
{
    /** @var list<string> */
    public array $askedRoundIds = [];

    /**
     * @param array<string, RoundUploadTarget> $roundsById by lowercase round id
     */
    public function __construct(private readonly array $roundsById, private readonly string $unreadableRoundId)
    {
    }

    public function of(string $roundId): ?RoundUploadTarget
    {
        $this->askedRoundIds[] = $roundId;
        try {
            $id = new RoundId($roundId);
        } catch (\InvalidArgumentException) {
            return null;
        }
        if ($id->toString() === $this->unreadableRoundId) {
            throw new \LogicException('A round is read as an upload target in a transaction under its organization as tenant; none is open.');
        }

        return $this->roundsById[$id->toString()] ?? null;
    }
}
