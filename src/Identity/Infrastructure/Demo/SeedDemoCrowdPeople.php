<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Demo;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Identity\Domain\Users;
use App\SharedKernel\Infrastructure\Console\DemoSeedStep;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * COUNT made-up people for the big Crowd team of Beta Bakery, so the person picker's server-side search and capped
 * page can be tried with thousands of people (tests/e2e/person-picker.spec.ts). Each is named "Person0001" and so on,
 * one word, because the picker searches first names and a first name is the first word of the name. They have no
 * password and cannot log in. SeedDemoCrowdTeam adds them to the team.
 *
 * The crowd steps run in dev only, where make seed fills the stack the Playwright specs use: in test, every
 * DemoSeedCommandTest run would make 2,000 people and its exact lists of users would no longer hold.
 */
#[AsTaggedItem(priority: 90)]
#[When('dev')]
final readonly class SeedDemoCrowdPeople implements DemoSeedStep
{
    public const int COUNT = 2000;
    private const int BATCH_SIZE = 200;

    public function __construct(
        private Users $users,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<string> the email of each crowd person, in number order
     */
    public static function emails(): array
    {
        return array_map(static fn (int $number): string => \sprintf('person%04d@beta-bakery.example', $number), range(1, self::COUNT));
    }

    public function seed(): string
    {
        $created = 0;
        foreach (self::emails() as $index => $email) {
            if (null === $this->users->byEmail(new Email($email))) {
                $name = \sprintf('Person%04d', $index + 1);
                $this->users->add(new User($this->users->nextIdentity(), new Email($email), $name, 'en', 'Europe/Berlin', $this->clock->now()));
                ++$created;
            }
            // Each add flushes the whole unit of work, so it is kept small.
            if (0 === ($index + 1) % self::BATCH_SIZE) {
                $this->entityManager->clear();
            }
        }
        $this->entityManager->clear();

        return \sprintf('Crowd people: created %d, kept %d.', $created, self::COUNT - $created);
    }
}
