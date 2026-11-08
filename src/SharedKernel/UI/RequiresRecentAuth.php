<?php

declare(strict_types=1);

namespace App\SharedKernel\UI;

/**
 * Marks a controller class or action that opens only when the logged-in person proved their password and a two-step
 * login code in this session within the last 10 minutes (ADR-0012). Identity enforces it and otherwise sends the
 * person to "Confirm it's you", or to set up two-step login first. It is here, not in Identity's Api, because every
 * module that marks a sensitive action may see SharedKernel. It adds nothing to the access check of the route: the
 * action still asks its voter.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class RequiresRecentAuth
{
}
