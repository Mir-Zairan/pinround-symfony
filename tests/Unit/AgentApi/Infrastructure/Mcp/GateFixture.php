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
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * A ToolCallGate on doubles: Omar's token in Acme Foods with the scopes given, a voter that grants only the attribute
 * and subject pairs it is told to and remembers each question, and an in-memory audit log.
 */
final class GateFixture
{
    public const string ACME_FOODS = '019a5f00-0000-7000-8000-00000000d601';
    public const string OMAR = '019a5f00-0000-7000-8000-00000000d621';
    public const string OMARS_TOKEN = '019a5f00-0000-7000-8000-00000000d611';

    public readonly RecordingAuditLog $auditLog;
    public readonly CollectingLogger $logger;
    public readonly ToolCallGate $gate;
    /** @var list<array{mixed, mixed}> each attribute and subject the voter was asked about */
    public array $voterQuestions = [];

    /**
     * @param list<string>               $scopes
     * @param list<array{string, mixed}> $grants   the attribute and subject pairs the voter grants
     * @param bool                       $hasToken false for a request the firewall let through without a token
     */
    public function __construct(array $scopes = ['read'], private readonly array $grants = [], ?\Throwable $auditFailure = null, bool $hasToken = true)
    {
        $this->auditLog = new RecordingAuditLog(new OrganizationId(self::ACME_FOODS), $auditFailure);
        $this->logger = new CollectingLogger();
        $access = $hasToken ? new ApiTokenAccess(self::OMARS_TOKEN, new OrganizationId(self::ACME_FOODS), $scopes) : null;
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
            new class implements CurrentUser {
                public function id(): UserId
                {
                    return new UserId(GateFixture::OMAR);
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
            $this->logger,
        );
    }

    public function answerVoterQuestion(mixed $attribute, mixed $subject): bool
    {
        $this->voterQuestions[] = [$attribute, $subject];

        return \in_array([$attribute, $subject], $this->grants, true);
    }
}
