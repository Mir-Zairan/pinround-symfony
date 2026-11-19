<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Doctrine;

use App\Review\Domain\Notebook\Notebook;
use App\Review\Domain\Notebook\NotebookCursor;
use App\Review\Domain\Notebook\NotebookId;
use App\Review\Domain\Notebook\NotebookPin;
use App\Review\Domain\Notebook\NotebookRefused;
use App\Review\Domain\Notebook\NotebookRows;
use App\Review\Domain\Notebook\NotebookScreenshot;
use App\Review\Domain\Notebook\NotebooksPage;
use App\Review\Domain\Notebook\NotebookSummary;
use App\Review\Domain\Scenario\ScenarioRefused;
use App\Review\Domain\Scenario\ScenarioRules;
use App\Review\Domain\Scenario\Scenarios;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * NotebookRows on rvw_notebook, under row-level security and with the organization and the owner in every WHERE, so the
 * primary key and the owner index serve each query. The screenshots column is a jsonb array of
 * {media_id, title, summary, pins: [{x_pct, y_pct, note}]}, as rvw_report_draft keeps it; the scenarios column a jsonb
 * array of the scenarios in scenario.v1.json's format, read back through ScenarioRules.
 */
final readonly class DbalNotebooks implements NotebookRows
{
    private const string COLUMNS = 'id, organization_id, owner_id, title, screenshots, scenarios, version, created_at, updated_at, last_used_at';

    // Microseconds and the offset, so a time reads back exactly as it was written.
    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s.uP';

    // json_decode counts the value inside the deepest object as a level too. Screenshots: the list, a picture, its
    // pins, a pin and its values, the deepest this class writes.
    private const int SCREENSHOTS_MAX_DEPTH = 5;

    // The list, a scenario, its steps, a step, its target and its values: the deepest ScenarioRules accepts.
    private const int SCENARIOS_MAX_DEPTH = 6;

    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function nextId(): NotebookId
    {
        return new NotebookId(Uuid::v7()->toRfc4122());
    }

    public function insert(Notebook $notebook): void
    {
        if (!$this->requireTenant()->equals($notebook->organizationId())) {
            throw new \LogicException('A notebook is written under its organization as tenant.');
        }
        $this->connection->executeStatement(
            'INSERT INTO rvw_notebook (organization_id, id, owner_id, title, screenshots, scenarios, version, created_at, updated_at, last_used_at)'
            .' VALUES (:organization, :id, :owner, :title, CAST(:screenshots AS jsonb), CAST(:scenarios AS jsonb), :version, :created_at, :updated_at, :last_used_at)',
            [
                'organization' => $notebook->organizationId()->toString(),
                'id' => $notebook->id()->toString(),
                'owner' => $notebook->ownerId()->toString(),
                'title' => $notebook->title(),
                'screenshots' => self::screenshotsJsonOf($notebook->screenshots()),
                'scenarios' => self::scenariosJsonOf($notebook->scenarios()),
                'version' => $notebook->version(),
                'created_at' => $notebook->createdAt()->format(self::TIMESTAMP_FORMAT),
                'updated_at' => $notebook->updatedAt()->format(self::TIMESTAMP_FORMAT),
                'last_used_at' => $notebook->lastUsedAt()->format(self::TIMESTAMP_FORMAT),
            ],
            ['version' => ParameterType::INTEGER],
        );
    }

    public function ofOwner(NotebookId $id, UserId $owner): ?Notebook
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM rvw_notebook WHERE organization_id = :organization AND id = :id AND owner_id = :owner',
            ['organization' => $this->requireTenant()->toString(), 'id' => $id->toString(), 'owner' => $owner->toString()],
        );

        return false === $row ? null : self::notebookOf($row);
    }

    public function forOwner(UserId $owner, ?NotebookCursor $after, int $pageSize): NotebooksPage
    {
        if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new \InvalidArgumentException(\sprintf('A page holds 1 to %d notebooks.', self::MAX_PAGE_SIZE));
        }
        $parameters = ['organization' => $this->requireTenant()->toString(), 'owner' => $owner->toString(), 'limit' => $pageSize + 1];
        $afterCondition = '';
        if (null !== $after) {
            // The first comparison bounds the owner index's last_used_at range; the second breaks ties on the id.
            $afterCondition = ' AND last_used_at <= :after_at AND (last_used_at < :after_at OR id < :after_id)';
            $parameters['after_at'] = $after->lastUsedAt->format(self::TIMESTAMP_FORMAT);
            $parameters['after_id'] = $after->notebookId->toString();
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, title, jsonb_array_length(screenshots) AS screenshot_count, jsonb_array_length(scenarios) AS scenario_count, last_used_at'
            .' FROM rvw_notebook WHERE organization_id = :organization AND owner_id = :owner'.$afterCondition
            .' ORDER BY last_used_at DESC, id DESC LIMIT :limit',
            $parameters,
            ['limit' => ParameterType::INTEGER],
        );
        $notebooks = array_map(static fn (array $row): NotebookSummary => new NotebookSummary(
            new NotebookId(self::stringOf($row, 'id')),
            self::stringOf($row, 'title'),
            self::intOf($row, 'screenshot_count'),
            self::intOf($row, 'scenario_count'),
            new \DateTimeImmutable(self::stringOf($row, 'last_used_at')),
        ), \array_slice($rows, 0, $pageSize));
        $last = $notebooks[\count($notebooks) - 1] ?? null;
        $nextCursor = \count($rows) > $pageSize && null !== $last ? new NotebookCursor($last->lastUsedAt, $last->id) : null;

        return new NotebooksPage($notebooks, $nextCursor);
    }

    public function save(Notebook $notebook): bool
    {
        return 1 === $this->connection->executeStatement(
            'UPDATE rvw_notebook SET title = :title, screenshots = CAST(:screenshots AS jsonb), scenarios = CAST(:scenarios AS jsonb),'
            .' version = version + 1, updated_at = :updated_at, last_used_at = :last_used_at'
            .' WHERE organization_id = :organization AND id = :id AND owner_id = :owner AND version = :version',
            [
                'title' => $notebook->title(),
                'screenshots' => self::screenshotsJsonOf($notebook->screenshots()),
                'scenarios' => self::scenariosJsonOf($notebook->scenarios()),
                'updated_at' => $notebook->updatedAt()->format(self::TIMESTAMP_FORMAT),
                'last_used_at' => $notebook->lastUsedAt()->format(self::TIMESTAMP_FORMAT),
                'organization' => $this->requireTenant()->toString(),
                'id' => $notebook->id()->toString(),
                'owner' => $notebook->ownerId()->toString(),
                'version' => $notebook->version(),
            ],
            ['version' => ParameterType::INTEGER],
        );
    }

    public function markUsed(NotebookId $id, UserId $owner, \DateTimeImmutable $at): bool
    {
        return 1 === $this->connection->executeStatement(
            'UPDATE rvw_notebook SET last_used_at = GREATEST(created_at, CAST(:at AS timestamptz))'
            .' WHERE organization_id = :organization AND id = :id AND owner_id = :owner',
            [
                'at' => $at->format(self::TIMESTAMP_FORMAT),
                'organization' => $this->requireTenant()->toString(),
                'id' => $id->toString(),
                'owner' => $owner->toString(),
            ],
        );
    }

    public function delete(NotebookId $id, UserId $owner, ?int $expectedVersion = null): bool
    {
        $parameters = ['organization' => $this->requireTenant()->toString(), 'id' => $id->toString(), 'owner' => $owner->toString()];
        $types = [];
        $versionCondition = '';
        if (null !== $expectedVersion) {
            $versionCondition = ' AND version = :version';
            $parameters['version'] = $expectedVersion;
            $types['version'] = ParameterType::INTEGER;
        }

        return 1 === $this->connection->executeStatement(
            'DELETE FROM rvw_notebook WHERE organization_id = :organization AND id = :id AND owner_id = :owner'.$versionCondition,
            $parameters,
            $types,
        );
    }

    /**
     * @param list<NotebookScreenshot> $screenshots
     */
    private static function screenshotsJsonOf(array $screenshots): string
    {
        return json_encode(array_map(static fn (NotebookScreenshot $screenshot): array => [
            'media_id' => $screenshot->mediaId(),
            'title' => $screenshot->title(),
            'summary' => $screenshot->summary(),
            'pins' => array_map(static fn (NotebookPin $pin): array => ['x_pct' => $pin->xPercent(), 'y_pct' => $pin->yPercent(), 'note' => $pin->note()], $screenshot->pins()),
        ], $screenshots), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function scenariosJsonOf(Scenarios $scenarios): string
    {
        return json_encode($scenarios->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @throws \LogicException when a stored value has another shape than this class writes, or breaks a notebook or
     *                         scenario rule
     */
    private static function notebookOf(array $row): Notebook
    {
        try {
            $screenshots = json_decode(self::stringOf($row, 'screenshots'), true, self::SCREENSHOTS_MAX_DEPTH, \JSON_THROW_ON_ERROR);
            // Objects as stdClass, as ScenarioRules::parse asks.
            $decodedScenarios = json_decode(self::stringOf($row, 'scenarios'), false, self::SCENARIOS_MAX_DEPTH, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $tooDeep) {
            throw new \LogicException('A stored notebook nests its screenshots or scenarios deeper than DbalNotebooks writes.', 0, $tooDeep);
        }
        if (!\is_array($screenshots) || !array_is_list($screenshots)) {
            throw new \LogicException('A stored notebook has screenshots that are not a list.');
        }
        try {
            $scenarios = ScenarioRules::parse($decodedScenarios);
        } catch (ScenarioRefused $broken) {
            throw new \LogicException('A stored notebook has scenarios ScenarioRules refuses.', 0, $broken);
        }

        try {
            return Notebook::restored(
                new NotebookId(self::stringOf($row, 'id')),
                new OrganizationId(self::stringOf($row, 'organization_id')),
                new UserId(self::stringOf($row, 'owner_id')),
                self::stringOf($row, 'title'),
                array_map(self::screenshotOf(...), $screenshots),
                $scenarios,
                self::intOf($row, 'version'),
                new \DateTimeImmutable(self::stringOf($row, 'created_at')),
                new \DateTimeImmutable(self::stringOf($row, 'updated_at')),
                new \DateTimeImmutable(self::stringOf($row, 'last_used_at')),
            );
        } catch (NotebookRefused|\InvalidArgumentException $broken) {
            throw new \LogicException('A stored notebook breaks a notebook rule.', 0, $broken);
        }
    }

    private static function screenshotOf(mixed $stored): NotebookScreenshot
    {
        if (!\is_array($stored) || !\is_string($stored['media_id'] ?? null) || !\is_array($stored['pins'] ?? null)) {
            throw new \LogicException('A stored notebook screenshot has another shape than DbalNotebooks writes.');
        }
        $title = $stored['title'] ?? null;
        $summary = $stored['summary'] ?? null;
        if ((null !== $title && !\is_string($title)) || (null !== $summary && !\is_string($summary))) {
            throw new \LogicException('A stored notebook screenshot has a title or summary that is not text.');
        }

        return NotebookScreenshot::of(
            $stored['media_id'],
            $title,
            $summary,
            array_values(array_map(static function (mixed $pin): NotebookPin {
                $xPercent = \is_array($pin) ? ($pin['x_pct'] ?? null) : null;
                $yPercent = \is_array($pin) ? ($pin['y_pct'] ?? null) : null;
                $note = \is_array($pin) ? ($pin['note'] ?? null) : null;
                if (!(\is_int($xPercent) || \is_float($xPercent)) || !(\is_int($yPercent) || \is_float($yPercent)) || !\is_string($note)) {
                    throw new \LogicException('A stored notebook pin is not {x_pct, y_pct, note}.');
                }

                return NotebookPin::placed((float) $xPercent, (float) $yPercent, $note);
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
            throw new \LogicException(\sprintf('rvw_notebook.%s is not text.', $column));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function intOf(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && 1 === preg_match('/^-?\d+\z/', $value)) {
            return (int) $value;
        }

        throw new \LogicException(\sprintf('rvw_notebook.%s is not a whole number.', $column));
    }

    /**
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    private function requireTenant(): OrganizationId
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Notebooks are read and written in a transaction under the organization as tenant; none is open.');
        }

        return $this->tenantContext->require();
    }
}
