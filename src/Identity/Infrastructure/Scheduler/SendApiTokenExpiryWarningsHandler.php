<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Scheduler;

use App\Identity\Api\UserQueries;
use App\Identity\Domain\ApiTokens;
use App\Identity\Domain\ExpiringApiToken;
use App\Identity\Infrastructure\Mail\ApiTokenExpiryMail;
use App\SharedKernel\Application\MessageForAnotherTenantRefused;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Mime\Address;

/**
 * Claims one page of the organization's tokens that stop working in about a week and emails each owner one warning
 * listing theirs, in worker-notify inside the bus transaction under the message's TenantStamp. The claim marks each
 * token warned in one conditional statement, so a token is warned once even when two runs or a redelivery overlap. The
 * emails, and the message for the next page when this one is full, are sent only once the transaction commits, so a
 * rollback sends nothing and leaves the tokens to the retry. An owner with no address (blocked) is sent nothing.
 * Nothing is logged: a token's name and prefix stay out of the logs.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class SendApiTokenExpiryWarningsHandler
{
    /**
     * As many tokens as UserQueries::emailsOf() takes owners, as each token may have its own; timeZonesOf() takes the
     * same number (MAX_TIME_ZONE_USER_IDS); were it lower, a full page would throw and roll its claim back.
     */
    public const int MAX_TOKENS_PER_PAGE = UserQueries::MAX_EMAIL_USER_IDS;

    /**
     * @param int $tokensPerPage MAX_TOKENS_PER_PAGE; lower only in tests
     *
     * @throws \InvalidArgumentException when $tokensPerPage is below 1 or above MAX_TOKENS_PER_PAGE
     */
    public function __construct(
        private TenantContext $tenantContext,
        private ApiTokens $apiTokens,
        private UserQueries $userQueries,
        private ApiTokenExpiryMail $expiryMail,
        #[Autowire(service: 'command.bus')]
        private MessageBusInterface $commandBus,
        private ClockInterface $clock,
        private int $tokensPerPage = self::MAX_TOKENS_PER_PAGE,
    ) {
        if ($tokensPerPage < 1 || $tokensPerPage > self::MAX_TOKENS_PER_PAGE) {
            throw new \InvalidArgumentException(\sprintf('A page of expiry warnings holds 1 to %d tokens.', self::MAX_TOKENS_PER_PAGE));
        }
    }

    /**
     * @throws \App\SharedKernel\Application\TenantNotSet when no organization is set, before any write
     * @throws MessageForAnotherTenantRefused             when the message names another organization than the one set
     */
    public function __invoke(SendApiTokenExpiryWarnings $warnings): void
    {
        $organizationId = new OrganizationId($warnings->organizationId);
        if (!$this->tenantContext->require()->equals($organizationId)) {
            throw new MessageForAnotherTenantRefused();
        }

        [$expiresFrom, $expiresBefore] = $warnings->expiryRange();
        $claimed = $this->apiTokens->claimExpiryWarnings($expiresFrom, $expiresBefore, $this->clock->now(), $this->tokensPerPage);

        /** @var array<string, list<ExpiringApiToken>> $tokensByOwner */
        $tokensByOwner = [];
        foreach ($claimed as $token) {
            $tokensByOwner[$token->ownerId->toString()][] = $token;
        }
        $ownerIds = array_map(static fn (string $ownerId): UserId => new UserId($ownerId), array_keys($tokensByOwner));
        $addresses = $this->userQueries->emailsOf($ownerIds);
        $timeZones = $this->userQueries->timeZonesOf($ownerIds);
        foreach ($tokensByOwner as $ownerId => $tokens) {
            $address = $addresses[$ownerId] ?? null;
            if (null === $address) {
                continue;
            }
            $this->commandBus->dispatch(
                new SendEmailMessage($this->expiryMail->emailFor($tokens, new Address($address), $timeZones[$ownerId] ?? new \DateTimeZone('UTC'))),
                [new DispatchAfterCurrentBusStamp()],
            );
        }

        if (\count($claimed) === $this->tokensPerPage) {
            $this->commandBus->dispatch(
                new SendApiTokenExpiryWarnings($organizationId->toString(), $warnings->ranAt),
                [new TenantStamp($organizationId), new DispatchAfterCurrentBusStamp()],
            );
        }
    }
}
