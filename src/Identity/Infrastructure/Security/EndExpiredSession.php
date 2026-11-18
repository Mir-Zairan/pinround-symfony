<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ends a session that was idle too long or is too old (ADR-0012, FR-ID-06). It runs just before the firewall (priority
 * 8), so the firewall finds no token in the emptied session and the request goes on like any logged-out one: the login
 * page, or a redirect to it. The session's metadata gives both times; the login's session migration restamps its
 * creation time, so the absolute limit counts from the login. A request under a firewall without security (the
 * health probes) or a stateless one (the personal access token paths, which ignore the cookie) is left alone, so it
 * never opens the session in Valkey.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
final readonly class EndExpiredSession
{
    public function __construct(
        private ClockInterface $clock,
        #[Autowire(service: 'security.firewall.map')]
        private FirewallMap $firewallMap,
        #[Autowire(param: 'identity.session_idle_seconds')]
        private int $idleSeconds,
        #[Autowire(param: 'identity.session_absolute_days')]
        private int $absoluteDays,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $firewallConfig = $this->firewallMap->getFirewallConfig($request);
        if (!$event->isMainRequest() || !$request->hasPreviousSession()
            || true !== $firewallConfig?->isSecurityEnabled() || $firewallConfig->isStateless()) {
            return;
        }

        $session = $request->getSession();
        $session->start();
        $metadata = $session->getMetadataBag();
        $now = $this->clock->now()->getTimestamp();

        $isIdleTooLong = $now - $metadata->getLastUsed() > $this->idleSeconds;
        $isTooOld = $now - $metadata->getCreated() > $this->absoluteDays * 86400;
        if ($isIdleTooLong || $isTooOld) {
            $session->invalidate();
        }
    }
}
