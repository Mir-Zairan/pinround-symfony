<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Doctrine;

use App\Review\Api\PlacedPin;
use App\Review\Api\ReportDraftContent;
use App\Review\Api\ReportDraftCursor;
use App\Review\Api\ReportDraftsPage;
use App\Review\Api\ReportDraftSummary;
use App\Review\Api\ReportDraftView;
use App\Review\Api\ScreenshotWithPins;
use App\Review\Application\ReportDraft\ReportDraftRows;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * ReportDraftRows on rvw_report_draft, under row-level security and with the organization in every WHERE, so the
 * primary key and the owner index serve each query. The screenshots column is a jsonb array of
 * {media_id, title, summary, pins: [{x_pct, y_pct, note}]}.
 */
final readonly class DbalReportDraftRows implements ReportDraftRows
{
    private const string COLUMNS = 'id, owner_id, project_key, title, detail, screenshots, version, created_at, updated_at';

    // A canonical uuid; anything else finds no draft without asking PostgreSQL, which would refuse the cast. \z, as $
    // would also accept a trailing newline.
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function nextId(): string
    {
        return Uuid::v7()->toRfc4122();
    }

    public function insert(OrganizationId $organizationId, string $id, UserId $owner, string $projectKey, ReportDraftContent $content, \DateTimeImmutable $at): void
    {
        if (!$this->requireTenant()->equals($organizationId)) {
            throw new \LogicException('A report draft is written under its organization as tenant.');
        }
        $this->connection->executeStatement(
            'INSERT INTO rvw_report_draft (organization_id, id, owner_id, project_key, title, detail, screenshots, version, created_at, updated_at)'
            .' VALUES (:organization, :id, :owner, :project_key, :title, :detail, CAST(:screenshots AS jsonb), 1, :at, :at)',
            [
                'organization' => $organizationId->toString(),
                'id' => $id,
                'owner' => $owner->toString(),
                'project_key' => $projectKey,
                'title' => $content->title,
                'detail' => $content->detail,
                'screenshots' => self::screenshotsJsonOf($content->screenshots),
                'at' => $at->format(\DATE_ATOM),
            ],
        );
    }

    public function forOwner(UserId $owner, ?ReportDraftCursor $after, int $pageSize): ReportDraftsPage
    {
        $parameters = ['organization' => $this->requireTenant()->toString(), 'owner' => $owner->toString(), 'limit' => $pageSize + 1];
        $afterCondition = '';
        if (null !== $after) {
            // The first comparison bounds the owner index's updated_at range; the second breaks ties on the id.
            $afterCondition = ' AND updated_at <= :after_at AND (updated_at < :after_at OR id < :after_id)';
            $parameters['after_at'] = $after->updatedAt->format('Y-m-d H:i:s.uP');
            $parameters['after_id'] = $after->draftId;
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, project_key, title, jsonb_array_length(screenshots) AS screenshot_count, updated_at FROM rvw_report_draft'
            .' WHERE organization_id = :organization AND owner_id = :owner'.$afterCondition.' ORDER BY updated_at DESC, id DESC LIMIT :limit',
            $parameters,
            ['limit' => ParameterType::INTEGER],
        );
        $drafts = array_map(static fn (array $row): ReportDraftSummary => new ReportDraftSummary(
            self::stringOf($row, 'id'),
            self::stringOf($row, 'project_key'),
            self::stringOf($row, 'title'),
            (int) self::stringOrIntOf($row, 'screenshot_count'),
            new \DateTimeImmutable(self::stringOf($row, 'updated_at')),
        ), \array_slice($rows, 0, $pageSize));
        $last = $drafts[\count($drafts) - 1] ?? null;
        $nextCursor = \count($rows) > $pageSize && null !== $last ? new ReportDraftCursor($last->updatedAt, $last->id) : null;

        return new ReportDraftsPage($drafts, $nextCursor);
    }

    public function ofOwner(string $id, UserId $owner, bool $lock = false): ?ReportDraftView
    {
        $organizationId = $this->requireTenant();
        $lowercaseId = strtolower($id);
        if (1 !== preg_match(self::UUID, $lowercaseId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM rvw_report_draft WHERE organization_id = :organization AND id = :id AND owner_id = :owner'.($lock ? ' FOR UPDATE' : ''),
            ['organization' => $organizationId->toString(), 'id' => $lowercaseId, 'owner' => $owner->toString()],
        );

        return false === $row ? null : self::viewOf($row);
    }

    public function update(string $id, UserId $owner, int $expectedVersion, ReportDraftContent $content, \DateTimeImmutable $at): bool
    {
        $organizationId = $this->requireTenant();
        $lowercaseId = strtolower($id);
        if (1 !== preg_match(self::UUID, $lowercaseId)) {
            return false;
        }

        return 1 === $this->connection->executeStatement(
            'UPDATE rvw_report_draft SET title = :title, detail = :detail, screenshots = CAST(:screenshots AS jsonb), version = version + 1,'
            .' updated_at = GREATEST(created_at, :at) WHERE organization_id = :organization AND id = :id AND owner_id = :owner AND version = :version',
            [
                'title' => $content->title,
                'detail' => $content->detail,
                'screenshots' => self::screenshotsJsonOf($content->screenshots),
                'at' => $at->format(\DATE_ATOM),
                'organization' => $organizationId->toString(),
                'id' => $lowercaseId,
                'owner' => $owner->toString(),
                'version' => $expectedVersion,
            ],
        );
    }

    public function delete(string $id, UserId $owner, ?int $expectedVersion = null): bool
    {
        $organizationId = $this->requireTenant();
        $lowercaseId = strtolower($id);
        if (1 !== preg_match(self::UUID, $lowercaseId)) {
            return false;
        }
        $parameters = ['organization' => $organizationId->toString(), 'id' => $lowercaseId, 'owner' => $owner->toString()];
        $versionCondition = '';
        if (null !== $expectedVersion) {
            $versionCondition = ' AND version = :version';
            $parameters['version'] = $expectedVersion;
        }

        return 1 === $this->connection->executeStatement(
            'DELETE FROM rvw_report_draft WHERE organization_id = :organization AND id = :id AND owner_id = :owner'.$versionCondition,
            $parameters,
        );
    }

    /**
     * @param list<ScreenshotWithPins> $screenshots
     */
    private static function screenshotsJsonOf(array $screenshots): string
    {
        return json_encode(array_map(static fn (ScreenshotWithPins $screenshot): array => [
            'media_id' => $screenshot->mediaId,
            'title' => $screenshot->title,
            'summary' => $screenshot->summary,
            'pins' => array_map(static fn (PlacedPin $pin): array => ['x_pct' => $pin->xPercent, 'y_pct' => $pin->yPercent, 'note' => $pin->note], $screenshot->pins),
        ], $screenshots), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws \LogicException when a stored value has another shape than this class writes
     */
    private static function viewOf(array $row): ReportDraftView
    {
        $screenshots = json_decode(self::stringOf($row, 'screenshots'), true, 8, \JSON_THROW_ON_ERROR);
        if (!\is_array($screenshots) || !array_is_list($screenshots)) {
            throw new \LogicException('A stored report draft has screenshots that are not a list.');
        }

        return new ReportDraftView(
            self::stringOf($row, 'id'),
            new UserId(self::stringOf($row, 'owner_id')),
            self::stringOf($row, 'project_key'),
            self::stringOf($row, 'title'),
            self::stringOf($row, 'detail'),
            array_map(self::screenshotOf(...), $screenshots),
            (int) self::stringOrIntOf($row, 'version'),
            new \DateTimeImmutable(self::stringOf($row, 'created_at')),
            new \DateTimeImmutable(self::stringOf($row, 'updated_at')),
        );
    }

    private static function screenshotOf(mixed $stored): ScreenshotWithPins
    {
        if (!\is_array($stored) || !\is_string($stored['media_id'] ?? null) || !\is_array($stored['pins'] ?? null)) {
            throw new \LogicException('A stored report draft screenshot has another shape than DbalReportDraftRows writes.');
        }
        $title = $stored['title'] ?? null;
        $summary = $stored['summary'] ?? null;
        if ((null !== $title && !\is_string($title)) || (null !== $summary && !\is_string($summary))) {
            throw new \LogicException('A stored report draft screenshot has a title or summary that is not text.');
        }

        return new ScreenshotWithPins(
            $stored['media_id'],
            $title,
            $summary,
            array_values(array_map(static function (mixed $pin): PlacedPin {
                $xPercent = \is_array($pin) ? ($pin['x_pct'] ?? null) : null;
                $yPercent = \is_array($pin) ? ($pin['y_pct'] ?? null) : null;
                $note = \is_array($pin) ? ($pin['note'] ?? null) : null;
                if (!(\is_int($xPercent) || \is_float($xPercent)) || !(\is_int($yPercent) || \is_float($yPercent)) || !\is_string($note)) {
                    throw new \LogicException('A stored report draft pin is not {x_pct, y_pct, note}.');
                }

                return new PlacedPin((float) $xPercent, (float) $yPercent, $note);
            }, $stored['pins'])),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function stringOf(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (!\is_string($value)) {
            throw new \LogicException(\sprintf('rvw_report_draft.%s is not text.', $column));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function stringOrIntOf(array $row, string $column): int|string
    {
        $value = $row[$column] ?? null;
        if (!\is_int($value) && !\is_string($value)) {
            throw new \LogicException(\sprintf('rvw_report_draft.%s is not a number.', $column));
        }

        return $value;
    }

    /**
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    private function requireTenant(): OrganizationId
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Report drafts are read and written in a transaction under the organization as tenant; none is open.');
        }

        return $this->tenantContext->require();
    }
}
