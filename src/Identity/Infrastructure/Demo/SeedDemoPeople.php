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
use App\SharedKernel\Infrastructure\Console\DemoSeedRefused;
use App\SharedKernel\Infrastructure\Console\DemoSeedStep;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The five people of the neutral example company Acme Foods and Ben of Beta Bakery, all with the password in
 * DEMO_PASSWORD. Organizations and teams come from later steps, which run after this one.
 */
#[AsTaggedItem(priority: 100)]
final readonly class SeedDemoPeople implements DemoSeedStep
{
    /** Email domain by name: Ben belongs to a second company, so the organization picker can be tried. */
    private const array PEOPLE = [
        'Lena' => 'acme-foods.example',
        'Omar' => 'acme-foods.example',
        'Mia' => 'acme-foods.example',
        'Raj' => 'acme-foods.example',
        'Sara' => 'acme-foods.example',
        'Ben' => 'beta-bakery.example',
    ];

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
        $gotPassword = [];
        $kept = [];

        foreach (self::PEOPLE as $name => $domain) {
            $email = new Email(\sprintf('%s@%s', strtolower($name), $domain));
            $user = $this->users->byEmail($email);
            $isNew = null === $user;
            if ($isNew) {
                $user = new User($this->users->nextIdentity(), $email, $name, 'en', 'Europe/Berlin', $this->clock->now());
                $this->users->add($user);
            }
            $needsCredential = null === $this->credentials->byUserId($user->id());
            if ($needsCredential) {
                $this->credentials->add(new Credential($user, $this->passwordHashing->hash($password), $this->clock->now()));
            }
            if ($isNew) {
                $created[] = $name;
            } elseif ($needsCredential) {
                $gotPassword[] = $name;
            } else {
                $kept[] = $name;
            }
        }

        return \sprintf(
            'People: created %d (%s), added a password to %d (%s), kept %d (%s).',
            \count($created),
            implode(', ', $created),
            \count($gotPassword),
            implode(', ', $gotPassword),
            \count($kept),
            implode(', ', $kept),
        );
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
