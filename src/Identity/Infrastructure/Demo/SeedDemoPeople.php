<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Demo;

use App\Identity\Domain\Credential;
use App\Identity\Domain\Credentials;
use App\Identity\Domain\Email;
use App\Identity\Domain\NewPassword;
use App\Identity\Domain\PasswordHashing;
use App\Identity\Domain\PasswordTooShort;
use App\Identity\Domain\User;
use App\Identity\Domain\Users;
use App\SharedKernel\Application\DemoSeedRefused;
use App\SharedKernel\Application\DemoSeedStep;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The five people of the neutral example company Acme Foods, all with the password in DEMO_PASSWORD. Organizations
 * and teams come from later steps, which run after this one.
 */
#[AsTaggedItem(priority: 100)]
final readonly class SeedDemoPeople implements DemoSeedStep
{
    private const array NAMES = ['Lena', 'Omar', 'Mia', 'Raj', 'Sara'];

    public function __construct(
        private Users $users,
        private Credentials $credentials,
        private PasswordHashing $passwordHashing,
        private ClockInterface $clock,
        #[Autowire(env: 'default::DEMO_PASSWORD')]
        #[\SensitiveParameter]
        private ?string $demoPassword,
    ) {
    }

    public function seed(): string
    {
        $password = $this->password();
        $created = [];
        $kept = [];

        foreach (self::NAMES as $name) {
            $email = new Email(\sprintf('%s@acme-foods.example', strtolower($name)));
            $user = $this->users->byEmail($email);
            $isNew = null === $user;
            if ($isNew) {
                $user = new User($this->users->nextIdentity(), $email, $name, 'en', 'Europe/Berlin', $this->clock->now());
                $this->users->add($user);
            }
            if (null === $this->credentials->byUserId($user->id())) {
                $this->credentials->add(new Credential($user, $this->passwordHashing->hash($password), $this->clock->now()));
            }
            if ($isNew) {
                $created[] = $name;
            } else {
                $kept[] = $name;
            }
        }

        return \sprintf('People: created %d (%s), kept %d (%s).', \count($created), implode(', ', $created), \count($kept), implode(', ', $kept));
    }

    private function password(): NewPassword
    {
        if (null === $this->demoPassword || '' === $this->demoPassword) {
            throw new DemoSeedRefused('DEMO_PASSWORD is not set. Set it to a password of at least 12 characters, for example in .env.dev.local.');
        }

        try {
            return new NewPassword($this->demoPassword);
        } catch (PasswordTooShort) {
            throw new DemoSeedRefused(\sprintf('DEMO_PASSWORD needs at least %d characters.', NewPassword::MINIMUM_LENGTH));
        }
    }
}
