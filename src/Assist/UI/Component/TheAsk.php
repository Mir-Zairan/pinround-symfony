<?php

declare(strict_types=1);

namespace App\Assist\UI\Component;

use App\Assist\Api\PolicyView;
use App\Assist\Api\SummaryRequestView;
use App\Assist\Application\Policy\PolicyRefusal;
use App\Assist\UI\SummarySourceNote;
use App\Assist\UI\ViewerAiPolicy;
use App\Identity\Api\CurrentTimeZone;
use App\SharedKernel\UI\RelativeTime;
use App\Ticketing\Api\TicketPageView;
use Psr\Clock\ClockInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The ticket page's "The ask" (ticket-page.md): the current summary with its source note and the earlier summaries in
 * a lazy frame, or the ask's text cut at ASK_CUT_LENGTH with "Summarize with AI". Ticketing's page embeds it by name, as
 * its UI may not use Assist's Api (deptrac.yaml). On the page it renders idle from the TicketPageView and the viewer's
 * AI policy, which the header's Local AI chip has read already, so it adds no query to the page's budget
 * (performance-and-scale.md); SummaryController renders it again in the FRAME_ID frame with the latest request state.
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
    ) {
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
     * for an ask with text and no summary, the only place the button may show.
     */
    public function aiRefusal(): ?string
    {
        if (null !== $this->refusal) {
            return $this->refusal;
        }

        return self::refusalOf($this->viewerAiPolicy->policy(), $this->ticket->isTeamOnly());
    }

    /**
     * The policy's answer for the server's AI as AiTaskAdmission gives it before a summary is queued, with the laptop
     * not ready: AI off or not for this person, then "laptop only", which the server never runs, then a team-only
     * ticket the admin keeps from cloud AI.
     */
    public static function refusalOf(PolicyView $policy, bool $isTicketTeamOnly): ?string
    {
        if (null !== $policy->refusalSentence) {
            return $policy->refusalSentence;
        }
        if (PolicyView::WHERE_LAPTOP === $policy->whereAiRuns) {
            return PolicyRefusal::LaptopNotReady->sentence();
        }
        if ($isTicketTeamOnly && !$policy->isRestrictedToCloudAllowed) {
            return PolicyRefusal::RestrictedTicketToCloud->sentence();
        }

        return null;
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
        $authorName = null === $summary->authorId ? null : ($this->ticket->displayNames[$summary->authorId->toString()] ?? null);

        return SummarySourceNote::of($summary, $authorName, $this->clock->now(), $this->currentTimeZone->zone());
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
