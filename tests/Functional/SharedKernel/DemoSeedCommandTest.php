<?php

declare(strict_types=1);

namespace App\Tests\Functional\SharedKernel;

use App\Identity\Domain\Email;
use App\Organization\Api\DemoOrganizations;
use App\Organization\Infrastructure\Demo\SeedDemoOrganizations;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Infrastructure\Console\DemoSeedCommand;
use App\SharedKernel\Infrastructure\Console\DemoSeedRefused;
use App\Tests\Factory\Identity\UserFactory;
use App\Tests\Integration\Organization\OrganizationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\ProjectRows;
use App\Ticketing\Infrastructure\Demo\SeedDemoProjects;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Attribute\When;
use Zenstruck\Foundry\Test\Factories;

/**
 * Runs app:demo:seed on the test database. The organization step sets the tenant, which needs no transaction to be
 * open, so the seed commits and each test removes the demo rows it made.
 */
final class DemoSeedCommandTest extends KernelTestCase
{
    use Factories;

    private const string PASSWORD = 'a-demo-password-for-tests';

    private const array EMAILS = [
        'ben@beta-bakery.example',
        'lena@acme-foods.example',
        'mia@acme-foods.example',
        'omar@acme-foods.example',
        'raj@acme-foods.example',
        'sara@acme-foods.example',
    ];

    private string|false $passwordBefore;

    protected function setUp(): void
    {
        $this->passwordBefore = getenv('DEMO_PASSWORD');
        self::bootKernel();
        $this->removeDemoRows();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (!self::$booted) {
            self::bootKernel();
        }
        $this->removeDemoRows();
        false === $this->passwordBefore ? putenv('DEMO_PASSWORD') : putenv('DEMO_PASSWORD='.$this->passwordBefore);
        parent::tearDown();
    }

    #[Test]
    public function itCreatesTheSixPeopleWithACredentialThatVerifiesAgainstDemoPassword(): void
    {
        $this->startWithPassword(self::PASSWORD);

        $tester = $this->runSeed();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(self::EMAILS, $this->emails());
        $hashes = $this->credentialHashes();
        self::assertCount(6, $hashes);
        foreach ($hashes as $hash) {
            self::assertIsString($hash);
            self::assertTrue(password_verify(self::PASSWORD, $hash));
            self::assertStringStartsWith('$argon2id$', $hash);
        }
        self::assertStringNotContainsString(self::PASSWORD, $tester->getDisplay());
    }

    #[Test]
    public function aSecondRunCreatesNoDuplicatesAndKeepsTheFirstPasswords(): void
    {
        $this->startWithPassword(self::PASSWORD);
        $this->runSeed();
        // Argon2id salts every hash, so an unchanged hash proves the credential was not written again.
        $hashesAfterFirstRun = $this->credentialHashes();

        $second = $this->runSeed();

        self::assertSame(Command::SUCCESS, $second->getStatusCode());
        self::assertSame(self::EMAILS, $this->emails());
        self::assertSame($hashesAfterFirstRun, $this->credentialHashes());
        self::assertStringContainsString('created 0', $second->getDisplay());
    }

    #[Test]
    public function aPersonWithoutACredentialGetsOneWithoutANewUser(): void
    {
        $this->startWithPassword(self::PASSWORD);
        $omar = UserFactory::createOne(['email' => new Email('omar@acme-foods.example'), 'name' => 'Omar']);

        $tester = $this->runSeed();

        self::assertStringContainsString('created 5 (Lena, Mia, Raj, Sara, Ben), added a password to 1 (Omar), kept 0 ()', $tester->getDisplay());
        self::assertSame(self::EMAILS, $this->emails());
        self::assertCount(6, $this->credentialHashes());
        self::assertSame(
            $omar->id()->toString(),
            $this->connection()->fetchOne("SELECT id FROM idn_user WHERE email = 'omar@acme-foods.example'"),
        );
    }

