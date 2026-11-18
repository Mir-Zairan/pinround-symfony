<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity\Infrastructure\Scheduler;

use App\Identity\Api\UserQueries;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokens;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Infrastructure\Doctrine\DoctrineApiTokens;
use App\Identity\Infrastructure\Mail\ApiTokenExpiryMail;
use App\Identity\Infrastructure\Scheduler\QueueApiTokenExpiryWarnings;
use App\Identity\Infrastructure\Scheduler\SendApiTokenExpiryWarnings;
use App\Identity\Infrastructure\Scheduler\SendApiTokenExpiryWarningsHandler;
use App\Identity\Infrastructure\Security\HmacApiTokenHashing;
use App\SharedKernel\Application\MessageForAnotherTenantRefused;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use App\SharedKernel\Infrastructure\Scheduler\DefaultSchedule;
use App\Tests\Fixtures\Messaging\CapturedLogRecords;
use App\Tests\Fixtures\Messaging\RecordingMessageBus;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Formatter\JsonFormatter;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;

/**
 * The API token expiry warning run on 3 Mar 2027 at 09:00 UTC, through the resolver, the real command.bus and
 * async_notify, so it warns tokens expiring from then up to but not including 11 Mar 09:00 UTC: on time 7 to 8 days
 * ahead, and late the ones a missed run left out. In Acme Foods Omar (Berlin time) has three tokens in that range (in 3
 * days, never warned; in 7 days; a second before its end) and three outside it (an hour ago, at the range's end, in 9
 * days); Lena has a revoked one in it and Mia, who is blocked, a live one. In Beta Bakery Lena has one in it; Cedar
 * Cafe only one in 9 days. Other tests share the database, so only these organizations' rows and
 * messages are read and handled.
 */
final class QueueApiTokenExpiryWarningsTest extends KernelTestCase
{
    use RunsTheWorkerUntilIdle;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000e9a01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000e9a02';
    private const string CEDAR_CAFE = '0197a3c0-0000-7000-8000-0000000e9a03';
    private const array ORGANIZATIONS = [self::ACME_FOODS, self::BETA_BAKERY, self::CEDAR_CAFE];
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000e9a11';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000e9a12';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000e9a13';
    private const array PEOPLE = [self::OMAR, self::LENA, self::MIA];
    private const string OMAR_ADDRESS = 'omar.9a11@acme-foods.example';
    private const string LENA_ADDRESS = 'lena.9a12@acme-foods.example';
    private const string MIA_ADDRESS = 'mia.9a13@acme-foods.example';

    private const string OMAR_IN_7_DAYS = '0197a3c0-0000-7000-8000-0000000e9a21';
    private const string OMAR_JUST_BEFORE_RANGE_END = '0197a3c0-0000-7000-8000-0000000e9a22';
    private const string OMAR_IN_3_DAYS = '0197a3c0-0000-7000-8000-0000000e9a23';
    private const string OMAR_AT_RANGE_END = '0197a3c0-0000-7000-8000-0000000e9a24';
    private const string OMAR_IN_9_DAYS = '0197a3c0-0000-7000-8000-0000000e9a25';
    private const string LENA_REVOKED = '0197a3c0-0000-7000-8000-0000000e9a26';
    private const string MIA_BLOCKED = '0197a3c0-0000-7000-8000-0000000e9a27';
    private const string LENA_AT_BETA = '0197a3c0-0000-7000-8000-0000000e9a28';
    private const string CEDAR_IN_9_DAYS = '0197a3c0-0000-7000-8000-0000000e9a29';
    private const string OMAR_EXPIRED_AN_HOUR_AGO = '0197a3c0-0000-7000-8000-0000000e9a2a';

    private const string NOW = '2027-03-03 09:00:00 UTC';
    private const string RAN_AT = '2027-03-03T09:00:00+00:00';
    private const string PEPPER = 'integration-test-pepper-of-32-bytes-or-more';

    /** @var array<string, string> token id => its display prefix */
    private array $prefixes = [];

