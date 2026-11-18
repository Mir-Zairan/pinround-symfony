<?php

declare(strict_types=1);

namespace App\Assist\UI\Component;

use App\Assist\Api\LocalAi;
use App\Assist\Api\LocalResult;
use App\Assist\Api\PolicyView;
use App\Assist\Api\SummaryRequestView;
use App\Assist\UI\SummarySourceNote;
use App\Assist\UI\ViewerAiPolicy;
use App\Identity\Api\CurrentTimeZone;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\UI\RelativeTime;
use App\Ticketing\Api\TicketPageView;
use App\Ticketing\Api\TicketPermission;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The ticket page's "The ask" (ticket-page.md): the current summary with its source note and the earlier summaries in
 * a lazy frame, or the ask's text cut at ASK_CUT_LENGTH with "Summarize with AI". Ticketing's page embeds it by name, as
 * its UI may not use Assist's Api (deptrac.yaml). On the page it renders idle from the TicketPageView and the viewer's
 * AI policy, which the header's Local AI chip has read already; in an organization whose AI runs on laptops it adds one
 * indexed read to the page's budget (performance-and-scale.md): the laptop summary proposal shown under the summary.
 * SummaryController renders it again
 * in the FRAME_ID frame with the latest request state.
 */
#[AsTwigComponent(name: 'TheAsk', template: '@Assist/components/TheAsk.html.twig')]
final class TheAsk
{
    public const string FRAME_ID = 'ticket-ask';
    public const string EARLIER_FRAME_ID = 'ticket-ask-earlier';

    /** The ask is cut after this many characters, at a word end (ticket-page.md, The ask). */
    public const int ASK_CUT_LENGTH = 700;
    public const string ASK_CUT_MARK = ' …';

    public const string UNKNOWN_REPORTER = 'an unknown person';

    public const string ORIGINAL_TEXT_FROM_REPORT = 'Original text from the report';
    public const string ORIGINAL_TEXT_FROM_JIRA = 'Original text from Jira';

    /** Heading of the laptop summary proposal, followed by LocalResult's label; not in the prototype. */
    public const string PROPOSAL_HEADING = 'Laptop summary';

    public const string STATE_IDLE = 'idle';
    public const string STATE_RUNNING = 'running';
    public const string STATE_FAILED = 'failed';
    public const string STATE_DONE = 'done';

    public TicketPageView $ticket;

    /** The latest request SummaryController read; null on the page's first paint, which reads no request state. */
    public ?SummaryRequestView $summaryRequest = null;

    /** A refusal of the button's request that hides the button, such as the policy's line. */
    public ?string $refusal = null;

    /** A refusal of the button's request that keeps the button, such as the AI call limit's line. */
    public ?string $notice = null;