    #[Test]
    public function itCreatesEveryRoleCombinationOfAcmeFoodsWithSaraAsTheTriager(): void
    {
        $this->startWithPassword(self::PASSWORD);

        $this->runSeed();

        $acmeFoods = $this->organizationIdOf('sara@acme-foods.example');
        self::assertSame(
            ['lena@acme-foods.example' => '{admin}', 'mia@acme-foods.example' => '{}', 'omar@acme-foods.example' => '{}', 'raj@acme-foods.example' => '{manager}', 'sara@acme-foods.example' => '{admin}'],
            $this->underTenant($acmeFoods, fn (): array => $this->connection()->fetchAllKeyValue(
                'SELECT u.email, m.org_roles FROM org_membership m JOIN idn_user u ON u.id = m.user_id ORDER BY u.email',
            )),
        );
        self::assertSame(
            [
                'Mobile|lena@acme-foods.example|lead|developer',
                'Mobile|omar@acme-foods.example|member|developer',
                'Shop|lena@acme-foods.example|lead|developer',
                'Shop|mia@acme-foods.example|member|tester',
                'Shop|omar@acme-foods.example|member|developer',
                'Shop|raj@acme-foods.example|viewer|other',
            ],
            $this->underTenant($acmeFoods, fn (): array => $this->connection()->fetchFirstColumn(
                "SELECT t.name || '|' || u.email || '|' || tm.team_role || '|' || tm.job FROM org_team_member tm"
                .' JOIN org_team t ON t.organization_id = tm.organization_id AND t.id = tm.team_id JOIN idn_user u ON u.id = tm.user_id ORDER BY 1',
            )),
        );
        self::assertSame(
            [$this->userIdOf('sara@acme-foods.example')],
            $this->underTenant($acmeFoods, fn (): array => $this->connection()->fetchFirstColumn("SELECT data->>'triager_id' FROM org_settings")),
        );
    }

    #[Test]
    public function betaBakeryHoldsOnlyBenAndItsRowsAreInvisibleUnderAcmeFoods(): void
    {
        $this->startWithPassword(self::PASSWORD);

        $this->runSeed();

        $betaBakery = $this->organizationIdOf('ben@beta-bakery.example');
        $acmeFoods = $this->organizationIdOf('sara@acme-foods.example');
        self::assertNotSame($acmeFoods, $betaBakery);
        self::assertSame(
            ['ben@beta-bakery.example' => '{admin}'],
            $this->underTenant($betaBakery, fn (): array => $this->connection()->fetchAllKeyValue(
                'SELECT u.email, m.org_roles FROM org_membership m JOIN idn_user u ON u.id = m.user_id',
            )),
        );
        self::assertSame(
            [0, 0],
            $this->underTenant($betaBakery, fn (): array => [
                $this->connection()->fetchOne('SELECT count(*) FROM org_team'),
                $this->connection()->fetchOne('SELECT count(*) FROM org_team_member'),
            ]),
        );
        self::assertSame(
            [],
            $this->underTenant($acmeFoods, fn (): array => $this->connection()->fetchFirstColumn(
                'SELECT organization_id FROM org_membership WHERE organization_id = ?',
                [$betaBakery],
            )),
        );
        self::assertSame(
            ['Acme Foods'],
            $this->underTenant($acmeFoods, fn (): array => $this->connection()->fetchFirstColumn('SELECT name FROM org_organization')),
        );
    }

    #[Test]
    public function lenaAndOmarEachBelongToExactlyOneOrganization(): void
    {
        $this->startWithPassword(self::PASSWORD);

        $this->runSeed();

        foreach (['lena@acme-foods.example', 'omar@acme-foods.example'] as $email) {
            self::assertSame(
                [$this->organizationIdOf('sara@acme-foods.example')],
                $this->organizationIdsOf($email),
                $email,
            );
        }
    }

