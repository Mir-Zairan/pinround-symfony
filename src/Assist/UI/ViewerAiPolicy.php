<?php

declare(strict_types=1);

namespace App\Assist\UI;

use App\Assist\Api\AiPolicy;
use App\Assist\Api\PolicyView;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The AI policy of the person the request is for, read at most once per request, so the header's Local AI chip and the
 * ticket page's "Summarize with AI" share one read (performance-and-scale.md, the page's query budget). It reads under
 * row-level security and so needs the request transaction; the kernel resets it between requests (ADR-0008).
 */
final class ViewerAiPolicy implements ResetInterface
{
    private ?PolicyView $policy = null;

    public function __construct(private readonly TokenStorageInterface $tokenStorage, private readonly AiPolicy $aiPolicy)
    {
    }

    /**
     * AI off for a visitor who is not logged in to an organization (AiPolicy::forTokenUser).
     */
    public function policy(): PolicyView
    {
        return $this->policy ??= $this->aiPolicy->forTokenUser($this->tokenStorage->getToken()?->getUser());
    }

    /**
     * Why the server's AI may not run for this person on a ticket (AiPolicy::serverTicketRefusalOf); null when it may.
     */
    public function serverTicketRefusalOf(bool $isTicketTeamOnly): ?string
    {
        return $this->aiPolicy->serverTicketRefusalOf($this->policy(), $isTicketTeamOnly);
    }

    /**
     * Why the server's AI may not check this person's draft report (AiPolicy::serverDraftRefusalOf); null when it may.
     */
    public function serverDraftRefusalOf(): ?string
    {
        return $this->aiPolicy->serverDraftRefusalOf($this->policy());
    }

    public function reset(): void
    {
        $this->policy = null;
    }
}
