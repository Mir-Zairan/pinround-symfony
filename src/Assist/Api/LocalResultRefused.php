<?php

declare(strict_types=1);

namespace App\Assist\Api;

/**
 * A laptop's result was not kept: the package is unknown (or another person's or another organization's, which reads
 * the same), it was used or it expired, the AI policy no longer gives the poster laptop AI, or the model name or the
 * text failed the check. The package stays as it was, and the refusal is recorded in the audit log as
 * local_ai.result_refused (LocalAi::accept). The message is the one line the person is shown; it never holds the text.
 */
final class LocalResultRefused extends \DomainException
{
    /** No package of this person in this organization has the id; a page answers it with 404. */
    public const string PACKAGE_NOT_FOUND = 'package_not_found';

    /** The package took a result already or expired; a page answers it with 410. */
    public const string PACKAGE_GONE = 'package_gone';

    /** The model name or the text failed the check; a page answers it with 422. */
    public const string RESULT_REJECTED = 'result_rejected';

    private function __construct(public readonly string $refusalCode, string $sentence)
    {
        parent::__construct($sentence);
    }

    public static function packageNotFound(): self
    {
        return new self(self::PACKAGE_NOT_FOUND, 'This laptop AI request does not exist.');
    }

    public static function packageGone(): self
    {
        return new self(self::PACKAGE_GONE, 'This laptop AI request was used or ran out of time. Ask again.');
    }

    /**
     * @param string $reason the rule the result broke, never the result itself
     */
    public static function resultRejected(string $reason): self
    {
        return new self(self::RESULT_REJECTED, $reason);
    }

    /**
     * The organization's AI policy no longer gives the poster laptop AI; a page answers it with 403.
     *
     * @param string $refusalCode a PolicyView refusal code: ai_off, leads_only or cloud_only
     * @param string $sentence    the line shown where the button is
     */
    public static function notAllowed(string $refusalCode, string $sentence): self
    {
        return new self($refusalCode, $sentence);
    }
}