    #[Test]
    public function aSecondRunOfTheOrganizationStepAddsNothingAndChangesNothing(): void
    {
        $this->startWithPassword(self::PASSWORD);
        $first = $this->runSeed();
        self::assertStringContainsString('Organizations: created 2, kept 0; Members: created 6, kept 0; Teams: created 2, kept 0; Team members: created 4, kept 0', $first->getDisplay());
        $rowsAfterFirstRun = $this->organizationRows();
        $outboxAfterFirstRun = $this->outboxCount();
        $acmeFoods = $this->organizationIdOf('sara@acme-foods.example');
        // A change made after the seed must survive the next one: Mia becomes a Viewer of Shop.
        $this->committedUnderTenant($acmeFoods, function (): void {
            $this->connection()->executeStatement("UPDATE org_team_member SET team_role = 'viewer' WHERE user_id = (SELECT id FROM idn_user WHERE email = 'mia@acme-foods.example')");
        });
        $rowsAfterChange = $this->organizationRows();

        $second = $this->runSeed();

        self::assertSame(Command::SUCCESS, $second->getStatusCode());
        self::assertStringContainsString('Organizations: created 0, kept 2; Members: created 0, kept 6; Teams: created 0, kept 2; Team members: created 0, kept 4', $second->getDisplay());
        self::assertSame($rowsAfterChange, $this->organizationRows());
        self::assertNotSame($rowsAfterFirstRun, $rowsAfterChange);
        self::assertSame($outboxAfterFirstRun, $this->outboxCount());
    }

    #[Test]
    public function aRunAfterAnOrganizationWasLostCreatesOnlyWhatIsMissing(): void
    {
        $this->startWithPassword(self::PASSWORD);
        $this->runSeed();
        $this->removeOrganizationOf('ben@beta-bakery.example');

        $second = $this->runSeed();

        self::assertStringContainsString('Organizations: created 1, kept 1; Members: created 1, kept 5; Teams: created 0, kept 2; Team members: created 0, kept 4', $second->getDisplay());
        self::assertCount(1, $this->organizationIdsOf('ben@beta-bakery.example'));
    }

    #[Test]
    public function itCreatesProjectShopOwnedByTheShopTeamOfAcmeFoodsOnce(): void
    {
        $this->startWithPassword(self::PASSWORD);
        $first = $this->runSeed();
        $acmeFoods = $this->organizationIdOf('sara@acme-foods.example');
        $projectsAfterFirstRun = $this->underTenant($acmeFoods, fn (): array => $this->connection()->fetchAllAssociative(
            'SELECT p.id, p.key, p.name, t.name AS team FROM tkt_project p JOIN org_team t ON t.organization_id = p.organization_id AND t.id = p.team_id',
        ));

        $second = $this->runSeed();

        self::assertStringContainsString('Projects: created 1, kept 0.', $first->getDisplay());
        self::assertCount(1, $projectsAfterFirstRun);
        self::assertSame(['SHOP', 'Shop', 'Shop'], [$projectsAfterFirstRun[0]['key'], $projectsAfterFirstRun[0]['name'], $projectsAfterFirstRun[0]['team']]);
        self::assertSame(Command::SUCCESS, $second->getStatusCode());
        self::assertStringContainsString('Projects: created 0, kept 1.', $second->getDisplay());
        self::assertSame($projectsAfterFirstRun, $this->underTenant($acmeFoods, fn (): array => $this->connection()->fetchAllAssociative(
            'SELECT p.id, p.key, p.name, t.name AS team FROM tkt_project p JOIN org_team t ON t.organization_id = p.organization_id AND t.id = p.team_id',
        )));
        self::assertSame(
            0,
            $this->underTenant($this->organizationIdOf('ben@beta-bakery.example'), fn (): mixed => $this->connection()->fetchOne('SELECT count(*) FROM tkt_project')),
        );
    }

    #[Test]
    public function theProjectStepRefusesWhenTheShopTeamIsMissing(): void
    {
        $this->startWithPassword(self::PASSWORD);
        $this->runSeed();
        $acmeFoods = $this->organizationIdOf('sara@acme-foods.example');
        self::getContainer()->get('doctrine')->resetManager();
        $this->projectRows()->removeAllOf($acmeFoods);
        $this->committedUnderTenant($acmeFoods, function (): void {
            $this->connection()->executeStatement("UPDATE org_team SET name = 'Webshop' WHERE name = 'Shop'");
        });
        $step = self::getContainer()->get(SeedDemoProjects::class);

        $this->expectException(DemoSeedRefused::class);
        $this->expectExceptionMessageIsOrContains('acme-foods has no team Shop yet');

        $step->seed();
    }

