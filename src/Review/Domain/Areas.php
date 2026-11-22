<?php

declare(strict_types=1);

namespace App\Review\Domain;

/**
 * The screen areas of review rounds and the verdicts on them and on the earlier round's pins, and the verdicts on the
 * screenshots of shared drafts. Each method needs an
 * open transaction under the round's organization as tenant, and reads or writes that organization's rows only.
 */
interface Areas
{
    /**
     * The round, locked until the transaction ends, so verdicts in one round are given one after the other.
     *
     * @return ReviewedRound|null null when the tenant has no such round
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function lockRound(RoundId $roundId): ?ReviewedRound;

    /**
     * @return Area|null null when the round has no such area
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function areaOf(RoundId $roundId, AreaId $areaId): ?Area;

    /**
     * Writes the area's verdict, note, who decided and when.
     *
     * @throws \LogicException when no transaction is open, no tenant is set, or the tenant has no such area
     */
    public function saveVerdict(Area $area): void;

    /**
     * The pin, when it is on the "before" picture of one of the round's areas.
     *
     * @return PinToCheck|null null when no area of the round has it on its "before" picture
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function pinToCheckOf(RoundId $roundId, PinId $pinId): ?PinToCheck;

    /**
     * Writes $changed's verdict over the pin, only while the stored verdict is still $read's.
     *
     * @return bool false when the stored verdict is no longer $read's; nothing is written then
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function savePinVerdict(PinToCheck $read, PinToCheck $changed): bool;

    /**
     * Whether the screenshot is one of the round's and the latest try of its chain, the one the round page shows.
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function isShownScreenshotOf(RoundId $roundId, ScreenshotId $screenshotId): bool;

    /**
     * Writes the verdict in place of any earlier one on its screenshot.
     *
     * @throws \LogicException when no transaction is open or no tenant is set; a screenshot of another round fails the
     *                         database's foreign key, so the caller saves only one isShownScreenshotOf found
     */
    public function saveScreenshotVerdict(DraftScreenshotVerdict $verdict): void;
}
