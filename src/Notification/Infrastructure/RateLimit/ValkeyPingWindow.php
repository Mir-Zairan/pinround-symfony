<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\RateLimit;

use App\Notification\Api\PingsPaused;
use App\Notification\Api\PingTooSoon;
use App\Notification\Application\Ping\PingWindow;
use App\Notification\Domain\PingId;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Symfony\Component\Cache\Exception\InvalidArgumentException as CacheConnectionFailed;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The ping window on the state Valkey, which never evicts (ADR-0010), so a held slot is never dropped to make room.
 * Each slot is one key holding "<again at, Unix seconds>:<ping id>" that Valkey expires when the slot ends.
 */
final readonly class ValkeyPingWindow implements PingWindow
{
    // Answers 1 when the slot was free and is now taken, else the value of the ping that holds it.
    private const string TAKE_SCRIPT = <<<'LUA'
        if redis.call('SET', KEYS[1], ARGV[1], 'NX', 'EX', ARGV[2]) then
            return 1
        end
        return redis.call('GET', KEYS[1])
        LUA;

    // ARGV[1] is ":<ping id>"; the part before it is digits only, so a suffix match is an exact match on the ping.
    private const string RELEASE_SCRIPT = <<<'LUA'
        local held = redis.call('GET', KEYS[1])
        if held and string.sub(held, -string.len(ARGV[1])) == ARGV[1] then
            return redis.call('DEL', KEYS[1])
        end
        return 0
        LUA;

    private const string HELD_SLOT = '/^(\d{1,12}):[0-9a-f-]{36}\z/';

    public function __construct(#[Autowire(service: 'state_valkey.connection')] private \Redis $connection)
    {
    }

    public function take(OrganizationId $organizationId, UserId $fromUserId, UserId $toUserId, TicketId $ticketId, PingId $pingId, int $hours, \DateTimeImmutable $at): void
    {
        if ($hours < 1) {
            throw new \InvalidArgumentException('A ping limit is at least one hour.');
        }

        $againAt = $at->modify('+'.$hours.' hours');
        $answer = $this->evaluate(self::TAKE_SCRIPT, $this->keyOf($organizationId, $fromUserId, $toUserId, $ticketId), [$againAt->getTimestamp().':'.$pingId->toString(), (string) ($hours * 3600)]);
        if (1 === $answer) {
            return;
        }

        throw new PingTooSoon(self::againAtOf($answer));
    }

    public function release(OrganizationId $organizationId, UserId $fromUserId, UserId $toUserId, TicketId $ticketId, PingId $pingId): void
    {
        $this->evaluate(self::RELEASE_SCRIPT, $this->keyOf($organizationId, $fromUserId, $toUserId, $ticketId), [':'.$pingId->toString()]);
    }

    public function heldUntilOf(OrganizationId $organizationId, UserId $fromUserId, array $toUserIds, TicketId $ticketId): array
    {
        if ([] === $toUserIds) {
            return [];
        }

        $keys = array_map(fn (UserId $toUserId): string => $this->keyOf($organizationId, $fromUserId, $toUserId, $ticketId), $toUserIds);
        try {
            $values = $this->connection->mget($keys);
        } catch (\RedisException|CacheConnectionFailed $failure) {
            throw new PingsPaused($failure);
        }
        if (!\is_array($values) || \count($values) !== \count($keys)) {
            throw new PingsPaused();
        }

        $heldUntilById = [];
        foreach (array_values($values) as $index => $held) {
            // MGET answers false for a key that does not exist: a free slot.
            if (false === $held) {
                continue;
            }
            $heldUntilById[$toUserIds[$index]->toString()] = self::againAtOf($held);
        }

        return $heldUntilById;
    }

    /**
     * @throws \LogicException when the value is not "<again at>:<ping id>"
     */
    private static function againAtOf(mixed $held): \DateTimeImmutable
    {
        if (!\is_string($held) || 1 !== preg_match(self::HELD_SLOT, $held, $matches)) {
            throw new \LogicException('The ping window holds an unreadable slot.');
        }

        return new \DateTimeImmutable('@'.$matches[1]);
    }

    /**
     * @param list<string> $arguments
     *
     * @throws PingsPaused when Valkey cannot be reached or the script fails
     */
    private function evaluate(string $script, string $key, array $arguments): mixed
    {
        try {
            $answer = $this->connection->eval($script, [$key, ...$arguments], 1);
        } catch (\RedisException|CacheConnectionFailed $failure) {
            throw new PingsPaused($failure);
        }

        // phpredis answers a script error (and nothing else here) with false instead of throwing.
        if (false === $answer) {
            $this->connection->clearLastError();

            throw new PingsPaused();
        }

        return $answer;
    }

    private function keyOf(OrganizationId $organizationId, UserId $fromUserId, UserId $toUserId, TicketId $ticketId): string
    {
        return 'ping:'.$organizationId->toString().':'.$fromUserId->toString().':'.$toUserId->toString().':'.$ticketId->toString();
    }
}