    #[Test]
    public function theProjectStepRefusesWhenAcmeFoodsIsMissing(): void
    {
        $this->startWithPassword(self::PASSWORD);
        $step = self::getContainer()->get(SeedDemoProjects::class);

        $this->expectException(DemoSeedRefused::class);
        $this->expectExceptionMessageIsOrContains('The demo organization acme-foods does not exist yet');

        $step->seed();
    }

    #[Test]
    public function demoOrganizationsFindsEachSeededOrganizationBySlugAndNothingElse(): void
    {
        $this->startWithPassword(self::PASSWORD);
        $demoOrganizations = self::getContainer()->get(DemoOrganizations::class);
        self::assertNull($demoOrganizations->idBySlug('acme-foods'));
        $this->runSeed();

        self::assertSame($this->organizationIdOf('sara@acme-foods.example'), $demoOrganizations->idBySlug('acme-foods')?->toString());
        self::assertSame($this->organizationIdOf('ben@beta-bakery.example'), $demoOrganizations->idBySlug('beta-bakery')?->toString());
        self::assertNull($demoOrganizations->idBySlug('gamma-grocers'));
    }

    #[Test]
    public function theOrganizationStepRefusesWhenAPersonIsMissing(): void
    {
        $this->startWithPassword(self::PASSWORD);
        $step = self::getContainer()->get(SeedDemoOrganizations::class);

        $this->expectException(DemoSeedRefused::class);
        $this->expectExceptionMessageIsOrContains('does not exist yet');

        $step->seed();
    }

    #[Test]
    public function itRefusesWithAClearMessageWhenDemoPasswordIsMissing(): void
    {
        $this->startWithPassword(null);

        $tester = $this->runSeed();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('DEMO_PASSWORD is not set', $tester->getDisplay());
        self::assertSame([], $this->emails());
    }

    #[Test]
    public function itRefusesWithAClearMessageWhenDemoPasswordHasFewerThanTwelveCharacters(): void
    {
        $this->startWithPassword('elevenchars');

        $tester = $this->runSeed();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('DEMO_PASSWORD needs at least 12 characters', $tester->getDisplay());
        self::assertStringNotContainsString('elevenchars', $tester->getDisplay());
        self::assertSame([], $this->emails());
    }

    #[Test]
    public function itIsRegisteredInTestAndNotInProd(): void
    {
        $registered = new Application(self::bootKernel())->find('app:demo:seed');
        $attributes = new \ReflectionClass(DemoSeedCommand::class)->getAttributes(When::class);

        $environments = array_map(static fn (\ReflectionAttribute $attribute): string => $attribute->newInstance()->env, $attributes);
        sort($environments);

        self::assertSame('app:demo:seed', $registered->getName());
        self::assertSame(['dev', 'test'], $environments);
    }

    /**
     * Boots a fresh kernel, because the container keeps the DEMO_PASSWORD it first resolved.
     */
    private function startWithPassword(?string $password): void
    {
        null === $password ? putenv('DEMO_PASSWORD') : putenv('DEMO_PASSWORD='.$password);
        self::bootKernel();
    }

    private function runSeed(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester(new Application($kernel)->find('app:demo:seed'));
        $tester->execute([]);

        return $tester;
    }

    /**
     * @return array<array-key, mixed> password hash by user id, for the demo people only
     */
    private function credentialHashes(): array
    {
        return $this->connection()->fetchAllKeyValue(
            'SELECT c.user_id, c.password_hash FROM idn_credential c JOIN idn_user u ON u.id = c.user_id WHERE u.email IN (?, ?, ?, ?, ?, ?) ORDER BY c.user_id',
            self::EMAILS,
        );
    }

