<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Doctrine;

use App\Assist\Api\LocalAiPurpose;
use App\Assist\Api\LocalModelName;
use App\Assist\Api\LocalPackageId;
use App\Assist\Api\LocalResult;
use App\Assist\Application\LocalAi\IssuedLocalPackage;
use App\Assist\Application\LocalAi\LocalPackageLimit;
use App\Assist\Application\LocalAi\LocalPackageOwner;
use App\Assist\Application\LocalAi\LocalPackages;
use App\Assist\Application\LocalAi\PostedLocalResult;
use App\Assist\Application\PromptVersion;
use App\Assist\Application\UnknownPromptTemplate;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketSummaryCommands;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * LocalPackages on ast_local_package. Names the tenant's organization_id as well as row-level security, and the
 * package's user, so nobody reads or uses another person's package. Times come from the application clock, never the
 * database's, so "now" is the same for the check and the write.
 */
final readonly class DbalLocalPackages implements LocalPackages
{
    // An advisory lock, not a row lock: a person with no open package has no row to lock.
    private const string LOCK = 'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))';

    // A package created in the last hour expires after an hour ago (expires_at > created_at), and so does an open one,
    // so the bound on expires_at lets the (organization_id, user_id, expires_at) index find both counts' rows.
    private const string COUNT_OPEN_AND_LAST_HOUR = 'SELECT count(*) FILTER (WHERE used_at IS NULL AND expires_at > ?) AS open_packages,'
        .' count(*) FILTER (WHERE created_at > ?) AS packages_last_hour'
        .' FROM ast_local_package WHERE organization_id = ? AND user_id = ? AND expires_at > ?';

    private const string INSERT = 'INSERT INTO ast_local_package (organization_id, id, user_id, ticket_id, purpose, prompt_version, created_at, expires_at)'
        .' VALUES (?, ?, ?, ?, ?, ?, ?, ?)';

    private const string RESOLVE = 'SELECT organization_id, user_id FROM resolve_laptop_package(?::uuid)';

    private const string SELECT_OF_USER = 'SELECT purpose, ticket_id, prompt_version, (used_at IS NULL AND expires_at > ?) AS is_open'
        .' FROM ast_local_package WHERE organization_id = ? AND id = ? AND user_id = ?';

    private const string POST = 'UPDATE ast_local_package SET used_at = ?, result_text = ?, model = ?, result_label = ?'
        .' WHERE organization_id = ? AND id = ? AND user_id = ? AND used_at IS NULL AND expires_at > ?';

    private const string RESULT_COLUMNS = 'id, user_id, result_text, model, result_label, prompt_version, used_at';

    // The predicate of ast_local_package_ticket_id_used_at_idx, so both statements read the partial index. The length
    // skips a result kept before accept() capped a summary's at a summary's length, which Ticketing would refuse; it
    // filters the index scan and is left out of the index, as such rows are few and never come back. A summary's
    // created_at keeps whole seconds and used_at keeps microseconds, so a result counts as newer from the next second
    // on: one posted earlier in the summary's second may have come before it.
    private const string PROPOSAL_OF_TICKET = 'organization_id = :organization AND ticket_id = :ticket AND used_at IS NOT NULL AND made_main_at IS NULL'
        .' AND used_at >= coalesce(CAST(:newer_than AS timestamptz) + interval \'1 second\', \'-infinity\')'
        .' AND char_length(result_text) <= '.TicketSummaryCommands::TEXT_MAX_LENGTH;

    private const string SELECT_PROPOSAL = 'SELECT '.self::RESULT_COLUMNS.' FROM ast_local_package WHERE '.self::PROPOSAL_OF_TICKET
        .' ORDER BY used_at DESC, id DESC LIMIT 1';

    // The row lock of the UPDATE orders two at once, and the second checks the outer made_main_at again on the row the
    // first committed, so it matches nothing; the subquery alone is not checked again.
    private const string MARK_MADE_MAIN = 'UPDATE ast_local_package SET made_main_at = :made_main_at'
        .' WHERE organization_id = :organization AND ticket_id = :ticket AND id = :package AND made_main_at IS NULL'
        .' AND id = (SELECT id FROM ast_local_package WHERE '.self::PROPOSAL_OF_TICKET.' ORDER BY used_at DESC, id DESC LIMIT 1)'
        .' RETURNING '.self::RESULT_COLUMNS;

    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s.uP';

    // The whole second of tkt_summary.created_at, so a fraction the caller carries cannot move the next second.
    private const string SECOND_FORMAT = 'Y-m-d H:i:sP';

    public function __construct(private Connection $connection, private TenantContext $tenantContext, private ClockInterface $clock)
    {
    }

    public function issue(LocalPackageId $packageId, OrganizationId $organizationId, UserId $requesterId, TicketId $ticketId, LocalAiPurpose $purpose, PromptVersion $promptVersion, int $lifetimeSeconds, int $maxOpenPackages, int $maxPackagesPerHour): \DateTimeImmutable|LocalPackageLimit
    {
        $this->requireTenant($organizationId);
        $now = $this->clock->now();
        $anHourAgo = $now->modify('-1 hour')->format(self::TIMESTAMP_FORMAT);
        $this->connection->executeStatement(self::LOCK, [\sprintf('ast_local_package:open:%s:%s', $organizationId->toString(), $requesterId->toString())]);
        $counts = $this->connection->fetchAssociative(self::COUNT_OPEN_AND_LAST_HOUR, [
            $now->format(self::TIMESTAMP_FORMAT),
            $anHourAgo,
            $organizationId->toString(),
            $requesterId->toString(),
            $anHourAgo,
        ]);
        if (false === $counts || !\is_int($counts['open_packages']) || !\is_int($counts['packages_last_hour'])) {
            throw new \UnexpectedValueException('The counts of laptop packages are not numbers.');
        }
        if ($counts['open_packages'] >= $maxOpenPackages) {
            return LocalPackageLimit::OpenPackages;
        }
        if ($counts['packages_last_hour'] >= $maxPackagesPerHour) {
            return LocalPackageLimit::PackagesPerHour;
        }

        $expiresAt = $now->modify(\sprintf('+%d seconds', $lifetimeSeconds));
        $this->connection->executeStatement(self::INSERT, [
            $organizationId->toString(),
            $packageId->toString(),
            $requesterId->toString(),
            $ticketId->toString(),
            $purpose->value,
            $promptVersion->name(),
            $now->format(self::TIMESTAMP_FORMAT),
            $expiresAt->format(self::TIMESTAMP_FORMAT),
        ]);

        return $expiresAt;
    }

    public function ownerOf(LocalPackageId $packageId): ?LocalPackageOwner
    {
        $row = $this->connection->fetchAssociative(self::RESOLVE, [$packageId->toString()]);
        if (false === $row) {
            return null;
        }
        if (!\is_string($row['organization_id']) || !\is_string($row['user_id'])) {
            throw new \UnexpectedValueException('resolve_laptop_package gave a row without its ids.');
        }

        return new LocalPackageOwner(new OrganizationId($row['organization_id']), new UserId($row['user_id']));
    }

    public function issuedOf(OrganizationId $organizationId, LocalPackageId $packageId, UserId $userId): ?IssuedLocalPackage
    {
        $this->requireTenant($organizationId);
        $row = $this->connection->fetchAssociative(self::SELECT_OF_USER, [
            $this->clock->now()->format(self::TIMESTAMP_FORMAT),
            $organizationId->toString(),
            $packageId->toString(),
            $userId->toString(),
        ]);
        if (false === $row) {
            return null;
        }
        $purpose = \is_string($row['purpose']) ? LocalAiPurpose::tryFrom($row['purpose']) : null;
        if (null === $purpose || !\is_string($row['ticket_id']) || !\is_string($row['prompt_version']) || !\is_bool($row['is_open'])) {
            throw new \UnexpectedValueException('A stored laptop package has a purpose, ticket or prompt version issue() does not write.');
        }
        try {
            $promptVersion = PromptVersion::fromName($row['prompt_version']);
        } catch (UnknownPromptTemplate $unreadable) {
            throw new \UnexpectedValueException('A stored laptop package has a prompt version issue() does not write.', 0, $unreadable);
        }

        return new IssuedLocalPackage($purpose, new TicketId($row['ticket_id']), $promptVersion, $row['is_open']);
    }

    public function post(OrganizationId $organizationId, LocalPackageId $packageId, UserId $posterId, string $text, LocalModelName $model, string $label): ?\DateTimeImmutable
    {
        $this->requireTenant($organizationId);
        $now = $this->clock->now();
        $nowText = $now->format(self::TIMESTAMP_FORMAT);
        $updated = $this->connection->executeStatement(self::POST, [
            $nowText,
            $text,
            $model->value,
            $label,
            $organizationId->toString(),
            $packageId->toString(),
            $posterId->toString(),
            $nowText,
        ]);

        return 1 === $updated ? $now : null;
    }

    public function proposalOf(OrganizationId $organizationId, TicketId $ticketId, ?\DateTimeImmutable $newerThan): ?PostedLocalResult
    {
        $this->requireTenant($organizationId);
        $row = $this->connection->fetchAssociative(self::SELECT_PROPOSAL, self::proposalParameters($organizationId, $ticketId, $newerThan));

        return false === $row ? null : self::postedResultOf($row);
    }

    public function markMadeMain(OrganizationId $organizationId, LocalPackageId $packageId, TicketId $ticketId, ?\DateTimeImmutable $newerThan): ?PostedLocalResult
    {
        $this->requireTenant($organizationId);
        $madeMainAt = $this->clock->now();
        $row = $this->connection->fetchAssociative(self::MARK_MADE_MAIN, [
            ...self::proposalParameters($organizationId, $ticketId, $newerThan),
            'package' => $packageId->toString(),
            'made_main_at' => $madeMainAt->format(self::TIMESTAMP_FORMAT),
        ]);

        return false === $row ? null : self::postedResultOf($row, $madeMainAt);
    }

    /**
     * @return array<string, string|null>
     */
    private static function proposalParameters(OrganizationId $organizationId, TicketId $ticketId, ?\DateTimeImmutable $newerThan): array
    {
        return [
            'organization' => $organizationId->toString(),
            'ticket' => $ticketId->toString(),
            'newer_than' => $newerThan?->format(self::SECOND_FORMAT),
        ];
    }

    /**
     * @param array<string, mixed> $row the columns of RESULT_COLUMNS
     *
     * @throws \UnexpectedValueException when a column is not what post() writes
     */
    private static function postedResultOf(array $row, ?\DateTimeImmutable $madeMainAt = null): PostedLocalResult
    {
        foreach (['id', 'user_id', 'result_text', 'model', 'result_label', 'prompt_version', 'used_at'] as $column) {
            if (!\is_string($row[$column])) {
                throw new \UnexpectedValueException(\sprintf('A posted laptop result has no %s.', $column));
            }
        }
        if (!LocalModelName::isValid($row['model'])) {
            throw new \UnexpectedValueException('A posted laptop result has a model name post() does not write.');
        }

        return new PostedLocalResult(
            new LocalResult(
                new LocalPackageId($row['id']),
                $row['result_text'],
                new LocalModelName($row['model']),
                new UserId($row['user_id']),
                $row['result_label'],
                new \DateTimeImmutable($row['used_at']),
            ),
            $row['prompt_version'],
            $madeMainAt,
        );
    }

    /**
     * @throws \LogicException when no transaction is open or $organizationId is not its tenant
     */
    private function requireTenant(OrganizationId $organizationId): void
    {
        // TenantMiddleware sets the tenant when a transaction begins; outside one the policy would hide every row.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Laptop packages are kept in a transaction under the organization as tenant; none is open.');
        }
        if (!$this->tenantContext->require()->equals($organizationId)) {
            throw new \LogicException('Laptop packages are kept under their own organization as tenant.');
        }
    }
}
