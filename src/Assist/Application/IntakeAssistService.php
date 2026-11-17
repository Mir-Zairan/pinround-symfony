<?php

declare(strict_types=1);

namespace App\Assist\Application;

use App\Assist\Api\DraftReport;
use App\Assist\Api\IntakeAssist;
use App\Assist\Api\IntakeCheckId;
use App\Assist\Api\IntakeCheckRefused;
use App\Assist\Api\IntakeCheckView;
use App\Assist\Application\Gateway\AiTaskAdmission;
use App\Assist\Application\Intake\GenerateIntakeSuggestion;
use App\Assist\Application\Intake\IntakeCheckIds;
use App\Assist\Application\Intake\IntakeJobQueue;
use App\Assist\Application\Intake\IntakeRequests;
use App\Assist\Application\Intake\TooManyOpenIntakeChecks;
use App\Assist\Domain\AiFeature;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\ListFilter;

/**
 * Refuses a draft over AiDraft's caps first, then asks AiTaskAdmission, the check the job's gateway call makes first,
 * without reading the draft's candidates, and the person's call limit without counting a call, so a check the gateway
 * would refuse queues nothing; then keeps the draft with the check's state, at most
 * IntakeRequests::MAX_OPEN_CHECKS_PER_PERSON open at once per person, and queues the job.
 */
final readonly class IntakeAssistService implements IntakeAssist
{
    public const string TOO_MANY_OPEN_CHECKS_SENTENCE = 'You have '.IntakeRequests::MAX_OPEN_CHECKS_PER_PERSON.' AI checks running. Wait for one to finish.';

    public function __construct(
        private AiRequesters $requesters,
        private AiTaskAdmission $aiTaskAdmission,
        private AiCallLimit $aiCallLimit,
        private IntakeRequests $intakeRequests,
        private IntakeJobQueue $intakeJobQueue,
        private Suggestions $suggestions,
        private IntakeCheckIds $intakeCheckIds,
    ) {
    }

    public function suggest(DraftReport $draft, UserId $requesterId, OrganizationId $organizationId): IntakeCheckId
    {
        $aiDraft = self::aiDraftOf($draft);
        $requester = $this->requesters->actorOf($requesterId, $organizationId)
            ?? throw new IntakeCheckRefused(IntakeCheckRefused::NOT_A_MEMBER, 'AI help works only in your own organization.');
        $refusal = $this->aiTaskAdmission->refusalFor(new AiTask(AiFeature::Intake, draft: $aiDraft), $requester);
        if (null !== $refusal) {
            throw new IntakeCheckRefused($refusal->value, $refusal->sentence());
        }
        if (!$this->aiCallLimit->hasCallsLeft($requester)) {
            throw new IntakeCheckRefused(IntakeCheckRefused::TOO_MANY_AI_REQUESTS, AiRateLimited::PER_PERSON_MESSAGE);
        }

        $checkId = $this->intakeCheckIds->next();
        try {
            $this->intakeRequests->open($organizationId, $checkId, $requesterId, $draft);
        } catch (TooManyOpenIntakeChecks) {
            throw new IntakeCheckRefused(IntakeCheckRefused::TOO_MANY_AI_REQUESTS, self::TOO_MANY_OPEN_CHECKS_SENTENCE);
        }
        try {
            $this->intakeJobQueue->queue(new GenerateIntakeSuggestion($organizationId->toString(), $checkId->toString(), $requesterId->toString()));
        } catch (\Throwable $queueFailure) {
            try {
                $this->intakeRequests->release($organizationId, $checkId, $requesterId);
            } finally {
                // The queue's failure is the one the caller must see, also when the release fails too.
                throw $queueFailure;
            }
        }

        return $checkId;
    }

    public function checkOf(IntakeCheckId $checkId, UserId $viewerId, OrganizationId $organizationId): ?IntakeCheckView
    {
        // The state first: it is released only after the suggestion is committed, so one of the two always answers.
        $pending = $this->intakeRequests->stateOf($organizationId, $checkId, $viewerId);
        $suggestion = $this->suggestions->intakeOf($organizationId, $checkId, $viewerId);
        if (null === $pending && null === $suggestion) {
            return null;
        }
        if (!$this->requesters->isMember($viewerId, $organizationId)) {
            return null;
        }

        return null === $suggestion ? $pending : IntakeCheckView::done($suggestion);
    }

    /**
     * @throws IntakeCheckRefused when the project id is no canonical uuid, or a text is not UTF-8 or over its cap
     */
    private static function aiDraftOf(DraftReport $draft): AiDraft
    {
        if (!ListFilter::isCanonicalUuid($draft->projectId)) {
            throw new IntakeCheckRefused(IntakeCheckRefused::UNKNOWN_PROJECT, 'Choose a project first.');
        }
        // The pin count first, so an oversized list is refused before any note is read; the UTF-8 scans last.
        if (\count($draft->pinNotes) > AiDraft::MAX_PIN_NOTES) {
            throw self::tooLong(\sprintf('Too many pins for AI help: %d at most.', AiDraft::MAX_PIN_NOTES));
        }
        if (mb_strlen($draft->title, 'UTF-8') > AiDraft::MAX_TITLE_CHARACTERS) {
            throw self::tooLong(\sprintf('The title is too long for AI help: %d characters at most.', AiDraft::MAX_TITLE_CHARACTERS));
        }
        if (mb_strlen($draft->detail, 'UTF-8') > AiDraft::MAX_DETAIL_CHARACTERS) {
            throw self::tooLong(\sprintf('The details are too long for AI help: %s characters at most.', number_format(AiDraft::MAX_DETAIL_CHARACTERS)));
        }
        foreach ($draft->pinNotes as $pinNote) {
            if (mb_strlen($pinNote, 'UTF-8') > AiDraft::MAX_PIN_NOTE_CHARACTERS) {
                throw self::tooLong(\sprintf('A pin note is too long for AI help: %s characters at most.', number_format(AiDraft::MAX_PIN_NOTE_CHARACTERS)));
            }
        }
        if (!mb_check_encoding($draft->title, 'UTF-8') || !mb_check_encoding($draft->detail, 'UTF-8')) {
            throw self::unreadable();
        }
        foreach ($draft->pinNotes as $pinNote) {
            if (!mb_check_encoding($pinNote, 'UTF-8')) {
                throw self::unreadable();
            }
        }

        return new AiDraft($draft->projectId, $draft->title, $draft->detail, $draft->pinNotes);
    }

    private static function unreadable(): IntakeCheckRefused
    {
        return new IntakeCheckRefused(IntakeCheckRefused::UNREADABLE_DRAFT, 'This report has text AI help cannot read.');
    }

    private static function tooLong(string $sentence): IntakeCheckRefused
    {
        return new IntakeCheckRefused(IntakeCheckRefused::DRAFT_TOO_LONG, $sentence);
    }
}
