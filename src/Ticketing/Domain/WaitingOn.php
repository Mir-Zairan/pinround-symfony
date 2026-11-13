<?php

declare(strict_types=1);

namespace App\Ticketing\Domain;

use App\SharedKernel\Domain\UserId;

/**
 * Who a ticket in Needs input waits on: a person, or a label such as "Customer" when that is no user (FR-WF-15).
 */
final readonly class WaitingOn
{
    public const int LABEL_MAX_LENGTH = 100;

    private function __construct(private ?UserId $userId, private ?string $label)
    {
    }

    public static function ofPerson(UserId $userId): self
    {
        return new self($userId, null);
    }

    /**
     * @throws InvalidWaitingOn when nothing is left of the label after trimming, it has more than 100 characters, or
     *                          it holds a control character, line break or byte order mark, or is not UTF-8
     */
    public static function ofLabel(string $label): self
    {
        $trimmedLabel = trim($label);
        if ('' === $trimmedLabel) {
            throw new InvalidWaitingOn('A ticket waits on a person or a label; the label is blank.');
        }
        if (mb_strlen($trimmedLabel) > self::LABEL_MAX_LENGTH) {
            throw new InvalidWaitingOn('A waiting-on label has at most 100 characters.');
        }
        // No control characters, line breaks (U+2028 included) or byte order mark: the label is shown in one line. Invalid
        // UTF-8 fails too.
        if (1 !== preg_match('/\A[^\p{Cc}\p{Zl}\p{Zp}\x{FEFF}]*\z/u', $trimmedLabel)) {
            throw new InvalidWaitingOn('A waiting-on label is one line of text.');
        }

        return new self(null, $trimmedLabel);
    }

    /**
     * Null when the ticket waits on a label.
     */
    public function userId(): ?UserId
    {
        return $this->userId;
    }

    /**
     * Null when the ticket waits on a person.
     */
    public function label(): ?string
    {
        return $this->label;
    }
}
