<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Valkey;

use App\Assist\Api\SummaryRequestView;
use App\Assist\Application\Summaries\SummaryRequests;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Summary request states on the state Valkey, which never evicts (ADR-0010), so a queued state is never dropped to
 * make room. Each ticket has one key, "summary_request:<organization>:<ticket>", holding the state as JSON
 * ({"status", "requesterId", "at" in Unix seconds, "sentence" when failed, "aiAsked" once the queued job asked the AI})
 * that Valkey expires on its own.
 */
final readonly class ValkeySummaryRequests implements SummaryRequests
{
    public const int QUEUED_SECONDS = 3600;
    public const int FAILED_SECONDS = 86400;

    // Answers 1 when the state was not queued and now is, else 0. A value it cannot decode fails the script.
    private const string CLAIM_SCRIPT = <<<'LUA'
        local held = redis.call('GET', KEYS[1])
        if held and cjson.decode(held)['status'] == 'queued' then
            return 0
        end
        redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[2])
        return 1
        LUA;

    // Answers 1 when the requester's queued state was not marked and now is, else 0. A value it cannot decode fails
    // the script. cjson writes the state back with the same fields, and KEEPTTL keeps the claim's expiry.
    private const string MARK_AI_ASKED_SCRIPT = <<<'LUA'
        local held = redis.call('GET', KEYS[1])
        if not held then
            return 0
        end
        local state = cjson.decode(held)
        if state['status'] ~= 'queued' or state['requesterId'] ~= ARGV[1] or state['aiAsked'] == true then
            return 0
        end
        state['aiAsked'] = true
        redis.call('SET', KEYS[1], cjson.encode(state), 'KEEPTTL')
        return 1
        LUA;

    public function __construct(
        #[Autowire(service: 'state_valkey.connection')]
        private \Redis $connection,
        private ClockInterface $clock,
    ) {
    }

    public function claim(OrganizationId $organizationId, TicketId $ticketId, UserId $requesterId): bool
    {
        $queued = self::encode(SummaryRequestView::QUEUED, $requesterId, $this->clock->now(), null);
        $answer = $this->connection->eval(self::CLAIM_SCRIPT, [self::keyOf($organizationId, $ticketId), $queued, (string) self::QUEUED_SECONDS], 1);
        // phpredis answers a script error with false instead of throwing.
        if (false === $answer) {
            $this->connection->clearLastError();

            throw new \RuntimeException('The summary request state could not be claimed.');
        }

        return 1 === $answer;
    }

    public function markAiAsked(OrganizationId $organizationId, TicketId $ticketId, UserId $requesterId): bool
    {
        $answer = $this->connection->eval(self::MARK_AI_ASKED_SCRIPT, [self::keyOf($organizationId, $ticketId), $requesterId->toString()], 1);
        // phpredis answers a script error with false instead of throwing.
        if (false === $answer) {
            $this->connection->clearLastError();

            throw new \RuntimeException('The summary request could not be marked as having asked the AI.');
        }

        return 1 === $answer;
    }

    public function recordFailure(OrganizationId $organizationId, TicketId $ticketId, UserId $requesterId, string $sentence): void
    {
        $failed = self::encode(SummaryRequestView::FAILED, $requesterId, $this->clock->now(), $sentence);
        if (true !== $this->connection->set(self::keyOf($organizationId, $ticketId), $failed, ['EX' => self::FAILED_SECONDS])) {
            throw new \RuntimeException('The failed summary request could not be stored.');
        }
    }

    public function release(OrganizationId $organizationId, TicketId $ticketId): void
    {
        $this->connection->del(self::keyOf($organizationId, $ticketId));
    }

    public function latestOf(OrganizationId $organizationId, TicketId $ticketId): ?SummaryRequestView
    {
        $held = $this->connection->get(self::keyOf($organizationId, $ticketId));
        // GET answers false for a key that does not exist.
        if (false === $held) {
            return null;
        }

        return self::decode($held);
    }

    private static function encode(string $status, UserId $requesterId, \DateTimeImmutable $at, ?string $sentence): string
    {
        return json_encode(
            ['status' => $status, 'requesterId' => $requesterId->toString(), 'at' => $at->getTimestamp(), 'sentence' => $sentence],
            \JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @throws \RuntimeException when the value is not one encode() wrote
     */
    private static function decode(mixed $held): SummaryRequestView
    {
        try {
            $state = \is_string($held) ? json_decode($held, true, 2, \JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            $state = null;
        }
        if (!\is_array($state) || !\is_string($state['requesterId'] ?? null) || !\is_int($state['at'] ?? null)) {
            throw new \RuntimeException('The summary request state holds an unreadable value.');
        }
        try {
            $requesterId = new UserId($state['requesterId']);
        } catch (\InvalidArgumentException $notAUserId) {
            throw new \RuntimeException('The summary request state holds no user id.', 0, $notAUserId);
        }
        $at = new \DateTimeImmutable('@'.$state['at']);

        return match ($state['status'] ?? null) {
            SummaryRequestView::QUEUED => SummaryRequestView::queued($requesterId, $at),
            SummaryRequestView::FAILED => self::failedOf($requesterId, $at, $state['sentence'] ?? null),
            default => throw new \RuntimeException('The summary request state holds an unknown status.'),
        };
    }

    /**
     * @throws \RuntimeException when the sentence is missing or blank
     */
    private static function failedOf(UserId $requesterId, \DateTimeImmutable $failedAt, mixed $sentence): SummaryRequestView
    {
        if (!\is_string($sentence)) {
            throw new \RuntimeException('The summary request state holds a failure without its sentence.');
        }
        try {
            return SummaryRequestView::failed($requesterId, $failedAt, $sentence);
        } catch (\InvalidArgumentException $blankSentence) {
            throw new \RuntimeException('The summary request state holds a failure with a blank sentence.', 0, $blankSentence);
        }
    }

    private static function keyOf(OrganizationId $organizationId, TicketId $ticketId): string
    {
        return 'summary_request:'.$organizationId->toString().':'.$ticketId->toString();
    }
}
