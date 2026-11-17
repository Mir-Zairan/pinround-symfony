<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * The pin numbers and area names the ticket's history shows for a Review event, which carries ids only (ADR-0027).
 * Review implements it, so Ticketing reads them without depending on Review. Each method runs in the caller's open
 * transaction under the event's organization as tenant, and answers null for an id that is malformed, unknown or of
 * another organization, so the history names "a pin" or "an area" instead.
 */
interface ReviewChangeLabels
{
    /**
     * @param string $itemId the rvw_check_item id
     *
     * @return int|null the number of the item's pin, from 1
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function checkItemPinNumberOf(string $itemId): ?int;

    /**
     * @param string $roundId the review round the verdict was given or undone in
     * @param string $pinId   the rvw_pin id of the earlier round's pin
     *
     * @return ReviewedPin|null null as well when no area of the round compares the pin's picture
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function reviewedPinOf(string $roundId, string $pinId): ?ReviewedPin;

    /**
     * @param string $areaId the rvw_area id
     *
     * @throws \LogicException when no transaction is open or no tenant is set
     */
    public function areaNameOf(string $areaId): ?string;
}
