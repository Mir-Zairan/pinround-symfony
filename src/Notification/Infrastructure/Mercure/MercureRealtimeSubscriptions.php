<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Mercure;

use App\Notification\Application\Realtime\RealtimeSubscriptions;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\ProtocolVersion;

/**
 * RealtimeSubscriptions on the default hub (config/packages/mercure.yaml). The cookie is the bundle's: Secure for the
 * https public URL, HttpOnly, SameSite=Strict, its path the hub's; the bundle adds it to the response.
 */
final readonly class MercureRealtimeSubscriptions implements RealtimeSubscriptions
{
    public function __construct(
        private Authorization $authorization,
        private HubInterface $hub,
        private RequestStack $requestStack,
        private ClockInterface $clock,
    ) {
    }

    public function grant(OrganizationId $organizationId, UserId $userId, TicketId ...$ticketIds): void
    {
        $request = $this->requestStack->getMainRequest() ?? throw new \LogicException('A subscriber cookie needs a request to answer.');
        $topics = [RealtimeTopics::ofUser($organizationId, $userId)];
        foreach ($ticketIds as $ticketId) {
            $topics[] = RealtimeTopics::ofTicket($organizationId, $ticketId);
        }
        $issuedAt = $this->clock->now();

        // The bundle's JwtClaims takes iat from the wall clock and exp from the cookie lifetime when they are missing.
        $this->authorization->setCookie($request, array_values(array_unique($topics)), additionalClaims: [
            'iat' => $issuedAt,
            'exp' => $issuedAt->modify('+'.self::LIFETIME_SECONDS.' seconds'),
        ]);
    }

    public function subscribeUrlOf(OrganizationId $organizationId, UserId $userId, ?TicketId $ticketId = null): string
    {
        $topics = [RealtimeTopics::ofUser($organizationId, $userId)];
        if (null !== $ticketId) {
            $topics[] = RealtimeTopics::ofTicket($organizationId, $ticketId);
        }

        // As the bundle's mercure() Twig function: protocol 1.0 matches exact topics with ?match=, older hubs read ?topic=.
        $parameter = ProtocolVersion::V1 === $this->hub->getProtocolVersion() ? 'match' : 'topic';

        return $this->hub->getPublicUrl().'?'.implode('&', array_map(static fn (string $topic): string => $parameter.'='.rawurlencode($topic), $topics));
    }
}
