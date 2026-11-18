<?php

declare(strict_types=1);

namespace App\Media\Api;

/**
 * An upload was refused for a reason the person can act on; the message is plain text to show them as it is.
 */
final class UploadRefused extends \DomainException
{
    public const string ADDING_TOO_FAST = 'You are adding files too fast. Wait a minute and try again.';

    /**
     * @param int|null $retryAfterSeconds how long to wait before the next try, 1 or more, for a refusal that ends by
     *                                    itself; null for one that waiting does not end
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @param int $retryAfterSeconds until the person's begin window ends, 1 or more
     */
    public static function addingTooFast(int $retryAfterSeconds): self
    {
        return new self(self::ADDING_TOO_FAST, retryAfterSeconds: $retryAfterSeconds);
    }
}
