<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Mercure;

use App\Notification\Application\Bell\BellInbox;
use App\Notification\Application\Realtime\RefreshBell;
use App\SharedKernel\Application\MessageForAnotherTenantRefused;
use App\SharedKernel\Application\OnceOnlyHandling;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Twig\Environment;

/**
 * Publishes a person's bell push in worker-notify, inside the transaction of the bus under the message's TenantStamp:
 * Turbo Streams with the new count, a fresh lazy popover frame and the toast, on that person's topic only. The frame is
 * never rendered with its cards, whose forms need a CSRF token of the person's session; the page loads them through
 * that session. Sent with no SSE type, so the page renders it as a Turbo Stream, not as a refresh signal. A push
 * delivered again publishes nothing, so its toast shows once; only a delivery whose bus transaction fails after the hub
 * took the update publishes it a second time.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class RefreshBellHandler
{
    public const string TEMPLATE = '@Notification/bell_push.stream.html.twig';

    /** The OnceOnlyHandling name; kept when the class is renamed, or redelivered pushes would show their toast again. */
    public const string HANDLER_NAME = 'notification.refresh_bell';

    public function __construct(
        private HubInterface $hub,
        private BellInbox $bellInbox,
        private TenantContext $tenantContext,
        private Environment $twig,
        private ClockInterface $clock,
        private OnceOnlyHandling $onceOnlyHandling,
    ) {
    }

    /**
     * @throws \App\SharedKernel\Application\TenantNotSet            when no organization is set, before any read
     * @throws MessageForAnotherTenantRefused                        when the push names another organization than the one set
     * @throws \Symfony\Component\Mercure\Exception\RuntimeException when the hub refuses the update, so it is retried
     */
    public function __invoke(RefreshBell $refresh): void
    {
        $organizationId = new OrganizationId($refresh->organizationId);
        if (!$this->tenantContext->require()->equals($organizationId)) {
            throw new MessageForAnotherTenantRefused();
        }

        $userId = new UserId($refresh->userId);
        $this->onceOnlyHandling->runOnce(self::HANDLER_NAME, function () use ($organizationId, $userId, $refresh): void {
            $this->hub->publish(new Update(
                RealtimeTopics::ofUser($organizationId, $userId),
                $this->twig->render(self::TEMPLATE, [
                    'openCount' => $this->bellInbox->openCountOf($userId, $this->clock->now()),
                    'toast' => $refresh->toast,
                ]),
                private: true,
            ));
        });
    }
}