    protected function setUp(): void
    {
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->removeRows();
        foreach ([[self::OMAR, self::OMAR_ADDRESS, 'Omar', 'active', 'Europe/Berlin'], [self::LENA, self::LENA_ADDRESS, 'Lena', 'active', 'UTC'], [self::MIA, self::MIA_ADDRESS, 'Mia', 'blocked', 'UTC']] as $person) {
            $this->migrator()->executeStatement(
                "INSERT INTO idn_user (id, email, name, status, locale, time_zone, created_at, session_stamp)
                 VALUES (?, ?, ?, ?, 'en', ?, '2027-01-04 10:00:00+00', 'a-stamp')",
                $person,
            );
        }
        // Added out of order, so the order in an email cannot come from the insert order.
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->addToken(self::OMAR_JUST_BEFORE_RANGE_END, self::ACME_FOODS, self::OMAR, 'Browser plugin', '2027-03-11 08:59:59 UTC');
            $this->addToken(self::OMAR_IN_3_DAYS, self::ACME_FOODS, self::OMAR, 'Old laptop', '2027-03-06 09:00:00 UTC');
            $this->addToken(self::OMAR_EXPIRED_AN_HOUR_AGO, self::ACME_FOODS, self::OMAR, 'Spare key', '2027-03-03 08:00:00 UTC');
            $this->addToken(self::OMAR_IN_7_DAYS, self::ACME_FOODS, self::OMAR, 'Claude Code', '2027-03-10 09:00:00 UTC');
            $this->addToken(self::OMAR_AT_RANGE_END, self::ACME_FOODS, self::OMAR, 'Build server', '2027-03-11 09:00:00 UTC');
            $this->addToken(self::OMAR_IN_9_DAYS, self::ACME_FOODS, self::OMAR, 'Home desktop', '2027-03-12 09:00:00 UTC');
            $this->addToken(self::LENA_REVOKED, self::ACME_FOODS, self::LENA, 'Claude Code', '2027-03-10 12:00:00 UTC');
            $this->addToken(self::MIA_BLOCKED, self::ACME_FOODS, self::MIA, 'Claude Code', '2027-03-10 12:00:00 UTC');
            self::assertTrue($this->tokens()->revoke(self::LENA_REVOKED, new \DateTimeImmutable('2027-03-01 09:00:00 UTC')));
        });
        $this->transactions()->committedAs(self::BETA_BAKERY, fn () => $this->addToken(self::LENA_AT_BETA, self::BETA_BAKERY, self::LENA, 'Claude Code', '2027-03-10 18:00:00 UTC'));
        $this->transactions()->committedAs(self::CEDAR_CAFE, fn () => $this->addToken(self::CEDAR_IN_9_DAYS, self::CEDAR_CAFE, self::LENA, 'Claude Code', '2027-03-12 09:00:00 UTC'));
        $this->capturedLogRecords()->clear();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeRows();
        parent::tearDown();
    }

    /** Not limited to some environments like the partition jobs: it uses the app connection, which prod has. */
    #[Test]
    public function theDefaultScheduleRunsTheWarningJobOnceADay(): void
    {
        $schedule = self::getContainer()->get(DefaultSchedule::class);

        $warningTasks = [];
        foreach ($schedule->getRecurringMessages() as $task) {
            $context = new MessageContext('default', $task->getId(), $task->getTrigger(), new \DateTimeImmutable(self::NOW));
            foreach ($task->getMessages($context) as $message) {
                if ($message instanceof ServiceCallMessage && QueueApiTokenExpiryWarnings::class === $message->getServiceId()) {
                    $warningTasks[] = $task->getTrigger().' '.$message->getMethod();
                }
            }
        }

        self::assertSame(['every 1 day __invoke'], $warningTasks);
    }

    #[Test]
    public function theResolverReturnsOnlyTheIdOfEachOrganizationWithAnUnwarnedLiveTokenInTheRangeOnce(): void
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT * FROM organizations_with_expiring_api_tokens(?, ?)',
            ['2027-03-10 09:00:00+00', '2027-03-11 09:00:00+00'],
        );

        self::assertSame(
            [['organization_id' => self::ACME_FOODS], ['organization_id' => self::BETA_BAKERY]],
            array_values(array_filter($rows, static fn (array $row): bool => \in_array($row['organization_id'] ?? null, self::ORGANIZATIONS, true))),
        );
    }

    #[Test]
    public function aRunWarnsEachLiveTokenInTheRangeOnceWithOneEmailPerOwnerInTheirTimeZone(): void
    {
        $this->runTheJobForTheseOrganizations([self::ACME_FOODS, self::BETA_BAKERY]);

        $emails = $this->emailsSent();
        self::assertEqualsCanonicalizing([self::OMAR_ADDRESS, self::LENA_ADDRESS], array_keys($emails));
        self::assertSame('3 of your pinround API tokens stop working soon', $emails[self::OMAR_ADDRESS]->getSubject());
        $omarsText = $emails[self::OMAR_ADDRESS]->getTextBody();
        self::assertIsString($omarsText);
        self::assertStringContainsString(
            "3 of your pinround API tokens stop working soon:\n\n"
            .'- Old laptop ('.$this->prefixes[self::OMAR_IN_3_DAYS].") works until 6 Mar 2027, 10:00 CET\n\n"
            .'- Claude Code ('.$this->prefixes[self::OMAR_IN_7_DAYS].") works until 10 Mar 2027, 10:00 CET\n\n"
            .'- Browser plugin ('.$this->prefixes[self::OMAR_JUST_BEFORE_RANGE_END].") works until 11 Mar 2027, 09:59 CET\n\n"
            ."To keep Claude Code or the browser plugin working, make a new token and put it in their settings:\n",
            $omarsText,
        );
        self::assertMatchesRegularExpression('#^https?://[^/\s]+/me/tokens$#m', $omarsText);
        self::assertSame('Your pinround API token stops working soon', $emails[self::LENA_ADDRESS]->getSubject());
        $lenasText = $emails[self::LENA_ADDRESS]->getTextBody();
        self::assertIsString($lenasText);
        self::assertStringContainsString('- Claude Code ('.$this->prefixes[self::LENA_AT_BETA].") works until 10 Mar 2027, 18:00 UTC\n", $lenasText);
        self::assertSame(
            [
                self::OMAR_IN_7_DAYS => '2027-03-03 09:00:00',
                self::OMAR_JUST_BEFORE_RANGE_END => '2027-03-03 09:00:00',
                // Never warned, as if a run was missed: warned late, once.
                self::OMAR_IN_3_DAYS => '2027-03-03 09:00:00',
                self::OMAR_AT_RANGE_END => null,
                self::OMAR_IN_9_DAYS => null,
                self::LENA_REVOKED => null,
                // Claimed with the others, though the blocked owner is sent nothing.
                self::MIA_BLOCKED => '2027-03-03 09:00:00',
                self::OMAR_EXPIRED_AN_HOUR_AGO => null,
            ],
            $this->warnedAtOf(self::ACME_FOODS),
        );
        self::assertSame([self::LENA_AT_BETA => '2027-03-03 09:00:00'], $this->warnedAtOf(self::BETA_BAKERY));
        self::assertSame([self::CEDAR_IN_9_DAYS => null], $this->warnedAtOf(self::CEDAR_CAFE));
    }

    #[Test]
    public function aSecondRunAndAMessageDeliveredAgainSendNothing(): void
    {
        $this->runTheJobForTheseOrganizations([self::ACME_FOODS, self::BETA_BAKERY]);
        $this->asyncNotify()->reset();

        self::assertSame([], $this->organizationsOf($this->job()()));
        self::assertSame([], $this->warningsSentForTheseOrganizations());

        self::getContainer()->get('command.bus')->dispatch(new SendApiTokenExpiryWarnings(self::ACME_FOODS, self::RAN_AT), [new TenantStamp(new OrganizationId(self::ACME_FOODS))]);
        $this->handleOnlyTheMessagesOfTheseOrganizations();

        self::assertSame([], $this->emailsSent());
        self::assertSame('2027-03-03 09:00:00', $this->warnedAtOf(self::ACME_FOODS)[self::OMAR_IN_7_DAYS]);
    }

    #[Test]
    public function anOrganizationsMessageWarnsOnlyThatOrganizationsTokens(): void
    {
        self::getContainer()->get('command.bus')->dispatch(new SendApiTokenExpiryWarnings(self::BETA_BAKERY, self::RAN_AT), [new TenantStamp(new OrganizationId(self::BETA_BAKERY))]);
        $this->handleOnlyTheMessagesOfTheseOrganizations();

        self::assertSame([self::LENA_ADDRESS], array_keys($this->emailsSent()));
        self::assertSame([self::LENA_AT_BETA => '2027-03-03 09:00:00'], $this->warnedAtOf(self::BETA_BAKERY));
        self::assertSame([], array_filter($this->warnedAtOf(self::ACME_FOODS), static fn (?string $warnedAt): bool => null !== $warnedAt));
    }

    #[Test]
    public function aMessageNamingAnotherOrganizationThanTheOneSetIsRefusedAndWarnsNothing(): void
    {
        $handler = self::getContainer()->get(SendApiTokenExpiryWarningsHandler::class);

        try {
            $this->transactions()->committedAs(self::ACME_FOODS, static function () use ($handler): void {
                $handler(new SendApiTokenExpiryWarnings(self::BETA_BAKERY, self::RAN_AT));
            });
            self::fail('Beta Bakery\'s warnings ran under Acme Foods.');
        } catch (MessageForAnotherTenantRefused) {
            // The failed transaction closed the entity manager.
            self::getContainer()->get('doctrine')->resetManager();
        }

        self::assertSame([self::LENA_AT_BETA => null], $this->warnedAtOf(self::BETA_BAKERY));
        self::assertSame([], array_filter($this->warnedAtOf(self::ACME_FOODS), static fn (?string $warnedAt): bool => null !== $warnedAt));
        self::assertSame([], $this->emailsSent());
    }

    /**
     * Acme Foods has four tokens to warn and a page holds three, so the first message claims three and sends itself
     * again with the same run time; the second claims the last one and, its page not full, sends nothing more. Each
     * email and the next page's message wait for the bus to commit, so a rollback sends none of them. The bus here
     * only records: the real one drops the stamp before a transport sees it.
     */
    #[Test]
    public function aFullPageSendsTheMessageAgainForTheSameRunAndAPageThatIsNotFullStops(): void
    {
        $commandBus = new RecordingMessageBus();
        $handler = $this->handlerWithPagesOf(3, $commandBus);

        $this->transactions()->committedAs(self::ACME_FOODS, static fn () => $handler(new SendApiTokenExpiryWarnings(self::ACME_FOODS, self::RAN_AT)));

        self::assertSame(
            ['email to '.self::OMAR_ADDRESS.' after commit', self::ACME_FOODS.' '.self::RAN_AT.' for '.self::ACME_FOODS.' after commit'],
            self::describe($commandBus->dispatched()),
        );
        self::assertSame(
            [self::OMAR_IN_7_DAYS => '2027-03-03 09:00:00', self::OMAR_JUST_BEFORE_RANGE_END => null, self::OMAR_IN_3_DAYS => '2027-03-03 09:00:00', self::MIA_BLOCKED => '2027-03-03 09:00:00'],
            array_intersect_key($this->warnedAtOf(self::ACME_FOODS), array_flip([self::OMAR_IN_7_DAYS, self::OMAR_JUST_BEFORE_RANGE_END, self::OMAR_IN_3_DAYS, self::MIA_BLOCKED])),
        );

        $this->transactions()->committedAs(self::ACME_FOODS, static fn () => $handler(new SendApiTokenExpiryWarnings(self::ACME_FOODS, self::RAN_AT)));

        // One email per owner and page: Omar's two tokens fell on two pages.
        self::assertSame(
            ['email to '.self::OMAR_ADDRESS.' after commit', self::ACME_FOODS.' '.self::RAN_AT.' for '.self::ACME_FOODS.' after commit', 'email to '.self::OMAR_ADDRESS.' after commit'],
            self::describe($commandBus->dispatched()),
        );
        self::assertSame('2027-03-03 09:00:00', $this->warnedAtOf(self::ACME_FOODS)[self::OMAR_JUST_BEFORE_RANGE_END]);
    }

    /**
     * @param list<Envelope> $envelopes
     *
     * @return list<string> per envelope its recipients or its organization, run time and TenantStamp, and whether it
     *                      waits for the bus to commit
     */
    private static function describe(array $envelopes): array
    {
        return array_map(static function (Envelope $envelope): string {
            $message = $envelope->getMessage();
            $what = match (true) {
                $message instanceof SendEmailMessage && $message->getMessage() instanceof Email => 'email to '.implode(', ', array_map(static fn ($address): string => $address->getAddress(), $message->getMessage()->getTo())),
                $message instanceof SendApiTokenExpiryWarnings => $message->organizationId.' '.$message->ranAt.' for '.$envelope->last(TenantStamp::class)?->organizationId()->toString(),
                default => $message::class,
            };

            return $what.(null === $envelope->last(DispatchAfterCurrentBusStamp::class) ? ' at once' : ' after commit');
        }, $envelopes);
    }

    /** A page past 100 would ask UserQueries for more owners than it takes; an empty page would never end. */
    #[Test]
    public function aPageOfNoTokensOrMoreThanAHundredIsRefused(): void
    {
        foreach ([0, SendApiTokenExpiryWarningsHandler::MAX_TOKENS_PER_PAGE + 1] as $tokensPerPage) {
            try {
                $this->handlerWithPagesOf($tokensPerPage);
                self::fail(\sprintf('A page of %d tokens was accepted.', $tokensPerPage));
            } catch (\InvalidArgumentException $refused) {
                self::assertSame('A page of expiry warnings holds 1 to 100 tokens.', $refused->getMessage());
            }
        }
        $this->handlerWithPagesOf(1);
        $this->handlerWithPagesOf(SendApiTokenExpiryWarningsHandler::MAX_TOKENS_PER_PAGE);
    }

    /**
     * Past the cap the run queues as many organizations as the cap allows and logs that it left some out, naming no
     * token.
     */
    #[Test]
    public function pastTheCapARunQueuesOnlyThatManyOrganizationsAndLogsItWithoutATokensNameOrPrefix(): void
    {
        $job = new QueueApiTokenExpiryWarnings(
            $this->connection(),
            self::getContainer()->get('command.bus'),
            new MockClock(new \DateTimeImmutable(self::NOW)),
            self::getContainer()->get(LoggerInterface::class),
            1,
        );

        self::assertCount(1, $job());
        $records = $this->capturedLogRecords()->all();
        self::assertNotSame([], $records);
        $lines = implode("\n", array_map(static fn ($record): string => new JsonFormatter()->format($record), $records));
        self::assertStringContainsString('The API token expiry warning job found more than {cap} organizations to warn', $lines);
        foreach ([...array_values($this->prefixes), 'Claude Code', 'Browser plugin'] as $tokenText) {
            self::assertStringNotContainsString($tokenText, $lines);
        }
    }

    /**
     * Runs the periodic task, checks it sent one stamped message to each of $expected among this test's organizations,
     * and handles only those, emails included.
     *
     * @param list<string> $expected
     */
    private function runTheJobForTheseOrganizations(array $expected): void
    {
        $sent = $this->organizationsOf($this->job()());
        self::assertEqualsCanonicalizing($expected, $sent);
        self::assertEqualsCanonicalizing(
            array_map(static fn (string $organizationId): string => $organizationId.' '.self::RAN_AT, $expected),
            $this->warningsSentForTheseOrganizations(),
        );
        $this->handleOnlyTheMessagesOfTheseOrganizations();
    }

    /**
     * @param list<SendApiTokenExpiryWarnings> $warnings
     *
     * @return list<string> the organizations among this test's that $warnings name
     */
    private function organizationsOf(array $warnings): array
    {
        return array_values(array_filter(
            array_map(static fn (SendApiTokenExpiryWarnings $sent): string => $sent->organizationId, $warnings),
            static fn (string $organizationId): bool => \in_array($organizationId, self::ORGANIZATIONS, true),
        ));
    }

    /**
     * @return list<string> "organization ranAt" of each message sent for this test's organizations, checked against
     *                      its TenantStamp
     */
    private function warningsSentForTheseOrganizations(): array
    {
        $sent = [];
        foreach ($this->asyncNotify()->getSent() as $envelope) {
            $warnings = $envelope->getMessage();
            if ($warnings instanceof SendApiTokenExpiryWarnings && \in_array($warnings->organizationId, self::ORGANIZATIONS, true)) {
                self::assertSame($warnings->organizationId, $envelope->last(TenantStamp::class)?->organizationId()->toString());
                $sent[] = $warnings->organizationId.' '.$warnings->ranAt;
            }
        }

        return $sent;
    }

    /**
     * Puts back only the queued messages of this test's organizations, so rows other tests hold are never warned here,
     * and the emails sent on the way.
     */
    private function handleOnlyTheMessagesOfTheseOrganizations(): void
    {
        $asyncNotify = $this->asyncNotify();
        $ours = array_filter(
            $asyncNotify->getSent(),
            static fn (Envelope $envelope): bool => \in_array($envelope->last(TenantStamp::class)?->organizationId()->toString(), self::ORGANIZATIONS, true),
        );
        $asyncNotify->reset();
        foreach ($ours as $envelope) {
            $asyncNotify->send($envelope);
        }
        self::runTheWorkerUntilIdle('async_notify');
    }

    /**
     * @return array<string, Email> each email queued for this test's people, by its one address, in the order queued
     */
    private function emailsSent(): array
    {
        $emails = [];
        foreach ($this->asyncNotify()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if (!$message instanceof SendEmailMessage) {
                continue;
            }
            $email = $message->getMessage();
            self::assertInstanceOf(Email::class, $email);
            $to = array_map(static fn ($address): string => $address->getAddress(), $email->getTo());
            if ([] === array_intersect($to, [self::OMAR_ADDRESS, self::LENA_ADDRESS, self::MIA_ADDRESS])) {
                continue;
            }
            self::assertCount(1, $to);
            self::assertArrayNotHasKey($to[0], $emails, 'a second email went to '.$to[0]);
            $emails[$to[0]] = $email;
        }

        return $emails;
    }

    /**
     * @return array<string, string|null> expiry_warned_at in UTC by token id, in id order
     */
    private function warnedAtOf(string $organizationId): array
    {
        /** @var array<string, string|null> $warnedAt */
        $warnedAt = $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchAllKeyValue(
            "SELECT id, to_char(expiry_warned_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM idn_api_token WHERE organization_id = ? ORDER BY id",
            [$organizationId],
        ));

        return $warnedAt;
    }

    private function addToken(string $id, string $organizationId, string $userId, string $name, string $expiresAt): void
    {
        $issued = ApiToken::issue(
            $id,
            new OrganizationId($organizationId),
            new UserId($userId),
            $name,
            ApiTokenScope::claudeCodeDefaults(),
            new HmacApiTokenHashing(self::PEPPER),
            new \DateTimeImmutable($expiresAt)->modify('-30 days'),
            30,
        );
        $this->tokens()->add($issued->token());
        $this->prefixes[$id] = $issued->token()->prefix();
    }

    private function handlerWithPagesOf(int $tokensPerPage, ?MessageBusInterface $commandBus = null): SendApiTokenExpiryWarningsHandler
    {
        return new SendApiTokenExpiryWarningsHandler(
            self::getContainer()->get(TenantContext::class),
            self::getContainer()->get(ApiTokens::class),
            self::getContainer()->get(UserQueries::class),
            self::getContainer()->get(ApiTokenExpiryMail::class),
            $commandBus ?? self::getContainer()->get('command.bus'),
            new MockClock(new \DateTimeImmutable(self::NOW)),
            $tokensPerPage,
        );
    }

    /**
     * Through the migrator, which owns the tables and so may delete; FORCE ROW LEVEL SECURITY binds it on the tokens.
     */
    private function removeRows(): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        foreach (self::ORGANIZATIONS as $organizationId) {
            $tenantContext->set(new OrganizationId($organizationId));
            try {
                $this->migrator()->transactional(
                    static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM idn_api_token WHERE organization_id = ?', [$organizationId]),
                );
            } finally {
                $tenantContext->clear();
            }
        }
        $this->migrator()->executeStatement('DELETE FROM idn_user WHERE id IN (?, ?, ?)', self::PEOPLE);
    }

    private function job(): QueueApiTokenExpiryWarnings
    {
        return self::getContainer()->get(QueueApiTokenExpiryWarnings::class);
    }

    private function tokens(): DoctrineApiTokens
    {
        return new DoctrineApiTokens($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function asyncNotify(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_notify');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function capturedLogRecords(): CapturedLogRecords
    {
        $capturedLogRecords = self::getContainer()->get(CapturedLogRecords::class);
        self::assertInstanceOf(CapturedLogRecords::class, $capturedLogRecords);

        return $capturedLogRecords;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }
}
