<?php

declare(strict_types=1);

namespace App\Review\Application\Verdict;

use App\Review\Api\Event\AreaVerdictSaved;
use App\Review\Api\Event\PinVerdictSet;
use App\Review\Api\Event\PinVerdictUndone;
use App\Review\Api\Event\ScreenshotVerdictSaved;
use App\Review\Api\VerdictCommands;
use App\Review\Api\VerdictRefused;
use App\Review\Application\ReviewEvents;
use App\Review\Domain\Area;
use App\Review\Domain\AreaId;
use App\Review\Domain\Areas;
use App\Review\Domain\AreaVerdict;
use App\Review\Domain\DraftScreenshotVerdict;
use App\Review\Domain\PinId;
use App\Review\Domain\PinToCheck;
use App\Review\Domain\PinVerdict;
use App\Review\Domain\ReviewedRound;
use App\Review\Domain\RoundId;
use App\Review\Domain\RoundKind;
use App\Review\Domain\ScreenshotId;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\UserId;

/**
 * VerdictCommands on Areas; the contract is on {@see VerdictCommands}. Every command locks the round before it reads
 * the pin or area it changes, so verdicts in one round are given one after the other.
 */
final readonly class UseCaseVerdictCommands implements VerdictCommands
{
    public function __construct(
        private Areas $areas,
        private ReviewEvents $reviewEvents,
        private TenantContext $tenantContext,
    ) {
    }

    public function setPinVerdict(string $roundId, string $pinId, UserId $actor, string $verdict, \DateTimeImmutable $at): void
    {
        $organizationId = $this->tenantContext->require();
        $round = $this->lockOpenRound($roundId);
        $pin = $this->pinToCheckOf($round, $pinId);
        $pinVerdict = match ($verdict) {
            self::PIN_FIXED => PinVerdict::Fixed,
            self::PIN_STILL_WRONG => PinVerdict::StillWrong,
            default => throw new VerdictRefused(VerdictRefused::UNKNOWN_PIN_VERDICT),
        };

        $this->savePinVerdict($pin, static fn (PinToCheck $read): PinToCheck => $read->judge($pinVerdict));

        $this->reviewEvents->publish(new PinVerdictSet(
            $organizationId->toString(),
            $round->ticketId->toString(),
            $round->id->toString(),
            $pin->id()->toString(),
            $pinVerdict->value,
            $actor->toString(),
            $at,
        ));
    }

    public function undoPinVerdict(string $roundId, string $pinId, UserId $actor, \DateTimeImmutable $at): void
    {
        $organizationId = $this->tenantContext->require();
        $round = $this->lockOpenRound($roundId);
        $pin = $this->pinToCheckOf($round, $pinId);

        $this->savePinVerdict($pin, static fn (PinToCheck $read): PinToCheck => $read->undo());

        $this->reviewEvents->publish(new PinVerdictUndone(
            $organizationId->toString(),
            $round->ticketId->toString(),
            $round->id->toString(),
            $pin->id()->toString(),
            // undo() refuses a pin with no verdict, so the one read has one.
            $pin->verdict()->value ?? throw new \LogicException('An undone pin verdict was none.'),
            $actor->toString(),
            $at,
        ));
    }

    public function saveAreaVerdict(string $roundId, string $areaId, UserId $actor, ?string $verdict, ?string $note, \DateTimeImmutable $at): void
    {
        $organizationId = $this->tenantContext->require();
        $round = $this->lockOpenRound($roundId);
        $area = $this->areaOf($round, $areaId);
        $areaVerdict = self::areaVerdictOf($verdict);

        try {
            $decided = $area->decide($actor, $areaVerdict, $note, $at);
        } catch (\DomainException|\InvalidArgumentException $refused) {
            throw new VerdictRefused($refused->getMessage(), 0, $refused);
        }
        $this->areas->saveVerdict($decided);

        $this->reviewEvents->publish(new AreaVerdictSaved(
            $organizationId->toString(),
            $round->ticketId->toString(),
            $round->id->toString(),
            $decided->id()->toString(),
            $areaVerdict->value,
            null !== $decided->note(),
            $actor->toString(),
            $at,
        ));
    }

    public function saveScreenshotVerdict(string $roundId, string $screenshotId, UserId $actor, ?string $verdict, ?string $note, \DateTimeImmutable $at): void
    {
        $organizationId = $this->tenantContext->require();
        $round = $this->lockOpenRound($roundId);
        if (RoundKind::Draft !== $round->kind) {
            // A review round's screenshots are its areas', which take saveAreaVerdict.
            throw new VerdictRefused(VerdictRefused::SCREENSHOT_NOT_FOUND);
        }
        try {
            $id = new ScreenshotId($screenshotId);
        } catch (\InvalidArgumentException) {
            throw new VerdictRefused(VerdictRefused::SCREENSHOT_NOT_FOUND);
        }
        if (!$this->areas->isShownScreenshotOf($round->id, $id)) {
            throw new VerdictRefused(VerdictRefused::SCREENSHOT_NOT_FOUND);
        }

        try {
            $decided = DraftScreenshotVerdict::decide($round->id, $id, $actor, self::areaVerdictOf($verdict), $note, $at);
        } catch (\DomainException|\InvalidArgumentException $refused) {
            throw new VerdictRefused($refused->getMessage(), 0, $refused);
        }
        $this->areas->saveScreenshotVerdict($decided);

        $this->reviewEvents->publish(new ScreenshotVerdictSaved(
            $organizationId->toString(),
            $round->ticketId->toString(),
            $round->id->toString(),
            $id->toString(),
            $decided->verdict->value,
            null !== $decided->note,
            $actor->toString(),
            $at,
        ));
    }

    /**
     * @throws VerdictRefused PICK_A_VERDICT for no verdict or one that is not an area verdict
     */
    private static function areaVerdictOf(?string $verdict): AreaVerdict
    {
        return match ($verdict) {
            self::AREA_PASS => AreaVerdict::Pass,
            self::AREA_NEEDS_FIX => AreaVerdict::NeedsFix,
            self::AREA_QUESTION => AreaVerdict::Question,
            default => throw new VerdictRefused(VerdictRefused::PICK_A_VERDICT),
        };
    }

    /**
     * @throws VerdictRefused NOT_FOUND when the id is malformed or the tenant has no such round; otherwise the reason
     *                        the round takes no verdicts
     */
    private function lockOpenRound(string $roundId): ReviewedRound
    {
        try {
            $id = new RoundId($roundId);
        } catch (\InvalidArgumentException) {
            throw new VerdictRefused(VerdictRefused::NOT_FOUND);
        }
        $round = $this->areas->lockRound($id) ?? throw new VerdictRefused(VerdictRefused::NOT_FOUND);
        try {
            $round->requireOpenForVerdicts();
        } catch (\DomainException $refused) {
            throw new VerdictRefused($refused->getMessage(), 0, $refused);
        }

        return $round;
    }

    /**
     * @throws VerdictRefused PIN_NOT_FOUND when the id is malformed or no area of the round has the pin to check
     */
    private function pinToCheckOf(ReviewedRound $round, string $pinId): PinToCheck
    {
        try {
            $id = new PinId($pinId);
        } catch (\InvalidArgumentException) {
            throw new VerdictRefused(VerdictRefused::PIN_NOT_FOUND);
        }

        return $this->areas->pinToCheckOf($round->id, $id) ?? throw new VerdictRefused(VerdictRefused::PIN_NOT_FOUND);
    }

    /**
     * @throws VerdictRefused AREA_NOT_FOUND when the id is malformed or the round has no such area
     */
    private function areaOf(ReviewedRound $round, string $areaId): Area
    {
        try {
            $id = new AreaId($areaId);
        } catch (\InvalidArgumentException) {
            throw new VerdictRefused(VerdictRefused::AREA_NOT_FOUND);
        }

        return $this->areas->areaOf($round->id, $id) ?? throw new VerdictRefused(VerdictRefused::AREA_NOT_FOUND);
    }

    /**
     * @param \Closure(PinToCheck): PinToCheck $change
     *
     * @throws VerdictRefused with the domain's reason, or PIN_CHANGED when the stored verdict moved since it was read;
     *                        nothing is saved
     */
    private function savePinVerdict(PinToCheck $read, \Closure $change): void
    {
        try {
            $changed = $change($read);
        } catch (\DomainException $refused) {
            throw new VerdictRefused($refused->getMessage(), 0, $refused);
        }
        if (!$this->areas->savePinVerdict($read, $changed)) {
            throw new VerdictRefused(VerdictRefused::PIN_CHANGED);
        }
    }
}
