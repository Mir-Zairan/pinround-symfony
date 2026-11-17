<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Identity\Domain\User;
use App\Kernel;
use App\Media\Api\ReadyPictureSeeds;
use App\Media\Infrastructure\Demo\SeedReadyPicture;
use App\Review\Infrastructure\Console\SeedDraftWithPictureCommand;
use App\Review\Infrastructure\Console\SeedReviewRoundCommand;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\KernelInterface;
use Zenstruck\Foundry\Test\Factories;

final class SeedDraftWithPictureCommandTest extends KernelTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use SeedsAssignedDemoTicket;
    use SeedsTicketRounds;

    private const string COMMAND = 'app:e2e:seed-draft-with-picture';
    private const string REVIEW_ROUND_COMMAND = 'app:e2e:seed-review-round';

    /** Read by SeedsTicketRounds. */
    protected string $shopProject;
    private string $stepId;

    /** @var array<string, User> */
    private array $people;

    private KernelInterface $testKernel;

    protected function setUp(): void
    {
        $this->testKernel = self::bootKernel();
    }

    protected function tearDown(): void
    {
        $this->removeSeededRounds();
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    public function testItRefusesAnUnknownOrganizationAndWritesNothing(): void
    {
        $this->seedAssignedTicket();
        $before = $this->rowCounts();
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['organization' => 'no-such-organization', 'ticket' => 'SHOP-1']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('That organization does not exist.', $tester->getDisplay());
        self::assertSame(['rounds' => 0, 'media' => 0, 'screenshots' => 0], $before);
        self::assertSame($before, $this->rowCounts());
    }

    public function testItGivesTheAssigneeAnUndecidedDraftWithOneReadyPictureOfTheOrganization(): void
    {
        $ticketId = $this->seedAssignedTicket();
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['organization' => 'acme-foods', 'ticket' => 'SHOP-1']);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertSame(['rounds' => 1, 'media' => 1, 'screenshots' => 1], $this->rowCounts());
        $rounds = $this->roundRowsOf($ticketId);
        self::assertCount(1, $rounds);
        self::assertSame('draft', $rounds[0]['kind']);
        self::assertSame($this->people['Omar']->id()->toString(), $rounds[0]['owner_id']);
        self::assertNull($rounds[0]['number']);
        self::assertNull($rounds[0]['decision']);
        $pictures = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use (&$pictures): int {
            $pictures = $migrator->fetchAllAssociative(
                'SELECT m.status, m.organization_id FROM rvw_screenshot s JOIN med_object m ON m.organization_id = s.organization_id AND m.id = s.media_id',
            );

            return 0;
        });
        self::assertSame([['status' => 'ready', 'organization_id' => $this->acmeFoods->toString()]], $pictures);
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function devOnlyClasses(): iterable
    {
        yield 'the command' => [SeedDraftWithPictureCommand::class];
        yield 'the review round command' => [SeedReviewRoundCommand::class];
        yield 'the ready picture seed' => [SeedReadyPicture::class];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('devOnlyClasses')]
    public function testItIsOnlyDeclaredForDevAndTest(string $class): void
    {
        $attributes = new \ReflectionClass($class)->getAttributes(When::class);

        $environments = array_map(static fn (\ReflectionAttribute $attribute): string => $attribute->newInstance()->env, $attributes);
        sort($environments);

        self::assertSame(['dev', 'test'], $environments);
    }

    /** Both e2e seed commands use the ready picture seed, so one prod kernel proves neither reaches prod. */
    public function testItIsFoundInTestAndNotInAProdKernelBuiltInAFreshCacheDirectory(): void
    {
        $commands = [self::COMMAND, self::REVIEW_ROUND_COMMAND];
        foreach ($commands as $command) {
            self::assertSame($command, new Application($this->testKernel)->find($command)->getName(), 'Found in test.');
        }
        $testContainer = $this->testKernel->getContainer();
        self::assertInstanceOf(Container::class, $testContainer);
        self::assertArrayHasKey(ReadyPictureSeeds::class, $testContainer->getRemovedIds(), 'Where the seed is wired, its port alias is among the removed private ids.');

        $filesystem = new Filesystem();
        $prodCache = $this->testKernel->getProjectDir().'/var/cache/prod';
        $filesystem->remove($prodCache);
        $prodKernel = new Kernel('prod', false);
        try {
            $prodKernel->boot();

            foreach ($commands as $command) {
                try {
                    new Application($prodKernel)->find($command);
                    self::fail(\sprintf('%s is registered in prod.', $command));
                } catch (CommandNotFoundException) {
                    $this->addToAssertionCount(1);
                }
            }
            $container = $prodKernel->getContainer();
            self::assertInstanceOf(Container::class, $container);
            self::assertSame([ReadyPictureSeeds::class => false, SeedReadyPicture::class => false], array_combine([ReadyPictureSeeds::class, SeedReadyPicture::class], array_map($container->has(...), [ReadyPictureSeeds::class, SeedReadyPicture::class])), 'The prod container has no public ready picture seed.');
            // has() misses private services. An excluded class is always a removed id; the port alias is one only once wired.
            $removedIds = $container->getRemovedIds();
            self::assertNotSame([], $removedIds, 'The compiled prod container lists the private services it removed.');
            self::assertArrayNotHasKey(ReadyPictureSeeds::class, $removedIds, 'Prod wires no ready picture seed, not even a private one.');
            $mentions = [];
            // removed-ids.php names every excluded class, the seed included; any other file naming it uses it.
            foreach (new Finder()->files()->in($prodCache)->name('*.php')->notName('removed-ids.php')->contains('SeedReadyPicture') as $file) {
                $mentions[] = $file->getRelativePathname();
            }
            self::assertSame([], $mentions, 'No prod container file names the ready picture seed.');
        } finally {
            $prodKernel->shutdown();
            $filesystem->remove($prodCache);
        }
    }

    private function commandTester(): CommandTester
    {
        return new CommandTester(new Application($this->testKernel)->find(self::COMMAND));
    }

    /**
     * @return array{rounds: int, media: int, screenshots: int} the rows of Acme Foods
     */
    private function rowCounts(): array
    {
        $counts = ['rounds' => 0, 'media' => 0, 'screenshots' => 0];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use (&$counts): int {
            $count = static fn (string $table): int => \is_int($rows = $migrator->fetchOne('SELECT count(*) FROM '.$table)) ? $rows : throw new \LogicException('A count is a whole number.');
            $counts = ['rounds' => $count('rvw_round'), 'media' => $count('med_object'), 'screenshots' => $count('rvw_screenshot')];

            return 0;
        });

        return $counts;
    }
}
