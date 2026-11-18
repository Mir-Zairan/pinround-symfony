<?php

declare(strict_types=1);

namespace App\AgentApi\Infrastructure\Mcp;

use App\Identity\Api\ApiTokenAccess;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The MCP call limits of ADR-0021: per token 60 a minute and 600 an hour, per organization 600 a minute
 * (rate_limiter.yaml). The counters live on the state Valkey through ValkeyLimiterStorage, so a counter that cannot be
 * read or written throws and the call fails instead of running unlimited.
 *
 * Each key holds the clock service's minute or hour, as SymfonyLaptopRequestLimit's does: the limiter counts its own
 * window on the wall clock, so a key per clock window ends each count when that window does, which a frozen clock can
 * move. A limit that refuses spends nothing on the limits after it: a token at its minute limit spends none of its
 * hour or of its organization's minute. Holds no state, so it is safe in worker mode (ADR-0008).
 */
final readonly class ToolCallLimiter
{
    public const string TOKEN_TOO_FAST = 'This token is calling too fast. Wait a minute and try again.';
    public const string TOKEN_HOUR_SPENT = 'This token made too many calls this hour. Try again next hour.';
    public const string ORGANIZATION_TOO_FAST = 'Your organization is calling too fast. Wait a minute and try again.';

    private const int SECONDS_PER_MINUTE = 60;
    private const int SECONDS_PER_HOUR = 3600;

    public function __construct(
        #[Autowire(service: 'limiter.mcp_calls_per_token_minute')]
        private RateLimiterFactoryInterface $perTokenMinute,
        #[Autowire(service: 'limiter.mcp_calls_per_token_hour')]
        private RateLimiterFactoryInterface $perTokenHour,
        #[Autowire(service: 'limiter.mcp_calls_per_organization_minute')]
        private RateLimiterFactoryInterface $perOrganizationMinute,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Spends one call of the token's minute, the token's hour and the organization's minute, in that order.
     *
     * @throws ToolCallsLimited when one of them is spent; the wait counts to the end of that limit's clock window
     */
    public function consume(ApiTokenAccess $access): void
    {
        $now = $this->clock->now()->getTimestamp();
        $minute = intdiv($now, self::SECONDS_PER_MINUTE);
        $hour = intdiv($now, self::SECONDS_PER_HOUR);
        $secondsToNextMinute = ($minute + 1) * self::SECONDS_PER_MINUTE - $now;
        $secondsToNextHour = ($hour + 1) * self::SECONDS_PER_HOUR - $now;

        self::spendOrRefuse($this->perTokenMinute, $access->tokenId.':'.$minute, self::TOKEN_TOO_FAST, $secondsToNextMinute);
        self::spendOrRefuse($this->perTokenHour, $access->tokenId.':'.$hour, self::TOKEN_HOUR_SPENT, $secondsToNextHour);
        self::spendOrRefuse($this->perOrganizationMinute, $access->organizationId->toString().':'.$minute, self::ORGANIZATION_TOO_FAST, $secondsToNextMinute);
    }

    /**
     * @throws ToolCallsLimited when the limit is spent; a refused call is not counted
     */
    private static function spendOrRefuse(RateLimiterFactoryInterface $limiter, string $key, string $refusal, int $retryAfterSeconds): void
    {
        if (!$limiter->create($key)->consume()->isAccepted()) {
            throw new ToolCallsLimited($refusal, $retryAfterSeconds);
        }
    }
}
