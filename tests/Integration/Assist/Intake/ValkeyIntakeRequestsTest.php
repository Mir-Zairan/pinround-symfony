<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assist\Intake;

use App\Assist\Api\DraftReport;
use App\Assist\Api\IntakeCheckId;
use App\Assist\Api\IntakeCheckView;
use App\Assist\Application\Intake\IntakeRequests;
use App\Assist\Application\Intake\TooManyOpenIntakeChecks;
use App\Assist\Infrastructure\Valkey\ValkeyIntakeRequests;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The check states on the test's state Valkey, with the clock frozen in March 2027. Beta Bakery has a check with the
 * same id as Acme Foods' one, so a key without the organization would mix their states up. The draft carries a marker
 * that must never be in the state key the page polls, and must be gone from Valkey once the job took it.
 */
final class ValkeyIntakeRequestsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000c9b01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000c9b02';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000c9b41';
    private const string CHECK = '0197a3c0-0000-7000-8000-0000000c9b31';
    private const string SECOND_CHECK = '0197a3c0-0000-7000-8000-0000000c9b32';
    private const string THIRD_CHECK = '0197a3c0-0000-7000-8000-0000000c9b33';
    private const string FOURTH_CHECK = '0197a3c0-0000-7000-8000-0000000c9b34';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000c9b11';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000c9b12';
    private const string NOW = '2027-03-15 10:00:00 UTC';
    private const string FAILED_SENTENCE = 'The AI answer failed. Try again.';
    private const string MARKER = 'MARKER-9b3';

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $this->forgetStates();
    }

    protected function tearDown(): void
    {
        $this->forgetStates();
        parent::tearDown();
    }

    #[Test]
    public function theContainersPortIsThisClassOnTheStateValkey(): void
    {
        $port = self::getContainer()->get('test.assist_intake_requests');
        self::assertInstanceOf(ValkeyIntakeRequests::class, $port);

        $port->open(self::acmeFoods(), self::check(), self::omar(), self::draft());

        self::assertSame(1, $this->connection()->exists(self::stateKeyOf(self::ACME_FOODS)));
    }

    #[Test]
    public function anOpenedCheckIsQueuedForItsRequesterAloneForAnHour(): void
    {
        $this->requests()->open(self::acmeFoods(), self::check(), self::omar(), self::draft());

        self::assertEquals(IntakeCheckView::queued(), $this->requests()->stateOf(self::acmeFoods(), self::check(), self::omar()));
        self::assertNull($this->requests()->stateOf(self::acmeFoods(), self::check(), new UserId(self::LENA)));
        self::assertNull($this->requests()->stateOf(new OrganizationId(self::BETA_BAKERY), self::check(), self::omar()));
        $this->assertTimeToLiveIsAbout(3600, self::stateKeyOf(self::ACME_FOODS));
    }

    /**
     * The poll reads the small state alone: the draft waits under its own key with the same expiry, its multibyte text
     * kept as it is rather than escaped to \uXXXX.
     */
    #[Test]
    public function anOpenedCheckKeepsItsDraftUnderItsOwnKeyWithTheSameExpiryAndUnescaped(): void
    {
        $this->requests()->open(self::acmeFoods(), self::check(), self::omar(), new DraftReport(self::SHOP_PROJECT, "Bezahlen geht nicht \u{2014} ".self::MARKER, "Pin \u{00E9}"));

        $state = $this->connection()->get(self::stateKeyOf(self::ACME_FOODS));
        $draft = $this->connection()->get(self::draftKeyOf(self::ACME_FOODS));
        self::assertIsString($state);
        self::assertIsString($draft);
        self::assertStringNotContainsString(self::MARKER, $state);
        self::assertStringNotContainsString('draft', $state);
        self::assertStringContainsString("Bezahlen geht nicht \u{2014} ".self::MARKER, $draft);
        self::assertStringContainsString("Pin \u{00E9}", $draft);
        self::assertStringNotContainsString('\u', $draft);
        $this->assertTimeToLiveIsAbout(3600, self::draftKeyOf(self::ACME_FOODS));
    }

    /**
     * The draft key holds a list, which a GET would answer with WRONGTYPE; the poll still answers, so it never asks for
     * the draft.
     */
    #[Test]
    public function thePollDoesNotReadTheDraftKey(): void
    {
        $this->requests()->open(self::acmeFoods(), self::check(), self::omar(), self::draft());
        $this->connection()->del(self::draftKeyOf(self::ACME_FOODS));
        $this->connection()->rPush(self::draftKeyOf(self::ACME_FOODS), 'not a string');

        self::assertEquals(IntakeCheckView::queued(), $this->requests()->stateOf(self::acmeFoods(), self::check(), self::omar()));
    }

    #[Test]
    public function aSecondOpenOfTheSameCheckIsRefusedAndKeepsTheFirst(): void
    {
        $this->requests()->open(self::acmeFoods(), self::check(), self::omar(), self::draft());
        $before = $this->everyValue();

        try {
            $this->requests()->open(self::acmeFoods(), self::check(), new UserId(self::LENA), new DraftReport(self::SHOP_PROJECT, 'Other', 'Other.'));
            self::fail('A second open replaced the check.');
        } catch (\RuntimeException $refusal) {
            self::assertSame('The intake check state could not be opened.', $refusal->getMessage());
        }

        self::assertSame($before, $this->everyValue());
    }

    /**
     * Omar has three checks open, so a fourth is refused and writes nothing; Lena, and Omar at Beta Bakery, each count
     * on their own.
     */
    #[Test]
    public function aFourthOpenCheckOfOnePersonIsRefusedAndWritesNothing(): void
    {
        $this->openOmarsFirstThreeChecks();
        $before = $this->everyValue();
        self::assertCount(7, $before, 'Three states, three drafts and the open set.');

        try {
            $this->requests()->open(self::acmeFoods(), new IntakeCheckId(self::FOURTH_CHECK), self::omar(), self::draft());
            self::fail('A fourth check was opened.');
        } catch (TooManyOpenIntakeChecks $refusal) {
            self::assertSame('At most 3 intake checks may be open per person.', $refusal->getMessage());
        }

        self::assertSame($before, $this->everyValue());
        $this->requests()->open(self::acmeFoods(), new IntakeCheckId(self::FOURTH_CHECK), new UserId(self::LENA), self::draft());
        $this->requests()->open(new OrganizationId(self::BETA_BAKERY), new IntakeCheckId(self::FOURTH_CHECK), self::omar(), self::draft());
        $this->assertTimeToLiveIsAbout(3600, self::openChecksKeyOf(self::ACME_FOODS, self::OMAR));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ends(): iterable
    {
        yield 'a release' => ['release'];
        yield 'a failure' => ['failure'];
    }

    #[Test]
    #[DataProvider('ends')]
    public function aCheckThatEndedNoLongerCountsAgainstTheCap(string $end): void
    {
        $this->openOmarsFirstThreeChecks();

        match ($end) {
            'release' => $this->requests()->release(self::acmeFoods(), self::check(), self::omar()),
            'failure' => $this->requests()->recordFailure(self::acmeFoods(), self::check(), self::omar(), self::FAILED_SENTENCE),
            default => throw new \LogicException('No end is called '.$end.'.'),
        };
        $this->requests()->open(self::acmeFoods(), new IntakeCheckId(self::FOURTH_CHECK), self::omar(), self::draft());

        self::assertEquals(IntakeCheckView::queued(), $this->requests()->stateOf(self::acmeFoods(), new IntakeCheckId(self::FOURTH_CHECK), self::omar()));
        self::assertEqualsCanonicalizing(
            [self::SECOND_CHECK, self::THIRD_CHECK, self::FOURTH_CHECK],
            $this->connection()->zRange(self::openChecksKeyOf(self::ACME_FOODS, self::OMAR), 0, -1),
        );
    }

    /**
     * The open-check key holds a plain string, which the scripts' sorted-set calls refuse with WRONGTYPE.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function writes(): iterable
    {
        yield 'an open' => ['open', 'The intake check state could not be opened.'];
        yield 'a release' => ['release', 'The intake check could not be released.'];
        yield 'a failure' => ['failure', 'The failed intake check could not be stored.'];
    }

    #[Test]
    #[DataProvider('writes')]
    public function aWriteOverAnOpenCheckKeyThisClassDidNotWriteFailsAndChangesNothing(string $write, string $expectedMessage): void
    {
        if ('open' !== $write) {
            $this->requests()->open(self::acmeFoods(), self::check(), self::omar(), self::draft());
        }
        $this->connection()->set(self::openChecksKeyOf(self::ACME_FOODS, self::OMAR), 'not a sorted set');
        $before = $this->everyValue();

        try {
            match ($write) {
                'open' => $this->requests()->open(self::acmeFoods(), self::check(), self::omar(), self::draft()),
                'release' => $this->requests()->release(self::acmeFoods(), self::check(), self::omar()),
                'failure' => $this->requests()->recordFailure(self::acmeFoods(), self::check(), self::omar(), self::FAILED_SENTENCE),
                default => throw new \LogicException('No write is called '.$write.'.'),
            };
            self::fail('A write went through over an open-check key of another type.');
        } catch (\RuntimeException $failure) {
            self::assertSame($expectedMessage, $failure->getMessage());
        }

        self::assertSame($before, $this->everyValue());
        self::assertNull($this->connection()->getLastError(), 'The script error stayed on the connection for the next caller.');
    }

    /**
     * A check whose queued state would have expired by now counts no more, though no end reached it.
     */
    #[Test]
    public function aCheckOpenForAnHourNoLongerCountsAgainstTheCap(): void
    {
        $this->openOmarsFirstThreeChecks();
        $this->clock->sleep(3599);

        try {
            $this->requests()->open(self::acmeFoods(), new IntakeCheckId(self::FOURTH_CHECK), self::omar(), self::draft());
            self::fail('A fourth check was opened before the first three expired.');
        } catch (TooManyOpenIntakeChecks $refusal) {
            self::assertSame('At most 3 intake checks may be open per person.', $refusal->getMessage());
        }
        $this->clock->sleep(1);
        $this->requests()->open(self::acmeFoods(), new IntakeCheckId(self::FOURTH_CHECK), self::omar(), self::draft());

        self::assertSame([self::FOURTH_CHECK], $this->connection()->zRange(self::openChecksKeyOf(self::ACME_FOODS, self::OMAR), 0, -1));
    }

    /**
     * The mark hands the draft over exactly as it was kept, multibyte text, CRLF and a BOM included, and forgets it,
     * so the draft lives at most until the job takes it.
     */
    #[Test]
    public function theMarkHandsOverTheDraftOnceForgetsItAndKeepsTheStateAndItsExpiry(): void
    {
        $draft = new DraftReport(self::SHOP_PROJECT, "\u{FEFF}Bezahlen geht nicht \u{2014} ".self::MARKER, "Line one\r\nLine two \u{1F600}", ['', "Pin \u{00E9}\r\n"]);
        $this->requests()->open(self::acmeFoods(), self::check(), self::omar(), $draft);
        $this->connection()->expire(self::stateKeyOf(self::ACME_FOODS), 1800);

        self::assertEquals($draft, $this->requests()->markAiMayBeAsked(self::acmeFoods(), self::check(), self::omar()));
        self::assertNull($this->requests()->markAiMayBeAsked(self::acmeFoods(), self::check(), self::omar()));

        self::assertSame(0, $this->connection()->exists(self::draftKeyOf(self::ACME_FOODS)));
        self::assertEquals(IntakeCheckView::queued(), $this->requests()->stateOf(self::acmeFoods(), self::check(), self::omar()));
        $this->assertTimeToLiveIsAbout(1800, self::stateKeyOf(self::ACME_FOODS));
    }

    #[Test]
    public function aDraftWithoutPinsComesBackWithAnEmptyListOfPinNotes(): void
    {
        $this->requests()->open(self::acmeFoods(), self::check(), self::omar(), new DraftReport(self::SHOP_PROJECT, 'Pay does nothing', ''));

        self::assertEquals(new DraftReport(self::SHOP_PROJECT, 'Pay does nothing', '', []), $this->requests()->markAiMayBeAsked(self::acmeFoods(), self::check(), self::omar()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function statesThatMayNotAsk(): iterable
    {
        yield 'no check' => ['none'];
        yield 'a failed check' => ['failed'];
        yield 'a check Lena opened' => ['opened by Lena'];
        yield 'another organization\'s check' => ['opened at Beta Bakery'];
        yield 'a queued check whose draft is gone' => ['queued without its draft'];
    }

    #[Test]
    #[DataProvider('statesThatMayNotAsk')]
    public function aCheckWithNoQueuedStateOfTheRequesterIsNotMarkedAndKeepsItsState(string $state): void
    {
        match ($state) {
            'none' => null,
            'failed' => $this->requests()->recordFailure(self::acmeFoods(), self::check(), self::omar(), self::FAILED_SENTENCE),
            'opened by Lena' => $this->requests()->open(self::acmeFoods(), self::check(), new UserId(self::LENA), self::draft()),
            'opened at Beta Bakery' => $this->requests()->open(new OrganizationId(self::BETA_BAKERY), self::check(), self::omar(), self::draft()),
            'queued without its draft' => $this->openWithoutItsDraft(),
            default => throw new \LogicException('No state is called '.$state.'.'),
        };
        $before = $this->everyValue();

        self::assertNull($this->requests()->markAiMayBeAsked(self::acmeFoods(), self::check(), self::omar()));

        self::assertSame($before, $this->everyValue());
    }

    #[Test]
    public function aMarkOverAValueTheScriptCannotDecodeFailsAndLeavesTheValue(): void
    {
        $this->connection()->set(self::stateKeyOf(self::ACME_FOODS), 'not json');

        try {
            $this->requests()->markAiMayBeAsked(self::acmeFoods(), self::check(), self::omar());
            self::fail('A mark went through over a value the script cannot decode.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The intake check could not be marked as one that may have asked the AI.', $failure->getMessage());
        }

        self::assertSame('not json', $this->connection()->get(self::stateKeyOf(self::ACME_FOODS)));
        self::assertNull($this->connection()->getLastError(), 'The script error stayed on the connection for the next caller.');
    }

    #[Test]
    public function aMarkOfAQueuedStateWithAnUnreadableDraftFails(): void
    {
        $this->connection()->set(self::stateKeyOf(self::ACME_FOODS), '{"status": "queued", "requesterId": "'.self::OMAR.'", "at": 1805104800, "sentence": null}');
        $this->connection()->set(self::draftKeyOf(self::ACME_FOODS), '{"title": 7}');

        try {
            $this->requests()->markAiMayBeAsked(self::acmeFoods(), self::check(), self::omar());
            self::fail('An unreadable draft was handed over.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The intake check state holds an unreadable draft.', $failure->getMessage());
        }
    }

    #[Test]
    public function aFailureReplacesTheQueuedStateDropsItsDraftAndClosesItForADay(): void
    {
        $this->requests()->open(self::acmeFoods(), self::check(), self::omar(), self::draft());
        $this->requests()->open(self::acmeFoods(), new IntakeCheckId(self::SECOND_CHECK), self::omar(), self::draft());

        $this->requests()->recordFailure(self::acmeFoods(), self::check(), self::omar(), self::FAILED_SENTENCE);

        self::assertEquals(IntakeCheckView::failed(self::FAILED_SENTENCE), $this->requests()->stateOf(self::acmeFoods(), self::check(), self::omar()));
        self::assertNull($this->requests()->stateOf(self::acmeFoods(), self::check(), new UserId(self::LENA)));
        self::assertSame(0, $this->connection()->exists(self::draftKeyOf(self::ACME_FOODS)));
        self::assertSame([self::SECOND_CHECK], $this->connection()->zRange(self::openChecksKeyOf(self::ACME_FOODS, self::OMAR), 0, -1));
        self::assertNull($this->requests()->markAiMayBeAsked(self::acmeFoods(), self::check(), self::omar()));
        $this->assertTimeToLiveIsAbout(86400, self::stateKeyOf(self::ACME_FOODS));
    }

    #[Test]
    public function aReleasedCheckHasNoStateDraftOrOpenEntryLeft(): void
    {
        $this->requests()->open(self::acmeFoods(), self::check(), self::omar(), self::draft());
        $this->requests()->open(new OrganizationId(self::BETA_BAKERY), self::check(), self::omar(), self::draft());

        $this->requests()->release(self::acmeFoods(), self::check(), self::omar());

        self::assertNull($this->requests()->stateOf(self::acmeFoods(), self::check(), self::omar()));
        self::assertSame(0, $this->connection()->exists(self::stateKeyOf(self::ACME_FOODS), self::draftKeyOf(self::ACME_FOODS), self::openChecksKeyOf(self::ACME_FOODS, self::OMAR)));
        self::assertSame(
            3,
            $this->connection()->exists(self::stateKeyOf(self::BETA_BAKERY), self::draftKeyOf(self::BETA_BAKERY), self::openChecksKeyOf(self::BETA_BAKERY, self::OMAR)),
            'The release reached another organization\'s check.',
        );
    }

    #[Test]
    public function aCheckNeverOpenedHasNoState(): void
    {
        self::assertNull($this->requests()->stateOf(self::acmeFoods(), self::check(), self::omar()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableValues(): iterable
    {
        yield 'not JSON' => ['not json'];
        yield 'a JSON string' => ['"queued"'];
        yield 'nested deeper than a state' => ['{"status": "queued", "requesterId": "'.self::OMAR.'", "at": 1805104800, "sentence": {"text": "nested"}}'];
        yield 'no requester' => ['{"status": "queued", "at": 1805104800, "sentence": null}'];
        yield 'an unknown status' => ['{"status": "running", "requesterId": "'.self::OMAR.'", "at": 1805104800, "sentence": null}'];
        yield 'a failure without its sentence' => ['{"status": "failed", "requesterId": "'.self::OMAR.'", "at": 1805104800, "sentence": null}'];
        yield 'a failure with a blank sentence' => ['{"status": "failed", "requesterId": "'.self::OMAR.'", "at": 1805104800, "sentence": " "}'];
    }

    #[Test]
    #[DataProvider('unreadableValues')]
    public function aValueThisClassDidNotWriteIsRefusedOnRead(string $unreadableValue): void
    {
        $this->connection()->set(self::stateKeyOf(self::ACME_FOODS), $unreadableValue);

        try {
            $this->requests()->stateOf(self::acmeFoods(), self::check(), self::omar());
            self::fail('An unreadable state was read.');
        } catch (\RuntimeException $refusal) {
            self::assertStringStartsWith('The intake check state holds ', $refusal->getMessage());
        }
    }

    private function requests(): IntakeRequests
    {
        return new ValkeyIntakeRequests($this->connection(), $this->clock);
    }

    private function openOmarsFirstThreeChecks(): void
    {
        foreach ([self::CHECK, self::SECOND_CHECK, self::THIRD_CHECK] as $checkId) {
            $this->requests()->open(self::acmeFoods(), new IntakeCheckId($checkId), self::omar(), self::draft());
        }
    }

    private function openWithoutItsDraft(): void
    {
        $this->requests()->open(self::acmeFoods(), self::check(), self::omar(), self::draft());
        $this->connection()->del(self::draftKeyOf(self::ACME_FOODS));
    }

    private function assertTimeToLiveIsAbout(int $expectedSeconds, string $key): void
    {
        $milliseconds = $this->connection()->pttl($key);
        self::assertIsInt($milliseconds);
        self::assertGreaterThan(($expectedSeconds - 5) * 1000, $milliseconds);
        self::assertLessThanOrEqual($expectedSeconds * 1000, $milliseconds);
    }

    /**
     * @return array<string, mixed> every intake key of both organizations with its value (a sorted set with its
     *                              members and scores), by key, sorted
     */
    private function everyValue(): array
    {
        $values = [];
        foreach ($this->intakeKeys() as $key) {
            $values[$key] = \Redis::REDIS_ZSET === $this->connection()->type($key)
                ? $this->connection()->zRange($key, 0, -1, ['withscores' => true])
                : $this->connection()->get($key);
        }
        ksort($values);

        return $values;
    }

    /**
     * @return list<string>
     */
    private function intakeKeys(): array
    {
        $keys = [];
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            foreach (['intake_request', 'intake_draft', 'intake_open'] as $prefix) {
                $found = $this->connection()->keys($prefix.':'.$organizationId.':*');
                $keys = [...$keys, ...(\is_array($found) ? $found : [])];
            }
        }

        return $keys;
    }

    private function forgetStates(): void
    {
        $keys = $this->intakeKeys();
        if ([] !== $keys) {
            $this->connection()->del($keys);
        }
    }

    private static function stateKeyOf(string $organizationId): string
    {
        return 'intake_request:'.$organizationId.':'.self::CHECK;
    }

    private static function draftKeyOf(string $organizationId): string
    {
        return 'intake_draft:'.$organizationId.':'.self::CHECK;
    }

    private static function openChecksKeyOf(string $organizationId, string $requesterId): string
    {
        return 'intake_open:'.$organizationId.':'.$requesterId;
    }

    private static function draft(): DraftReport
    {
        return new DraftReport(self::SHOP_PROJECT, 'Pay does nothing '.self::MARKER, 'Pressing Pay does nothing.', ['The button.']);
    }

    private static function acmeFoods(): OrganizationId
    {
        return new OrganizationId(self::ACME_FOODS);
    }

    private static function check(): IntakeCheckId
    {
        return new IntakeCheckId(self::CHECK);
    }

    private static function omar(): UserId
    {
        return new UserId(self::OMAR);
    }

    private function connection(): \Redis
    {
        return self::getContainer()->get('state_valkey.connection');
    }
}
