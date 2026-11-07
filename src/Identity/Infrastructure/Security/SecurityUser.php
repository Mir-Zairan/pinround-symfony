<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\Credential;
use App\Identity\Domain\User;
use App\Identity\Domain\UserStatus;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The logged-in person as Symfony Security sees them; the security token in the session holds it. The session gets a
 * crc32c of the password hash instead of the hash, so a changed password still ends other sessions (Symfony's
 * ContextListener compares the two) while no hash sits in Valkey.
 */
final class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    private function __construct(
        private string $id,
        private string $email,
        private string $name,
        private bool $isBlocked,
        private string $passwordHash,
    ) {
    }

    public static function of(User $user, Credential $credential): self
    {
        return new self(
            $user->id()->toString(),
            $user->email()->toString(),
            $user->name(),
            UserStatus::Blocked === $user->status(),
            $credential->passwordHash()->encoded(),
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * The full name; the page header reads it through `app.user.name` for the avatar.
     */
    public function name(): string
    {
        return $this->name;
    }

    public function isBlocked(): bool
    {
        return $this->isBlocked;
    }

    public function getUserIdentifier(): string
    {
        if ('' === $this->email) {
            throw new \LogicException('A security user always has an email.');
        }

        return $this->email;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    /**
     * The encoded hash; after the user came back from the session, the crc32c of it.
     */
    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    /**
     * @return array{id: string, email: string, name: string, isBlocked: bool, passwordHash: string}
     */
    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'isBlocked' => $this->isBlocked,
            'passwordHash' => hash('crc32c', $this->passwordHash),
        ];
    }

    /**
     * @param array{id: string, email: string, name: string, isBlocked: bool, passwordHash: string} $data
     */
    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->email = $data['email'];
        $this->name = $data['name'];
        $this->isBlocked = $data['isBlocked'];
        $this->passwordHash = $data['passwordHash'];
    }
}
