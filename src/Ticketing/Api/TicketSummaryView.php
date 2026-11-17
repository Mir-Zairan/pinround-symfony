<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\UserId;

/**
 * One summary of a ticket as a page shows it, current or earlier.
 */
final readonly class TicketSummaryView
{
    /**
     * @param string  $source        what made it: 'sync', 'ai', 'explanation' or 'human'
     * @param ?UserId $authorId      who wrote or asked for it; null for a summary made on sync
     * @param ?string $model         the model that wrote it; null when no AI did
     * @param ?string $promptVersion the prompt template that made it, such as "summary.v1"; null when no AI did
     */
    public function __construct(
        public string $id,
        public string $text,
        public string $source,
        public ?UserId $authorId,
        public ?string $model,
        public ?string $promptVersion,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
