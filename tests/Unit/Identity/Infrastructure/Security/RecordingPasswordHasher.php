<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * Verifies nothing: records each call and answers "wrong password".
 */
final class RecordingPasswordHasher implements PasswordHasherInterface
{
    /** @var list<string> the hashes it made */
    public array $hashes = [];

    /** @var list<array{hashedPassword: string, plainPassword: string}> */
    public array $verifiedPasswords = [];

    public function hash(#[\SensitiveParameter] string $plainPassword): string
    {
        return $this->hashes[] = '$argon2id$v=19$m=10,t=3,p=1$throwaway'.\count($this->hashes);
    }

    public function verify(string $hashedPassword, #[\SensitiveParameter] string $plainPassword): bool
    {
        $this->verifiedPasswords[] = ['hashedPassword' => $hashedPassword, 'plainPassword' => $plainPassword];

        return false;
    }

    public function needsRehash(string $hashedPassword): bool
    {
        return false;
    }
}