    /**
     * @return list<string>
     */
    private function emails(): array
    {
        $emails = [];
        foreach ($this->connection()->fetchFirstColumn('SELECT email FROM idn_user WHERE email IN (?, ?, ?, ?, ?, ?) ORDER BY email', self::EMAILS) as $email) {
            self::assertIsString($email);
            $emails[] = $email;
        }

        return $emails;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function underTenant(string $organizationId, \Closure $work): mixed
    {
        $entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');

        return new TenantTransactions($entityManager, self::getContainer()->get(TenantContext::class))->rolledBackAs($organizationId, $work);
    }

    /**
     * @param \Closure(): mixed $work
     */
    private function committedUnderTenant(string $organizationId, \Closure $work): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');

        new TenantTransactions($entityManager, self::getContainer()->get(TenantContext::class))->committedAs($organizationId, $work);
    }

    private function userIdOf(string $email): string
    {
        $id = $this->connection()->fetchOne('SELECT id FROM idn_user WHERE email = ?', [$email]);
        self::assertIsString($id, $email);

        return $id;
    }

    /**
     * @return list<string>
     */
    private function organizationIdsOf(string $email): array
    {
        $ids = [];
        foreach ($this->connection()->fetchFirstColumn('SELECT organization_id FROM resolve_user_orgs(?)', [$this->userIdOf($email)]) as $id) {
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    private function organizationIdOf(string $email): string
    {
        $ids = $this->organizationIdsOf($email);
        self::assertCount(1, $ids, $email);

        return $ids[0];
    }

    /**
     * @return list<string> every membership and team member row of both demo organizations, as text
     */
    private function organizationRows(): array
    {
        $rows = [];
        foreach (['sara@acme-foods.example', 'ben@beta-bakery.example'] as $email) {
            $rows[] = $this->underTenant($this->organizationIdOf($email), fn (): string => json_encode([
                $this->connection()->fetchAllAssociative('SELECT * FROM org_membership ORDER BY user_id'),
                $this->connection()->fetchAllAssociative('SELECT * FROM org_team_member ORDER BY team_id, user_id'),
                $this->connection()->fetchAllAssociative('SELECT * FROM org_team ORDER BY id'),
                $this->connection()->fetchAllAssociative('SELECT * FROM org_settings'),
            ], \JSON_THROW_ON_ERROR));
        }

        return $rows;
    }

    private function outboxCount(): int
    {
        $count = $this->connection()->fetchOne("SELECT count(*) FROM messenger_outbox WHERE queue_name = 'outbox'");
        self::assertIsInt($count);

        return $count;
    }

    private function removeOrganizationOf(string $email): void
    {
        $this->rows()->removeAllOf(...$this->organizationIdsOf($email));
    }

    /**
     * Removes what the seed made, wherever an earlier run left it: the organizations of the demo people, their
     * outbox messages, credentials and the people themselves. The entity manager is emptied first, as the tenant
     * may only change once it holds nothing.
     */
    private function removeDemoRows(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $migrator = self::getContainer()->get('doctrine.dbal.migrator_connection');
        $userIds = $migrator->fetchFirstColumn('SELECT id FROM idn_user WHERE email IN (?)', [self::EMAILS], [ArrayParameterType::STRING]);
        $organizationIds = [];
        foreach ($userIds as $userId) {
            foreach ($this->connection()->fetchFirstColumn('SELECT organization_id FROM resolve_user_orgs(?)', [$userId]) as $organizationId) {
                self::assertIsString($organizationId);
                $organizationIds[$organizationId] = $organizationId;
            }
        }
        $this->projectRows()->removeAllOf(...array_values($organizationIds));
        $this->rows()->removeAllOf(...array_values($organizationIds));
        $migrator->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
        if ([] !== $userIds) {
            $migrator->executeStatement('DELETE FROM idn_credential WHERE user_id IN (?)', [$userIds], [ArrayParameterType::STRING]);
            $migrator->executeStatement('DELETE FROM idn_user WHERE id IN (?)', [$userIds], [ArrayParameterType::STRING]);
        }
    }

    private function projectRows(): ProjectRows
    {
        return new ProjectRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }

    private function rows(): OrganizationRows
    {
        return new OrganizationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager')->getConnection();
    }
}
