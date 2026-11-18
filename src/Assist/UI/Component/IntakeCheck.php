<?php

declare(strict_types=1);

namespace App\Assist\UI\Component;

use App\Assist\Api\IntakeCheckId;
use App\Assist\Api\IntakeCheckView;
use App\Assist\UI\ViewerAiPolicy;
use App\Ticketing\Api\IntakeCandidate;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * "Check with AI" on the New ticket form (report-a-problem.md, Where it goes): the AI note and button, then the check
 * running, its suggestion or its failure, in the FRAME_ID frame. Ticketing's form embeds it by name, as its UI may not
 * use Assist's Api (deptrac.yaml); on the page it renders idle, and IntakeCheckController renders it again in the frame
 * with the check. A done check carries its type and priority as the form's radio values, for the browser to mark with
 * ✦; the AI never sets anything itself (ADR-0020), and never the visibility.
 */
#[AsTwigComponent(name: 'IntakeCheck', template: '@Assist/components/IntakeCheck.html.twig')]
final class IntakeCheck
{
    public const string FRAME_ID = 'intake-check';

    public const string STATE_IDLE = 'idle';
    public const string STATE_RUNNING = 'running';
    public const string STATE_DONE = 'done';
    public const string STATE_FAILED = 'failed';

    public const string NOTE = 'AI can look for duplicates, missing info and risk, and suggest type and priority.';
    public const string RUNNING_LINE = 'The AI is reading your report…';
    public const string NO_SUMMARY_LINE = 'AI suggestions are marked ✦.';
    public const string DUPLICATES_HEADING = 'The AI thinks this may already be reported';
    public const string CHECK_LABEL = 'Check with AI';
    public const string CHECK_AGAIN_LABEL = 'Check again with AI';
    public const string RUNNING_LABEL = 'Thinking…';

    /** The New ticket form's radio values, by the type and priority the intake schema answers with. */
    private const array TYPE_VALUES = ['Bug' => 'bug', 'Story' => 'story', 'Task' => 'task'];
    private const array PRIORITY_VALUES = ['Critical' => 'critical', 'Major' => 'major', 'Normal' => 'normal', 'Minor' => 'minor'];

    /** Null on the page's first paint and for a refused request, which have no check. */
    public ?IntakeCheckId $checkId = null;

    public ?IntakeCheckView $check = null;

    /** @var list<IntakeCandidate> the suggested duplicates the person may see, in the AI's order */
    public array $duplicates = [];

    /** A refusal of the button's request that hides the button, such as the policy's line. */
    public ?string $refusal = null;

    /** A refusal of the button's request that keeps the button, such as the AI call limit's line. */
    public ?string $notice = null;

    public function __construct(private readonly ViewerAiPolicy $viewerAiPolicy)
    {
    }

    public function state(): string
    {
        return match ($this->check?->status) {
            null => self::STATE_IDLE,
            IntakeCheckView::QUEUED => self::STATE_RUNNING,
            IntakeCheckView::DONE => self::STATE_DONE,
            default => self::STATE_FAILED,
        };
    }

    /**
     * Why "Check with AI" is hidden for this person; null when it shows. Reads the viewer's policy only when no refusal
     * was given.
     */
    public function aiRefusal(): ?string
    {
        return $this->refusal ?? $this->viewerAiPolicy->serverDraftRefusalOf();
    }

    /**
     * "In short: …" goes before the summary in the template; null when the suggestion has no summary to show.
     */
    public function summary(): ?string
    {
        $summary = $this->check?->suggestion?->summary;

        return null === $summary || '' === trim($summary) ? null : $summary;
    }

    /**
     * @return string|null the Type radio's value the AI suggests; null with no suggestion or a type the form lacks
     */
    public function suggestedType(): ?string
    {
        $type = $this->check?->suggestion?->type;

        return null === $type ? null : (self::TYPE_VALUES[$type] ?? null);
    }

    /**
     * @return string|null the Priority radio's value the AI suggests; null with no suggestion or a priority the form lacks
     */
    public function suggestedPriority(): ?string
    {
        $priority = $this->check?->suggestion?->priority;

        return null === $priority ? null : (self::PRIORITY_VALUES[$priority] ?? null);
    }
}
