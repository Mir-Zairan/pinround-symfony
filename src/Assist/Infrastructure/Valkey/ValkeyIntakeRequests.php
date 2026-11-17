<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Valkey;

use App\Assist\Api\DraftReport;
use App\Assist\Api\IntakeCheckId;
use App\Assist\Api\IntakeCheckView;
use App\Assist\Application\Intake\IntakeRequests;
use App\Assist\Application\Intake\TooManyOpenIntakeChecks;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * "Check with AI" states on the state Valkey, which never evicts (ADR-0010), so a queued state is never dropped to make
 * room. Each check has two keys that Valkey expires on its own: "intake_request:<organization>:<check>" holds the small
 * state the page polls, as JSON ({"status", "requesterId", "at" in Unix seconds, "sentence" when failed,
 * "aiMayBeAsked" once the job may have asked the AI}), and "intake_draft:<organization>:<check>" holds the draft as
 * JSON until the job takes it, so a poll never reads the draft. "intake_open:<organization>:<requester>" is a sorted set
 * of the requester's open checks, each scored with the Unix second its queued state expires, which the open script
 * prunes before it counts.
 */
final readonly class ValkeyIntakeRequests implements IntakeRequests
{
    public const int QUEUED_SECONDS = 3600;
    public const int FAILED_SECONDS = 86400;

    // Answers 1 when the check was opened, 0 when the requester has ARGV[6] checks open already and -1 when the check
    // has a state already; nothing is written but the pruning then. ARGV: state, draft, now, expiry, check, cap, seconds.
    private const string OPEN_SCRIPT = <<<'LUA'
        redis.call('ZREMRANGEBYSCORE', KEYS[3], '-inf', ARGV[3])
        if redis.call('EXISTS', KEYS[1]) == 1 then
            return -1
        end
        if redis.call('ZCARD', KEYS[3]) >= tonumber(ARGV[6]) then
            return 0
        end
        redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[7])
        redis.call('SET', KEYS[2], ARGV[2], 'EX', ARGV[7])
        redis.call('ZADD', KEYS[3], ARGV[4], ARGV[5])
        redis.call('EXPIRE', KEYS[3], ARGV[7])
        return 1
        LUA;

    // Answers {1, draft} when the requester's queued state was not marked and now is, and takes the draft away; else 0.
    // A value it cannot decode fails the script.
    private const string MARK_AI_MAY_BE_ASKED_SCRIPT = <<<'LUA'
        local held = redis.call('GET', KEYS[1])
        if not held then
            return 0
        end
        local state = cjson.decode(held)
        if state['status'] ~= 'queued' or state['requesterId'] ~= ARGV[1] or state['aiMayBeAsked'] == true then
            return 0
        end
        local draft = redis.call('GET', KEYS[2])
        if not draft then
            return 0
        end
        redis.call('DEL', KEYS[2])
        state['aiMayBeAsked'] = true
        redis.call('SET', KEYS[1], cjson.encode(state), 'KEEPTTL')
        return {1, draft}
        LUA;

    // ARGV: the failed state, its seconds, the check. The ZREM, the one call that can fail on a value of another type,
    // goes first, as a failed script keeps the writes made before the failure.
    private const string RECORD_FAILURE_SCRIPT = <<<'LUA'
        redis.call('ZREM', KEYS[3], ARGV[3])
        redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[2])
        redis.call('DEL', KEYS[2])
        return 1
        LUA;

    // ARGV: the check. The ZREM goes first, as in the failure script.
    private const string RELEASE_SCRIPT = <<<'LUA'
        redis.call('ZREM', KEYS[3], ARGV[1])
        redis.call('DEL', KEYS[1], KEYS[2])
        return 1
        LUA;

    public function __construct(
        #[Autowire(service: 'state_valkey.connection')]
        private \Redis $connection,
        private ClockInterface $clock,
    ) {
    }

    public function open(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId, DraftReport $draft): void
    {
        $now = $this->clock->now();
        $answer = $this->connection->eval(self::OPEN_SCRIPT, [
            self::stateKeyOf($organizationId, $checkId),
            self::draftKeyOf($organizationId, $checkId),
            self::openChecksKeyOf($organizationId, $requesterId),
            self::encode(IntakeCheckView::QUEUED, $requesterId, $now, null),
            self::encodeDraft($draft),
            (string) $now->getTimestamp(),
            (string) ($now->getTimestamp() + self::QUEUED_SECONDS),
            $checkId->toString(),
            (string) self::MAX_OPEN_CHECKS_PER_PERSON,
            (string) self::QUEUED_SECONDS,
        ], 3);
        if (0 === $answer) {
            throw new TooManyOpenIntakeChecks();
        }
        if (1 !== $answer) {
            $this->clearScriptError($answer);

            throw new \RuntimeException('The intake check state could not be opened.');
        }
    }

    public function markAiMayBeAsked(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId): ?DraftReport
    {
        $answer = $this->connection->eval(
            self::MARK_AI_MAY_BE_ASKED_SCRIPT,
            [self::stateKeyOf($organizationId, $checkId), self::draftKeyOf($organizationId, $checkId), $requesterId->toString()],
            2,
        );
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
        $answer = $this->connection->eval(self::RECORD_FAILURE_SCRIPT, [
            self::stateKeyOf($organizationId, $checkId),
            self::draftKeyOf($organizationId, $checkId),
            self::openChecksKeyOf($organizationId, $requesterId),
            self::encode(IntakeCheckView::FAILED, $requesterId, $this->clock->now(), $sentence),
            (string) self::FAILED_SECONDS,
            $checkId->toString(),
        ], 3);
        if (1 !== $answer) {
            $this->clearScriptError($answer);

            throw new \RuntimeException('The failed intake check could not be stored.');
        }
    }

    public function release(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId): void
    {
        $answer = $this->connection->eval(self::RELEASE_SCRIPT, [
            self::stateKeyOf($organizationId, $checkId),
            self::draftKeyOf($organizationId, $checkId),
            self::openChecksKeyOf($organizationId, $requesterId),
            $checkId->toString(),
        ], 3);
        if (1 !== $answer) {
            $this->clearScriptError($answer);

            throw new \RuntimeException('The intake check could not be released.');
        }
    }

    public function stateOf(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId): ?IntakeCheckView
    {
        $held = $this->connection->get(self::stateKeyOf($organizationId, $checkId));
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

    /**
     * phpredis answers a script error with false instead of throwing, and keeps it as the connection's last error.
     */
    private function clearScriptError(mixed $answer): void
    {
        if (false === $answer) {
            $this->connection->clearLastError();
        }
    }

    private static function encode(string $status, UserId $requesterId, \DateTimeImmutable $at, ?string $sentence): string
    {
        return json_encode(
            ['status' => $status, 'requesterId' => $requesterId->toString(), 'at' => $at->getTimestamp(), 'sentence' => $sentence],
            \JSON_THROW_ON_ERROR,
        );
    }

    private static function encodeDraft(DraftReport $draft): string
    {
        return json_encode(
            ['projectId' => $draft->projectId, 'title' => $draft->title, 'detail' => $draft->detail, 'pinNotes' => $draft->pinNotes],
            \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
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

    private static function stateKeyOf(OrganizationId $organizationId, IntakeCheckId $checkId): string
    {
        return 'intake_request:'.$organizationId->toString().':'.$checkId->toString();
    }

    private static function draftKeyOf(OrganizationId $organizationId, IntakeCheckId $checkId): string
    {
        return 'intake_draft:'.$organizationId->toString().':'.$checkId->toString();
    }

    private static function openChecksKeyOf(OrganizationId $organizationId, UserId $requesterId): string
    {
        return 'intake_open:'.$organizationId->toString().':'.$requesterId->toString();
    }
}
