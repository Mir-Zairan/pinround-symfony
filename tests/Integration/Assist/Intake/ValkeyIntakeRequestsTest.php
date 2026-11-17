<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assist\Intake;

use App\Assist\Api\DraftReport;
use App\Assist\Api\IntakeCheckId;
use App\Assist\Api\IntakeCheckView;
use App\Assist\Application\Intake\IntakeRequests;
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
 * that must be gone from the state once the job took it.
 */
final class ValkeyIntakeRequestsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000c9b01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000c9b02';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000c9b41';
    private const string CHECK = '0197a3c0-0000-7000-8000-0000000c9b31';
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

        self::assertSame(1, $this->connection()->exists(self::keyOf(self::ACME_FOODS)));
    }

    #[Test]
    public function anOpenedCheckIsQueuedForItsRequesterAloneForAnHour(): void
    {
        $this->requests()->open(self::acmeFoods(), self::check(), self::omar(), self::draft());

        self::assertEquals(IntakeCheckView::queued(), $this->requests()->stateOf(self::acmeFoods(), self::check(), self::omar()));
        self::assertNull($this->requests()->stateOf(self::acmeFoods(), self::check(), new UserId(self::LENA)));
        self::assertNull($this->requests()->stateOf(new OrganizationId(self::BETA_BAKERY), self::check(), self::omar()));
        $this->assertTimeToLiveIsAbout(3600, self::keyOf(self::ACME_FOODS));
    }

    #[Test]
    public function aSecondOpenOfTheSameCheckIsRefusedAndKeepsTheFirst(): void
    {
        $this->requests()->open(self::acmeFoods(), self::check(), self::omar(), self::draft());
        $before = $this->connection()->get(self::keyOf(self::ACME_FOODS));

        try {
            $this->requests()->open(self::acmeFoods(), self::check(), new UserId(self::LENA), new DraftReport(self::SHOP_PROJECT, 'Other', 'Other.'));
            self::fail('A second open replaced the check.');
        } catch (\RuntimeException $refusal) {
            self::assertSame('The intake check state could not be opened.', $refusal->getMessage());
        }

        self::assertSame($before, $this->connection()->get(self::keyOf(self::ACME_FOODS)));
    }

    /**
     * The mark hands the draft over exactly as it was kept, multibyte text, CRLF and a BOM included, and forgets it,
     * so the draft lives in the state at most until the job takes it.
     */
    #[Test]
    public function theMarkHandsOverTheDraftOnceForgetsItAndKeepsTheStateAndItsExpiry(): void
    {
        $draft = new DraftReport(self::SHOP_PROJECT, "\u{FEFF}Bezahlen geht nicht \u{2014} ".self::MARKER, "Line one\r\nLine two \u{1F600}", ['', "Pin \u{00E9}\r\n"]);
        $this->requests()->open(self::acmeFoods(), self::check(), self::omar(), $draft);
        $this->connection()->expire(self::keyOf(self::ACME_FOODS), 1800);

        self::assertEquals($draft, $this->requests()->markAiMayBeAsked(self::acmeFoods(), self::check(), self::omar()));
        self::assertNull($this->requests()->markAiMayBeAsked(self::acmeFoods(), self::check(), self::omar()));

        $held = $this->connection()->get(self::keyOf(self::ACME_FOODS));
        self::assertIsString($held);
        self::assertStringNotContainsString(self::MARKER, $held);
        self::assertStringNotContainsString('"draft"', $held);
        self::assertEquals(IntakeCheckView::queued(), $this->requests()->stateOf(self::acmeFoods(), self::check(), self::omar()));
        $this->assertTimeToLiveIsAbout(1800, self::keyOf(self::ACME_FOODS));
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
            default => throw new \LogicException('No state is called '.$state.'.'),
        };
        $before = [$this->connection()->get(self::keyOf(self::ACME_FOODS)), $this->connection()->get(self::keyOf(self::BETA_BAKERY))];

        self::assertNull($this->requests()->markAiMayBeAsked(self::acmeFoods(), self::check(), self::omar()));

        self::assertSame($before, [$this->connection()->get(self::keyOf(self::ACME_FOODS)), $this->connection()->get(self::keyOf(self::BETA_BAKERY))]);
    }

    #[Test]
    public function aMarkOverAValueTheScriptCannotDecodeFailsAndLeavesTheValue(): void
    {
        $this->connection()->set(self::keyOf(self::ACME_FOODS), 'not json');

        try {
            $this->requests()->markAiMayBeAsked(self::acmeFoods(), self::check(), self::omar());
            self::fail('A mark went through over a value the script cannot decode.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The intake check could not be marked as one that may have asked the AI.', $failure->getMessage());
        }

        self::assertSame('not json', $this->connection()->get(self::keyOf(self::ACME_FOODS)));
        self::assertNull($this->connection()->getLastError(), 'The script error stayed on the connection for the next caller.');
    }

    #[Test]
    public function aMarkOfAQueuedStateWithAnUnreadableDraftFailsAndLeavesTheValue(): void
    {
        $unreadable = '{"status": "queued", "requesterId": "'.self::OMAR.'", "at": 1805104800, "sentence": null, "draft": "{\"title\": 7}"}';
        $this->connection()->set(self::keyOf(self::ACME_FOODS), $unreadable);

        try {
            $this->requests()->markAiMayBeAsked(self::acmeFoods(), self::check(), self::omar());
            self::fail('An unreadable draft was handed over.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The intake check state holds an unreadable draft.', $failure->getMessage());
        }
    }

    #[Test]
    public function aFailureReplacesTheQueuedStateAndItsDraftWithItsSentenceForADay(): void
    {
        $this->requests()->open(self::acmeFoods(), self::check(), self::omar(), self::draft());

        $this->requests()->recordFailure(self::acmeFoods(), self::check(), self::omar(), self::FAILED_SENTENCE);

        self::assertEquals(IntakeCheckView::failed(self::FAILED_SENTENCE), $this->requests()->stateOf(self::acmeFoods(), self::check(), self::omar()));
        self::assertNull($this->requests()->stateOf(self::acmeFoods(), self::check(), new UserId(self::LENA)));
        $held = $this->connection()->get(self::keyOf(self::ACME_FOODS));
        self::assertIsString($held);
        self::assertStringNotContainsString(self::MARKER, $held);
        self::assertNull($this->requests()->markAiMayBeAsked(self::acmeFoods(), self::check(), self::omar()));
        $this->assertTimeToLiveIsAbout(86400, self::keyOf(self::ACME_FOODS));
    }

    #[Test]
    public function aReleasedCheckHasNoStateLeft(): void
    {
        $this->requests()->open(self::acmeFoods(), self::check(), self::omar(), self::draft());
        $this->requests()->open(new OrganizationId(self::BETA_BAKERY), self::check(), self::omar(), self::draft());

        $this->requests()->release(self::acmeFoods(), self::check());

        self::assertNull($this->requests()->stateOf(self::acmeFoods(), self::check(), self::omar()));
        self::assertSame(0, $this->connection()->exists(self::keyOf(self::ACME_FOODS)));
        self::assertSame(1, $this->connection()->exists(self::keyOf(self::BETA_BAKERY)), 'The release reached another organization\'s check.');
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
        $this->connection()->set(self::keyOf(self::ACME_FOODS), $unreadableValue);

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

    private function assertTimeToLiveIsAbout(int $expectedSeconds, string $key): void
    {
        $milliseconds = $this->connection()->pttl($key);
        self::assertIsInt($milliseconds);
        self::assertGreaterThan(($expectedSeconds - 5) * 1000, $milliseconds);
        self::assertLessThanOrEqual($expectedSeconds * 1000, $milliseconds);
    }

    private function forgetStates(): void
    {
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $this->connection()->del(self::keyOf($organizationId));
        }
    }

    private static function keyOf(string $organizationId): string
    {
        return 'intake_request:'.$organizationId.':'.self::CHECK;
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
