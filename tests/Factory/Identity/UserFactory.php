<?php

declare(strict_types=1);

namespace App\Tests\Factory\Identity;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Users of the neutral example company Acme Foods. Each default email gets the user id's last 12 digits, so two
 * default users never share one.
 *
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
    private const array NAMES = ['Lena', 'Omar', 'Mia', 'Raj', 'Sara'];

    public static function class(): string
    {
        return User::class;
    }

    protected function defaults(): array
    {
        $id = Uuid::v7()->toRfc4122();
        $name = self::NAMES[array_rand(self::NAMES)];

        return [
            'id' => new UserId($id),
            'email' => new Email(\sprintf('%s.%s@acme-foods.example', strtolower($name), substr($id, -12))),
            'name' => $name,
            'locale' => 'en',
            'timeZone' => 'Europe/Berlin',
            'createdAt' => new \DateTimeImmutable('2026-11-02 09:30:00 UTC'),
        ];
    }
}
