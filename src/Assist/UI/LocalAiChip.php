<?php

declare(strict_types=1);

namespace App\Assist\UI;

use App\Assist\Api\AiPolicy;
use App\Assist\Api\PolicyView;
use App\Assist\Application\Policy\PolicyRefusal;
use App\Organization\Api\CurrentActor;
use App\SharedKernel\UI\CspConnectSources;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Who gets the header's "Local AI" chip, its setup sheet and the status call behind it (FR-AI-10): a member whose
 * organization runs AI on laptops (laptop only or laptop first) and whose policy gives them AI help, but not an admin
 * with no team or manager role, who files and reads no tickets to use it on. It reads the policy at most once per
 * request and so needs the request transaction; the kernel resets it between requests (ADR-0008).
 */
final class LocalAiChip implements ResetInterface
{
    /** Where the browser finds Ollama on the laptop; the only origin the local-ai controller calls. */
    public const string OLLAMA_ORIGIN = 'http://localhost:11434';

    public const string NOT_A_MEMBER = 'Local AI is for members of this organization.';
    public const string ADMIN_ONLY = 'Local AI is for people in a team and for managers.';

    /** The policy when the person gets the chip, else the one line that says why not; null until read. */
    private PolicyView|string|null $decision = null;

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly CurrentActor $currentActor,
        private readonly AiPolicy $aiPolicy,
        private readonly CspConnectSources $cspConnectSources,
    ) {
    }

    /**
     * For the header. When the chip shows, it also lets this page's connect-src reach OLLAMA_ORIGIN, so a page
     * without the chip keeps the plain policy.
     *
     * @return PolicyView|null the viewer's policy when the chip shows, else null
     */
    #[AsTwigFunction('local_ai_chip')]
    public function chipPolicy(): ?PolicyView
    {
        $decision = $this->decision();
        if (!$decision instanceof PolicyView) {
            return null;
        }
        $this->cspConnectSources->allow(self::OLLAMA_ORIGIN);

        return $decision;
    }

    /**
     * For the status call: the same rule as the chip.
     *
     * @return string|null null when the person gets the chip, else the one line that says why not
     */
    public function refusal(): ?string
    {
        $decision = $this->decision();

        return $decision instanceof PolicyView ? null : $decision;
    }

    public function reset(): void
    {
        $this->decision = null;
    }

    private function decision(): PolicyView|string
    {
        return $this->decision ??= $this->decide();
    }

    /**
     * Reads no policy for a visitor with no organization or an admin-only member.
     */
    private function decide(): PolicyView|string
    {
        $tokenUser = $this->tokenStorage->getToken()?->getUser();
        $roles = $this->currentActor->actorFor($tokenUser)?->roles();
        if (null === $roles) {
            return self::NOT_A_MEMBER;
        }
        if ($roles->isAdmin() && !$roles->isManager() && [] === $roles->teamIds()) {
            return self::ADMIN_ONLY;
        }

        $policy = $this->aiPolicy->forTokenUser($tokenUser);
        if (null !== $policy->refusalSentence) {
            return $policy->refusalSentence;
        }

        return $policy->isLaptopAllowed() ? $policy : PolicyRefusal::CloudOnly->sentence();
    }
}
