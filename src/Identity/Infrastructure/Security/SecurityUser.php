<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\Credential;
use App\Identity\Domain\Theme;
use App\Identity\Domain\User;
use App\Identity\Domain\UserStatus;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The logged-in person as Symfony Security sees them; the security token in the session holds it. The session gets a
 * crc32c of the password hash instead of the hash, so no hash sits in Valkey. On every request Symfony's
 * ContextListener asks the user from the session whether it equals the freshly loaded one (isEqualTo); a changed
 * password or a rotated session stamp makes them differ, which logs that session out.
 */
final class SecurityUser implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    private function __construct(
        private string $id,
        private string $email,
        private string $name,
        private bool $isBlocked,
        private string $passwordHash,
        private string $sessionStamp,
        private Theme $theme,
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
            $user->sessionStamp(),
            $user->theme(),
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

    /**
     * The Theme's value, for the page's data-theme through `app.user.theme`.
     */
    public function theme(): string
    {
        return $this->theme->value;
    }

    public function sessionStamp(): string
    {
        return $this->sessionStamp;
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
     * Called on the user from the session with the freshly loaded one. Its password is the crc32c of the hash then,
     * so the refreshed hash is compared through its crc32c too.
     */
    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self) {
            return false;
        }

        $isSamePassword = $user->passwordHash === $this->passwordHash
            || hash('crc32c', $user->passwordHash) === $this->passwordHash;

        return $isSamePassword
            && $user->id === $this->id
            && $user->email === $this->email
            && hash_equals($this->sessionStamp, $user->sessionStamp);
    }

    /**
     * @return array{id: string, email: string, name: string, isBlocked: bool, passwordHash: string, sessionStamp: string, theme: string}
     */
    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'isBlocked' => $this->isBlocked,
            'passwordHash' => hash('crc32c', $this->passwordHash),
            'sessionStamp' => $this->sessionStamp,
            'theme' => $this->theme->value,
        ];
    }

    /**
     * A session saved before the stamp existed comes back with an empty one, which equals no user, so it is logged out.
     * A missing or unknown theme comes back as System.
     *
     * @param array{id: string, email: string, name: string, isBlocked: bool, passwordHash: string, sessionStamp?: string, theme?: string} $data
     */
    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->email = $data['email'];
        $this->name = $data['name'];
        $this->isBlocked = $data['isBlocked'];
        $this->passwordHash = $data['passwordHash'];
        $this->sessionStamp = $data['sessionStamp'] ?? '';
        $this->theme = Theme::tryFrom($data['theme'] ?? '') ?? Theme::System;
    }
}
