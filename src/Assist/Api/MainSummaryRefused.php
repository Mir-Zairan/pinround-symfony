<?php

declare(strict_types=1);

namespace App\Assist\Api;

/**
 * A laptop's result was not made the ticket's main summary (LocalAi::makeMainSummary). The message is the one line the
 * person is shown; it never holds the text.
 */
final class MainSummaryRefused extends \DomainException
{
    /** The result is not the ticket's proposal: unknown, another ticket's, made the main summary already, or older. */
    public const string NOT_A_PROPOSAL = 'not_a_proposal';

    /** Ticketing refused the text, model or prompt version as a summary. */
    public const string SUMMARY_REJECTED = 'summary_rejected';

    private function __construct(public readonly string $refusalCode, string $sentence)
    {
        parent::__construct($sentence);
    }

    public static function notAProposal(): self
    {
        return new self(self::NOT_A_PROPOSAL, 'This laptop summary is no longer offered. Reload the page.');
    }

    /**
     * @param string $reason the summary rule the result broke, never the result itself
     */
    public static function summaryRejected(string $reason): self
    {
        return new self(self::SUMMARY_REJECTED, $reason);
    }
}
