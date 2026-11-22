<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Doctrine;

use App\Review\Api\DraftScreenshotVerdictQueries;
use App\Review\Api\DraftScreenshotVerdictView;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;

/**
 * DraftScreenshotVerdictQueries on rvw_screenshot_verdict through DBAL, by its primary key, with organization_id named
 * as well as row-level security.
 */
final readonly class DbalDraftScreenshotVerdictQueries implements DraftScreenshotVerdictQueries
{
    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function verdictOf(string $roundId, string $screenshotId): ?DraftScreenshotVerdictView
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A screenshot verdict is read in a transaction under the organization as tenant; none is open.');
        }
        $organizationId = $this->tenantContext->require()->toString();
        $row = $this->connection->fetchAssociative(
            'SELECT verdict, note, decided_by FROM rvw_screenshot_verdict WHERE organization_id = ? AND screenshot_id = ? AND round_id = ?',
            [$organizationId, $screenshotId, $roundId],
        );
        if (false === $row) {
            return null;
        }
        $note = $row['note'];

        return new DraftScreenshotVerdictView(
            self::text($row['verdict'], 'verdict'),
            null === $note ? null : self::text($note, 'note'),
            new UserId(self::text($row['decided_by'], 'decided_by')),
        );
    }

    private static function text(mixed $value, string $column): string
    {
        return \is_string($value) ? $value : throw new \LogicException(\sprintf('A screenshot verdict %s from the database is not a string.', $column));
    }
}
