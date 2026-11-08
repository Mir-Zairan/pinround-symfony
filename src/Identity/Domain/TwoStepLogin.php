<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\SharedKernel\Domain\UserId;

/**
 * A user's two-step login (TOTP, ADR-0012): the authenticator seed, encrypted, and once confirmed the hashes of the
 * recovery codes. It stays off until the user types a code their app made from the seed. One per user: the mapping
 * makes the user its id, so user_id is both the primary key and the foreign key to idn_user.
 */
final class TwoStepLogin
{
    private string $encryptedSeed;
    private ?\DateTimeImmutable $confirmedAt = null;
    /** @var list<string> */
    private array $recoveryCodeHashes = [];
    // The time step of the last code taken, so the same code (or an older one) never counts twice.
    private ?int $lastUsedStep = null;
    private \DateTimeImmutable $createdAt;

    /**
     * @param string $encryptedSeed the seed as FieldEncryption stored it, never the seed itself
     */
    public function __construct(private readonly User $user, #[\SensitiveParameter] string $encryptedSeed, \DateTimeImmutable $now)
    {
        $this->encryptedSeed = $encryptedSeed;
        $this->createdAt = $now;
    }

    public function userId(): UserId
    {
        return $this->user->id();
    }

    /**
     * Replaces the seed of a setup not confirmed yet, so only the newest QR code works.
     *
     * @param string $encryptedSeed the seed as FieldEncryption stored it
     *
     * @throws TwoStepLoginAlreadyOn when it is confirmed: a new seed would lock the user out of their app
     */
    public function start(#[\SensitiveParameter] string $encryptedSeed, \DateTimeImmutable $now): void
    {
        if ($this->isOn()) {
            throw new TwoStepLoginAlreadyOn('Two-step login is already on.');
        }

        $this->encryptedSeed = $encryptedSeed;
        $this->createdAt = $now;
    }

    /**
     * Turns two-step login on once the user typed a code of the seed.
     *
     * @param int          $matchedStep        the time step the typed code belongs to; it counts as used
     * @param list<string> $recoveryCodeHashes the hashes of the recovery codes the user is shown once
     *
     * @throws TwoStepLoginAlreadyOn     when it is confirmed already
     * @throws \InvalidArgumentException when there are no recovery code hashes
     */
    public function confirm(int $matchedStep, \DateTimeImmutable $now, #[\SensitiveParameter] array $recoveryCodeHashes): void
    {
        if ($this->isOn()) {
            throw new TwoStepLoginAlreadyOn('Two-step login is already on.');
        }
        if ([] === $recoveryCodeHashes) {
            throw new \InvalidArgumentException('Two-step login needs recovery codes.');
        }

        $this->confirmedAt = $now;
        $this->recoveryCodeHashes = $recoveryCodeHashes;
        $this->lastUsedStep = $matchedStep;
    }

    public function isOn(): bool
    {
        return null !== $this->confirmedAt;
    }

    /**
     * Takes a code at login: only when two-step login is on and the code's step is later than the last one taken.
     *
     * @param int $matchedStep the time step the typed code belongs to
     */
    public function acceptCode(int $matchedStep): bool
    {
        if (!$this->isOn() || (null !== $this->lastUsedStep && $matchedStep <= $this->lastUsedStep)) {
            return false;
        }

        $this->lastUsedStep = $matchedStep;

        return true;
    }

    /**
     * @return string the seed as FieldEncryption stored it
     */
    public function encryptedSeed(): string
    {
        return $this->encryptedSeed;
    }

    public function confirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    /**
     * @return list<string> empty until it is confirmed
     */
    public function recoveryCodeHashes(): array
    {
        return $this->recoveryCodeHashes;
    }

    public function lastUsedStep(): ?int
    {
        return $this->lastUsedStep;
    }

    /**
     * When the current seed was made.
     */
    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
