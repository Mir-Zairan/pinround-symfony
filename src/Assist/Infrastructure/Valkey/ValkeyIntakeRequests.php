<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Valkey;

use App\Assist\Api\DraftReport;
use App\Assist\Api\IntakeCheckId;
use App\Assist\Api\IntakeCheckView;
use App\Assist\Application\Intake\IntakeRequests;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * "Check with AI" states on the state Valkey, which never evicts (ADR-0010), so a queued state is never dropped to make
 * room. Each check has one key, "intake_request:<organization>:<check>", holding the state as JSON ({"status",
 * "requesterId", "at" in Unix seconds, "sentence" when failed, "draft" while queued and not yet handed to the job, as a
 * JSON string of its own, "aiMayBeAsked" once the job may have asked the AI}) that Valkey expires on its own.
 */
final readonly class ValkeyIntakeRequests implements IntakeRequests
{
    public const int QUEUED_SECONDS = 3600;
    public const int FAILED_SECONDS = 86400;

    // Answers {1, draft} when the requester's queued state was not marked and now is, else 0. A value it cannot decode
    // fails the script. The draft stays a string, so cjson never rewrites it (it would turn an empty list into {}).
    private const string MARK_AI_MAY_BE_ASKED_SCRIPT = <<<'LUA'
        local held = redis.call('GET', KEYS[1])
        if not held then
            return 0
        end
        local state = cjson.decode(held)
        if state['status'] ~= 'queued' or state['requesterId'] ~= ARGV[1] or state['aiMayBeAsked'] == true or type(state['draft']) ~= 'string' then
            return 0
        end
        local draft = state['draft']
        state['draft'] = nil
        state['aiMayBeAsked'] = true
        redis.call('SET', KEYS[1], cjson.encode(state), 'KEEPTTL')
        return {1, draft}
        LUA;

    public function __construct(
        #[Autowire(service: 'state_valkey.connection')]
        private \Redis $connection,
        private ClockInterface $clock,
    ) {
    }

    public function open(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId, DraftReport $draft): void
    {
        $queued = self::encode(IntakeCheckView::QUEUED, $requesterId, $this->clock->now(), null, self::encodeDraft($draft));
        if (true !== $this->connection->set(self::keyOf($organizationId, $checkId), $queued, ['NX', 'EX' => self::QUEUED_SECONDS])) {
            throw new \RuntimeException('The intake check state could not be opened.');
        }
    }

    public function markAiMayBeAsked(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId): ?DraftReport
    {
        $answer = $this->connection->eval(self::MARK_AI_MAY_BE_ASKED_SCRIPT, [self::keyOf($organizationId, $checkId), $requesterId->toString()], 1);
        // phpredis answers a script error with false instead of throwing.
        if (false === $answer) {
            $this->connection->clearLastError();

            throw new \RuntimeException('The intake check could not be marked as one that may have asked the AI.');
        }
        if (0 === $answer) {
            return null;
        }
        if (!\is_array($answer) || 1 !== ($answer[0] ?? null) || !\is_string($answer[1] ?? null)) {
            throw new \RuntimeException('The intake check state answered the mark with an unreadable value.');
        }

        return self::decodeDraft($answer[1]);
    }

    public function recordFailure(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId, string $sentence): void
    {
        $failed = self::encode(IntakeCheckView::FAILED, $requesterId, $this->clock->now(), $sentence, null);
        if (true !== $this->connection->set(self::keyOf($organizationId, $checkId), $failed, ['EX' => self::FAILED_SECONDS])) {
            throw new \RuntimeException('The failed intake check could not be stored.');
        }
    }

    public function release(OrganizationId $organizationId, IntakeCheckId $checkId): void
    {
        $this->connection->del(self::keyOf($organizationId, $checkId));
    }

    public function stateOf(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId): ?IntakeCheckView
    {
        $held = $this->connection->get(self::keyOf($organizationId, $checkId));
        // GET answers false for a key that does not exist.
        if (false === $held) {
            return null;
        }
        try {
            $state = \is_string($held) ? json_decode($held, true, 2, \JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            $state = null;
        }
        if (!\is_array($state) || !\is_string($state['requesterId'] ?? null)) {
            throw new \RuntimeException('The intake check state holds an unreadable value.');
        }
        if ($state['requesterId'] !== $requesterId->toString()) {
            return null;
        }

        return match ($state['status'] ?? null) {
            IntakeCheckView::QUEUED => IntakeCheckView::queued(),
            IntakeCheckView::FAILED => self::failedOf($state['sentence'] ?? null),
            default => throw new \RuntimeException('The intake check state holds an unknown status.'),
        };
    }

    private static function encode(string $status, UserId $requesterId, \DateTimeImmutable $at, ?string $sentence, ?string $draft): string
    {
        $state = ['status' => $status, 'requesterId' => $requesterId->toString(), 'at' => $at->getTimestamp(), 'sentence' => $sentence];
        if (null !== $draft) {
            $state['draft'] = $draft;
        }

        return json_encode($state, \JSON_THROW_ON_ERROR);
    }

    private static function encodeDraft(DraftReport $draft): string
    {
        return json_encode(
            ['projectId' => $draft->projectId, 'title' => $draft->title, 'detail' => $draft->detail, 'pinNotes' => $draft->pinNotes],
            \JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @throws \RuntimeException when the value is not one encodeDraft() wrote
     */
    private static function decodeDraft(string $encoded): DraftReport
    {
        try {
            $draft = json_decode($encoded, true, 3, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $draft = null;
        }
        if (!\is_array($draft) || !\is_string($draft['projectId'] ?? null) || !\is_string($draft['title'] ?? null) || !\is_string($draft['detail'] ?? null)) {
            throw new \RuntimeException('The intake check state holds an unreadable draft.');
        }
        $storedPinNotes = $draft['pinNotes'] ?? null;
        if (!\is_array($storedPinNotes) || !array_is_list($storedPinNotes)) {
            throw new \RuntimeException('The intake check state holds a draft with unreadable pin notes.');
        }
        $pinNotes = [];
        foreach ($storedPinNotes as $pinNote) {
            $pinNotes[] = \is_string($pinNote) ? $pinNote : throw new \RuntimeException('The intake check state holds a draft with unreadable pin notes.');
        }

        return new DraftReport($draft['projectId'], $draft['title'], $draft['detail'], $pinNotes);
    }

    /**
     * @throws \RuntimeException when the sentence is missing or blank
     */
    private static function failedOf(mixed $sentence): IntakeCheckView
    {
        if (!\is_string($sentence)) {
            throw new \RuntimeException('The intake check state holds a failure without its sentence.');
        }
        try {
            return IntakeCheckView::failed($sentence);
        } catch (\InvalidArgumentException $blankSentence) {
            throw new \RuntimeException('The intake check state holds a failure with a blank sentence.', 0, $blankSentence);
        }
    }

    private static function keyOf(OrganizationId $organizationId, IntakeCheckId $checkId): string
    {
        return 'intake_request:'.$organizationId->toString().':'.$checkId->toString();
    }
}