    public function __construct(
        private readonly ViewerAiPolicy $viewerAiPolicy,
        private readonly ClockInterface $clock,
        private readonly CurrentTimeZone $currentTimeZone,
        private readonly LocalAi $localAi,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * The ticket's laptop summary proposal, which everyone who sees the ticket sees under its summary; one indexed read
     * (LocalAi::proposalOf), so the template asks for it once. None in an organization whose AI runs in the cloud,
     * which reads nothing: the page budget of a cloud organization stays as before laptop AI.
     */
    public function proposal(): ?LocalResult
    {
        // The organization's setting, not this viewer's permission: everyone who sees the ticket sees the proposal.
        if (PolicyView::WHERE_CLOUD === $this->viewerAiPolicy->policy()->whereAiRuns) {
            return null;
        }

        return $this->localAi->proposalOf($this->ticket->id, $this->ticket->currentSummary?->createdAt, $this->ticket->visibilityFacts->organizationId);
    }

    /**
     * Whether "Use as main summary" shows: to the lead of the ticket's team and its reporter.
     */
    public function canChooseMainSummary(): bool
    {
        return $this->authorizationChecker->isGranted(TicketPermission::CHOOSE_MAIN_SUMMARY, $this->ticket->visibilityFacts);
    }

    /**
     * Whether the organization runs AI on laptops for this person (laptop only or laptop first), so "Summarize with AI"
     * carries what the browser needs to run it on the laptop.
     */
    public function isOnLaptop(): bool
    {
        return $this->viewerAiPolicy->policy()->isLaptopAllowed();
    }

    /**
     * STATE_DONE once the ticket has a summary, whatever the request state says; else the request's state.
     */
    public function state(): string
    {
        if (null !== $this->ticket->currentSummary) {
            return self::STATE_DONE;
        }
        if (null === $this->summaryRequest) {
            return self::STATE_IDLE;
        }

        return $this->summaryRequest->isQueued() ? self::STATE_RUNNING : self::STATE_FAILED;
    }

    /**
     * Why "Summarize with AI" is hidden for this person and ticket; null when it shows. Reads the viewer's policy only
     * for an ask with text and no summary, the only place the button may show. In a laptop-only organization the server
     * cannot tell whether this laptop is ready, so it is PolicyRefusal::LaptopNotReady's line (ticket-page.md, AI not
     * available), which the browser hides when it is.
     */
    public function aiRefusal(): ?string
    {
        return $this->refusal ?? $this->viewerAiPolicy->serverTicketRefusalOf($this->ticket->isTeamOnly());
    }

    public function shortAsk(): ?string
    {
        return self::shortAskOf($this->ticket->description);
    }

    public function askedLine(): string
    {
        return \sprintf(
            'Asked by %s · %s',
            $this->ticket->reporterName ?? self::UNKNOWN_REPORTER,
            RelativeTime::dateOf($this->ticket->createdAt, $this->clock->now(), $this->currentTimeZone->zone()),
        );
    }

    public function sourceNote(): ?string
    {
        $summary = $this->ticket->currentSummary;
        if (null === $summary) {
            return null;
        }
        $nameOf = fn (?UserId $personId): ?string => null === $personId ? null : ($this->ticket->displayNames[$personId->toString()] ?? null);

        return SummarySourceNote::of($summary, $nameOf($summary->authorId), $this->clock->now(), $this->currentTimeZone->zone(), $nameOf($summary->chosenById));
    }

    /**
     * The heading of the ask's text under a summary: a summary written at sync was written from the Jira text.
     */
    public function originalTextHeading(): string
    {
        return 'sync' === $this->ticket->currentSummary?->source ? self::ORIGINAL_TEXT_FROM_JIRA : self::ORIGINAL_TEXT_FROM_REPORT;
    }

    public function isSummaryWrittenByAi(): bool
    {
        return null !== $this->ticket->currentSummary && SummarySourceNote::isWrittenByAi($this->ticket->currentSummary);
    }

    /**
     * "1 earlier summary" or "3 earlier summaries".
     */
    public function earlierSummariesLabel(): string
    {
        $count = $this->ticket->earlierSummaryCount;

        return \sprintf(1 === $count ? '%d earlier summary' : '%d earlier summaries', $count);
    }

    /**
     * The ask's first ASK_CUT_LENGTH characters, cut back to the end of the last whole word, then ASK_CUT_MARK. A
     * first word longer than the limit is cut inside it.
     *
     * @return string|null null when the whole ask fits, so the page shows it all and no "Show the full ask"
     */
    public static function shortAskOf(string $description): ?string
    {
        if (mb_strlen($description, 'UTF-8') <= self::ASK_CUT_LENGTH) {
            return null;
        }
        $head = mb_substr($description, 0, self::ASK_CUT_LENGTH, 'UTF-8');
        $isCutInsideAWord = 1 !== preg_match('/^\s/u', mb_substr($description, self::ASK_CUT_LENGTH, 1, 'UTF-8'));
        if ($isCutInsideAWord && 1 === preg_match('/^(.*\S)\s+\S*\z/su', $head, $wholeWords)) {
            $head = $wholeWords[1];
        }

        return (preg_replace('/\s+\z/u', '', $head) ?? $head).self::ASK_CUT_MARK;
    }
}
