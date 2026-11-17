<?php

declare(strict_types=1);

namespace App\Assist\Api;

/**
 * A laptop's result was not kept, and nothing was stored: the package is unknown (or another person's or another
 * organization's, which reads the same), it was used or it expired, or the model name or the text failed the check.
 * The message is the one line the person is shown; it never holds the text.
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
}
