<?php

declare(strict_types=1);

namespace App\Tests\Functional\SharedKernel;

use App\Identity\Domain\Email;
use App\SharedKernel\Infrastructure\Console\DemoSeedCommand;
use App\Tests\Factory\Identity\UserFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Attribute\When;
use Zenstruck\Foundry\Test\Factories;

/**
 * Runs app:demo:seed on the test database inside a transaction that is rolled back after each test.
 */
final class DemoSeedCommandTest extends KernelTestCase
{
    use Factories;

    private const string PASSWORD = 'a-demo-password-for-tests';

    private const array EMAILS = [
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
    }

    protected function tearDown(): void
    {
        if (self::$booted && $this->connection()->isTransactionActive()) {
            $this->connection()->rollBack();
        }
        false === $this->passwordBefore ? putenv('DEMO_PASSWORD') : putenv('DEMO_PASSWORD='.$this->passwordBefore);
        parent::tearDown();
    }

    #[Test]
    public function itCreatesTheFivePeopleWithACredentialThatVerifiesAgainstDemoPassword(): void
    {
        $this->startWithPassword(self::PASSWORD);

        $tester = $this->runSeed();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(self::EMAILS, $this->emails());
        $hashes = $this->connection()->fetchFirstColumn('SELECT password_hash FROM idn_credential');
        self::assertCount(5, $hashes);
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
        $hashesAfterFirstRun = $this->connection()->fetchAllKeyValue('SELECT user_id, password_hash FROM idn_credential');

        $second = $this->runSeed();

        self::assertSame(Command::SUCCESS, $second->getStatusCode());
        self::assertSame(self::EMAILS, $this->emails());
        self::assertSame($hashesAfterFirstRun, $this->connection()->fetchAllKeyValue('SELECT user_id, password_hash FROM idn_credential'));
        self::assertStringContainsString('created 0', $second->getDisplay());
    }

    #[Test]
    public function aPersonWithoutACredentialGetsOneWithoutANewUser(): void
    {
        $this->startWithPassword(self::PASSWORD);
        $omar = UserFactory::createOne(['email' => new Email('omar@acme-foods.example'), 'name' => 'Omar']);

        $this->runSeed();

        self::assertSame(self::EMAILS, $this->emails());
        self::assertSame(5, $this->connection()->fetchOne('SELECT count(*) FROM idn_credential'));
        self::assertSame(
            $omar->id()->toString(),
            $this->connection()->fetchOne("SELECT id FROM idn_user WHERE email = 'omar@acme-foods.example'"),
        );
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
        $this->connection()->beginTransaction();
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
     * @return list<string>
     */
    private function emails(): array
    {
        $emails = [];
        foreach ($this->connection()->fetchFirstColumn('SELECT email FROM idn_user ORDER BY email') as $email) {
            self::assertIsString($email);
            $emails[] = $email;
        }

        return $emails;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager')->getConnection();
    }
}
