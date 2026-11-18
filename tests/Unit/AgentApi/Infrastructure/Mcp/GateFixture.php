<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp;

use App\AgentApi\Infrastructure\Mcp\ToolCallAuditor;
use App\AgentApi\Infrastructure\Mcp\ToolCallGate;
use App\Identity\Api\ApiTokenAccess;
use App\Identity\Api\CurrentApiToken;
use App\Identity\Api\CurrentUser;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * A ToolCallGate on doubles: Omar's token in Acme Foods with the scopes given, a voter that grants only the attribute
 * and subject pairs it is told to and remembers each question, an in-memory audit log, and an in-memory SQLite
 * connection with an open transaction in place of the request's, whose table WRITES a tool can write to.
 */
final class GateFixture
{
    public const string ACME_FOODS = '019a5f00-0000-7000-8000-00000000d601';
    public const string OMAR = '019a5f00-0000-7000-8000-00000000d621';
    public const string OMARS_TOKEN = '019a5f00-0000-7000-8000-00000000d611';
    public const string WRITES = 'tool_write';

    public readonly RecordingAuditLog $auditLog;
    public readonly CollectingLogger $logger;
    public readonly Connection $connection;
    public readonly ToolCallGate $gate;
    /** @var list<array{mixed, mixed}> each attribute and subject the voter was asked about */
    public array $voterQuestions = [];

    /**
     * @param list<string>               $scopes
     * @param list<array{string, mixed}> $grants   the attribute and subject pairs the voter grants
     * @param bool                       $hasToken false for a request the firewall let through without a token
     * @param bool                       $hasUser  false for a request the firewall let through without a user
     */
    public function __construct(array $scopes = ['read'], private readonly array $grants = [], ?\Throwable $auditFailure = null, bool $hasToken = true, bool $hasUser = true)
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE '.self::WRITES.' (label TEXT NOT NULL)');
        $this->auditLog = new RecordingAuditLog(new OrganizationId(self::ACME_FOODS), $auditFailure, $this->connection);
        $this->connection->beginTransaction();
        $this->logger = new CollectingLogger();
        $access = $hasToken ? new ApiTokenAccess(self::OMARS_TOKEN, new OrganizationId(self::ACME_FOODS), $scopes, new \DateTimeImmutable('2027-01-31 09:30:00 UTC')) : null;
        $userId = $hasUser ? new UserId(self::OMAR) : null;
        $fixture = $this;

        $this->gate = new ToolCallGate(
            new class($access) implements CurrentApiToken {
                public function __construct(private readonly ?ApiTokenAccess $access)
                {
                }

                public function access(): ?ApiTokenAccess
                {
                    return $this->access;
                }
            },
            new class($userId) implements CurrentUser {
                public function __construct(private readonly ?UserId $userId)
                {
                }

                public function id(): ?UserId
                {
                    return $this->userId;
                }

                public function idOf(mixed $tokenUser): ?UserId
                {
                    return null;
                }
            },
            new class($fixture) implements AuthorizationCheckerInterface {
                public function __construct(private readonly GateFixture $fixture)
                {
                }

                public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
                {
                    return $this->fixture->answerVoterQuestion($attribute, $subject);
                }
            },
            new ToolCallAuditor($this->auditLog),
            $this->connection,
            $this->logger,
        );
    }

    /**
     * @return list<mixed> the labels in WRITES as the open transaction sees them
     */
    public function writtenLabels(): array
    {
        return $this->connection->fetchFirstColumn('SELECT label FROM '.self::WRITES.' ORDER BY label');
    }

    public function writeLabel(string $label): void
    {
        $this->connection->insert(self::WRITES, ['label' => $label]);
    }

    public function answerVoterQuestion(mixed $attribute, mixed $subject): bool
    {
        $this->voterQuestions[] = [$attribute, $subject];

        return \in_array([$attribute, $subject], $this->grants, true);
    }
}
