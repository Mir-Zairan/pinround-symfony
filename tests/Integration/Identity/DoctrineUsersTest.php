<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Domain\Email;
use App\Identity\Domain\Theme;
use App\Identity\Domain\User;
use App\Identity\Domain\UserStatus;
use App\Identity\Infrastructure\Doctrine\DoctrineUsers;
use App\SharedKernel\Domain\UserId;
use App\Tests\Factory\Identity\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * DoctrineUsers on the real idn_user table as pinround_app. Each test runs in a transaction that is rolled back, since
 * the app may not delete users.
 */
final class DoctrineUsersTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection()->rollBack();
        parent::tearDown();
    }

    #[Test]
    public function aSavedUserIsFoundByIdWithEveryField(): void
    {
        $createdAt = new \DateTimeImmutable('2026-11-02 09:30:00 UTC');
        $user = new User(new UserId('0197a3c0-0000-7000-8000-0000000000c1'), new Email('sara@acme-foods.example'), 'Sara', 'de', 'Europe/Berlin', $createdAt);
        $this->users()->add($user);
        $this->entityManager->clear();

        $found = $this->users()->byId(new UserId('0197a3c0-0000-7000-8000-0000000000c1'));

        self::assertNotNull($found);
        self::assertNotSame($user, $found);
        self::assertSame('sara@acme-foods.example', $found->email()->toString());
        self::assertSame('Sara', $found->name());
        self::assertSame(UserStatus::Active, $found->status());
        self::assertSame('de', $found->locale());
        self::assertSame('Europe/Berlin', $found->timeZone());
        self::assertSame($createdAt->getTimestamp(), $found->createdAt()->getTimestamp());
        self::assertSame(Theme::System, $found->theme());
        self::assertSame($user->sessionStamp(), $found->sessionStamp());
    }

    #[Test]
    public function aRotatedSessionStampIsSavedOnFlush(): void
    {
        $user = UserFactory::createOne();
        $before = $user->sessionStamp();

        $user->rotateSessionStamp();
        $this->entityManager->flush();

        $saved = $this->connection()->fetchOne('SELECT session_stamp FROM idn_user WHERE id = ?', [$user->id()->toString()]);
        self::assertSame($user->sessionStamp(), $saved);
        self::assertNotSame($before, $saved);
    }

    #[Test]
    public function aChangedProfileSavedTwiceIsOneRowWithTheNewValuesAndTheSameSessionStamp(): void
    {
        $user = new User(new UserId('0197a3c0-0000-7000-8000-0000000000ca'), new Email('mia@acme-foods.example'), 'Mia', 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
        $this->users()->add($user);
        $stampBefore = $user->sessionStamp();

        $user->changeProfile('Mia Lund', 'US/Pacific', 'dark');
        $this->users()->save($user);
        $this->users()->save($user);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame(
            [['id' => '0197a3c0-0000-7000-8000-0000000000ca', 'name' => 'Mia Lund', 'time_zone' => 'US/Pacific', 'theme' => 'dark', 'session_stamp' => $stampBefore]],
            $this->connection()->fetchAllAssociative('SELECT id, name, time_zone, theme, session_stamp FROM idn_user'),
        );
        $found = $this->users()->byId(new UserId('0197a3c0-0000-7000-8000-0000000000ca'));
        self::assertNotNull($found);
        self::assertSame(['Mia Lund', 'US/Pacific', Theme::Dark], [$found->name(), $found->timeZone(), $found->theme()]);
    }

    #[Test]
    public function aChangedThemeIsSaved(): void
    {
        $user = UserFactory::createOne();

        $user->changeTheme(Theme::Light);
        $this->users()->save($user);
        $this->entityManager->clear();

        self::assertSame('light', $this->connection()->fetchOne('SELECT theme FROM idn_user WHERE id = ?', [$user->id()->toString()]));
    }

    #[Test]
    public function theDatabaseRefusesAThemeOutsideSystemLightAndDark(): void
    {
        $this->expectExceptionMessageMatches('/idn_user_theme_check/');

        $this->connection()->executeStatement(
            "INSERT INTO idn_user (id, email, name, status, locale, time_zone, created_at, session_stamp, theme)
             VALUES ('0197a3c0-0000-7000-8000-0000000000c6', 'raj@acme-foods.example', 'Raj', 'active', 'en', 'UTC', now(), 'a-stamp', 'blue')",
        );
    }

    /**
     * @return iterable<string, array{Theme}>
     */
    public static function everyTheme(): iterable
    {
        foreach (Theme::cases() as $theme) {
            yield $theme->value => [$theme];
        }
    }

    #[Test]
    #[DataProvider('everyTheme')]
    public function theDatabaseTakesEveryThemeAndItReadsBackAsTheSameCase(Theme $theme): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO idn_user (id, email, name, status, locale, time_zone, created_at, session_stamp, theme)
             VALUES ('0197a3c0-0000-7000-8000-0000000000c8', 'raj@acme-foods.example', 'Raj', 'active', 'en', 'UTC', now(), 'a-stamp', ?)",
            [$theme->value],
        );

        self::assertSame($theme, $this->users()->byId(new UserId('0197a3c0-0000-7000-8000-0000000000c8'))?->theme());
    }

    /**
     * @return iterable<string, array{UserStatus}>
     */
    public static function everyStatus(): iterable
    {
        foreach (UserStatus::cases() as $status) {
            yield $status->value => [$status];
        }
    }

    #[Test]
    #[DataProvider('everyStatus')]
    public function theDatabaseTakesEveryStatusAndItReadsBackAsTheSameCase(UserStatus $status): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO idn_user (id, email, name, status, locale, time_zone, created_at, session_stamp)
             VALUES ('0197a3c0-0000-7000-8000-0000000000c9', 'raj@acme-foods.example', 'Raj', ?, 'en', 'UTC', now(), 'a-stamp')",
            [$status->value],
        );

        self::assertSame($status, $this->users()->byId(new UserId('0197a3c0-0000-7000-8000-0000000000c9'))?->status());
    }

    #[Test]
    public function aRowWrittenWithoutAThemeFollowsTheSystem(): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO idn_user (id, email, name, status, locale, time_zone, created_at, session_stamp)
             VALUES ('0197a3c0-0000-7000-8000-0000000000c7', 'raj@acme-foods.example', 'Raj', 'active', 'en', 'UTC', now(), 'a-stamp')",
        );

        self::assertSame(Theme::System, $this->users()->byId(new UserId('0197a3c0-0000-7000-8000-0000000000c7'))?->theme());
    }

    #[Test]
    public function addingTheSameUserTwiceSavesOneRowWithTheOriginalValues(): void
    {
        $user = new User(new UserId('0197a3c0-0000-7000-8000-0000000000c5'), new Email('omar@acme-foods.example'), 'Omar', 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
        $this->users()->add($user);
        $this->users()->add($user);
        $this->entityManager->clear();

        self::assertSame(
            [['id' => '0197a3c0-0000-7000-8000-0000000000c5', 'email' => 'omar@acme-foods.example', 'name' => 'Omar', 'status' => 'active', 'locale' => 'en', 'time_zone' => 'Europe/Berlin', 'created_at' => '2026-11-02 09:30:00', 'theme' => 'system', 'session_stamp' => $user->sessionStamp()]],
            $this->connection()->fetchAllAssociative("SELECT id, email, name, status, locale, time_zone, to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS created_at, theme, session_stamp FROM idn_user"),
        );
    }

    #[Test]
    public function anUnknownIdOrEmailFindsNobody(): void
    {
        UserFactory::createOne(['email' => new Email('omar@acme-foods.example')]);

        self::assertNull($this->users()->byId(new UserId('0197a3c0-0000-7000-8000-0000000000ff')));
        self::assertNull($this->users()->byEmail(new Email('mia@acme-foods.example')));
    }

    #[Test]
    public function aUserIsFoundByEmailWhateverTheCaseOfTheStoredRow(): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO idn_user (id, email, name, status, locale, time_zone, created_at, session_stamp)
             VALUES ('0197a3c0-0000-7000-8000-0000000000c2', 'Omar@Acme-Foods.Example', 'Omar', 'active', 'en', 'UTC', now(), 'a-stamp')",
        );

        $found = $this->users()->byEmail(new Email('omar@acme-foods.example'));

        self::assertNotNull($found);
        self::assertSame('0197a3c0-0000-7000-8000-0000000000c2', $found->id()->toString());
    }

    #[Test]
    public function aSecondUserWithTheSameEmailIsRefused(): void
    {
        UserFactory::createOne(['email' => new Email('lena@acme-foods.example')]);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->users()->add(UserFactory::new()->withoutPersisting()->create(['email' => new Email(' LENA@acme-foods.example')]));
    }

    #[Test]
    public function theUniqueIndexRefusesTheSameEmailInAnotherLetterCase(): void
    {
        UserFactory::createOne(['email' => new Email('mia@acme-foods.example')]);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->connection()->executeStatement(
            "INSERT INTO idn_user (id, email, name, status, locale, time_zone, created_at, session_stamp)
             VALUES ('0197a3c0-0000-7000-8000-0000000000c3', 'MIA@Acme-Foods.Example', 'Mia', 'active', 'en', 'UTC', now(), 'a-stamp')",
        );
    }

    #[Test]
    public function theDatabaseRefusesAStatusOutsideActiveAndBlocked(): void
    {
        $this->expectExceptionMessageMatches('/idn_user_status_check/');

        $this->connection()->executeStatement(
            "INSERT INTO idn_user (id, email, name, status, locale, time_zone, created_at, session_stamp)
             VALUES ('0197a3c0-0000-7000-8000-0000000000c4', 'raj@acme-foods.example', 'Raj', 'deleted', 'en', 'UTC', now(), 'a-stamp')",
        );
    }

    private function users(): DoctrineUsers
    {
        return new DoctrineUsers($this->entityManager);
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
