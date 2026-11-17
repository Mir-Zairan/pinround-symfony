<?php

declare(strict_types=1);

namespace App\Assist\Api;

/**
 * "Summarize with AI" was refused before anything was queued: the organization's AI policy says no for this person or
 * ticket, or a summary of the ticket is already on its way. The message is the one line the person is shown.
 */
final class SummaryRequestRefused extends \DomainException
{
    /** The code of a refusal because a summary of the ticket is already queued. */
    public const string ALREADY_QUEUED = 'already_queued';

    /**
     * @param string $refusalCode a PolicyView refusal code (ai_off, leads_only, cannot_see_ticket,
     *                            restricted_ticket_to_cloud, laptop_not_ready) or ALREADY_QUEUED
     * @param string $sentence    the line shown where the button is
     */
    public function __construct(public readonly string $refusalCode, string $sentence)
    {
        parent::__construct($sentence);
    }

    public static function alreadyQueued(): self
    {
        return new self(self::ALREADY_QUEUED, 'A summary is already on its way.');
    }
}
