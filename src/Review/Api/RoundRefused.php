<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\UserId;

/**
 * The round's content breaks a rule, such as a title over 120 characters, or the ticket has that round already, so
 * nothing was saved. The message is the reason to show the person (422), except NOT_FOUND, which the caller answers as
 * a missing page (404).
 *
 * A caller that answers by kind rather than by text asks isNotFound, isReadOnly, isInvalidContent and isSharingBlocked;
 * RoundCommands sets the kind where it refuses, so no caller has to read the exception it wraps. A refusal of none of
 * these kinds, such as DRAFT_CHANGED or NUMBER_TAKEN, answers false to all four.
 */
final class RoundRefused extends \DomainException
{
    /**
     * The reason while another shared round on the ticket is open (ADR-0048); %d is its number. It names nobody, as
     * Review does not know names: a caller that does may say who gives the verdict from readyForUserId.
     */
    public const string ROUND_STILL_OPEN = 'Round %d is still open. Wait for its verdict before you share.';
    /** A picture the person did not upload: another person's, another organization's, an unknown or a malformed id. */
    public const string MEDIA_NOT_UPLOADED_BY_YOU = 'Add a screenshot you uploaded yourself.';
    public const string MEDIA_STILL_PROCESSING = 'Still processing your screenshot. Try again in a moment.';
    /** The text the Screenshot component shows on a rejected picture. */
    public const string MEDIA_REJECTED = 'We could not process this image. Try again.';

    /**
     * No draft round of the person with that id: an unknown or malformed id, another organization's round, another
     * person's draft, a round that is not a draft, or a deleted draft. One answer for all, so nobody learns that
     * another person's draft exists (ADR-0017).
     */
    public const string NOT_FOUND = 'That draft round does not exist.';
    public const string DRAFT_EXISTS = 'You have a draft round on this ticket already.';
    /** Another request saved the draft between this one's read and its save. */
    public const string DRAFT_CHANGED = 'This draft changed in another tab. Reload and try again.';
    /** An unknown or malformed screenshot id, or one of another round; the same text as Round's refusal. */
    public const string SCREENSHOT_NOT_FOUND = 'This draft round has no such screenshot.';
    public const string UNKNOWN_VERDICT = 'Choose "Looks right" or "Not right yet".';
    /** An unknown or malformed pin id, or one of another round; the same text as Round's refusal. */
    public const string PIN_NOT_FOUND = 'This draft round has no such pin.';
    public const string UNKNOWN_PIN_VERDICT = 'Mark the pin fixed or still wrong.';
    /** A round saved without RoundCommands took the number while the draft was being shared. */
    public const string NUMBER_TAKEN = 'Another round took this number just now. Try again.';

    private const string KIND_READ_ONLY = 'read_only';
    private const string KIND_INVALID_CONTENT = 'invalid_content';
    private const string KIND_SHARING_BLOCKED = 'sharing_blocked';

    private ?string $kind = null;
    private ?int $openRoundNumber = null;
    private ?UserId $readyForUserId = null;

    /**
     * The draft no longer changes: it is shared or closed. Sending the change again cannot help.
     *
     * @param string $reason the text to show the person, the domain's own
     */
    public static function readOnly(string $reason, ?\Throwable $cause = null): self
    {
        $refused = new self($reason, 0, $cause);
        $refused->kind = self::KIND_READ_ONLY;

        return $refused;
    }

    /**
     * What was sent breaks a rule of the round's content, such as a text over its cap, a pin outside the picture or an
     * unknown verdict; other content may pass.
     *
     * @param string $reason the text to show the person, the domain's own
     */
    public static function invalidContent(string $reason, ?\Throwable $cause = null): self
    {
        $refused = new self($reason, 0, $cause);
        $refused->kind = self::KIND_INVALID_CONTENT;

        return $refused;
    }

    /**
     * The draft may not be shared while round $openRoundNumber of the ticket is open (ADR-0048).
     *
     * @param int         $openRoundNumber the open round's number, from 1
     * @param UserId|null $readyForUserId  whose verdict the round waits for, when Review knows it: the assignee for a
     *                                     findings round; null for any other round, whose person is whoever's turn it
     *                                     is now, which the caller reads from the turn
     */
    public static function roundStillOpen(int $openRoundNumber, ?UserId $readyForUserId): self
    {
        $refused = new self(\sprintf(self::ROUND_STILL_OPEN, $openRoundNumber));
        $refused->kind = self::KIND_SHARING_BLOCKED;
        $refused->openRoundNumber = $openRoundNumber;
        $refused->readyForUserId = $readyForUserId;

        return $refused;
    }

    /**
     * The draft may not be shared as it is, but may still be kept private, such as a draft with no screenshot
     * (ADR-0048).
     *
     * @param string $reason the text to show the person, the domain's own
     */
    public static function sharingBlocked(string $reason, ?\Throwable $cause = null): self
    {
        $refused = new self($reason, 0, $cause);
        $refused->kind = self::KIND_SHARING_BLOCKED;

        return $refused;
    }

    /**
     * NOT_FOUND, SCREENSHOT_NOT_FOUND or PIN_NOT_FOUND: no draft of the person with that id, or no such picture or pin
     * in it.
     */
    public function isNotFound(): bool
    {
        return \in_array($this->getMessage(), [self::NOT_FOUND, self::SCREENSHOT_NOT_FOUND, self::PIN_NOT_FOUND], true);
    }

    public function isReadOnly(): bool
    {
        return self::KIND_READ_ONLY === $this->kind;
    }

    public function isInvalidContent(): bool
    {
        return self::KIND_INVALID_CONTENT === $this->kind;
    }

    /**
     * Sharing waits: another shared round of the ticket is open, or the draft has no screenshot. The draft stays
     * unshared and unnumbered, and may still be kept private, so a hand-off offers only "Keep private and move".
     */
    public function isSharingBlocked(): bool
    {
        return self::KIND_SHARING_BLOCKED === $this->kind;
    }

    /**
     * The number of the open round that blocks the share; null for any other refusal.
     */
    public function openRoundNumber(): ?int
    {
        return $this->openRoundNumber;
    }

    /**
     * Whose verdict the open round waits for, when Review knows it (see roundStillOpen); null otherwise.
     */
    public function readyForUserId(): ?UserId
    {
        return $this->readyForUserId;
    }
}
