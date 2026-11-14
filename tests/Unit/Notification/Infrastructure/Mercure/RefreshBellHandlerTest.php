<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Mercure;

use App\Notification\Application\Realtime\RefreshBell;
use App\Notification\Infrastructure\Mercure\RefreshBellHandler;
use App\SharedKernel\Application\MessageForAnotherTenantRefused;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Unit\Notification\Application\Notify\OnceOnlyHandlingDouble;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The bell push of Omar and Mia in Acme Foods, on 20 Mar 2027, with a stand-in stream template that prints what the
 * handler gives it; RefreshBellRoutingTest renders the real one. The inbox counts only the rows of the organization
 * set, as row-level security does, so Beta Bakery's Omar has a count of his own that Acme Foods never reads.
 */
final class RefreshBellHandlerTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000be001';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000be002';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000be011';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000be012';
    private const string NOW = '2027-03-20 10:00:00 UTC';

    private TenantContext $tenantContext;

    private TenantScopedBellInbox $bellInbox;

    /** @var list<Update> */
    private array $published = [];

    private ?\RuntimeException $nextPublishFailure = null;

    private OnceOnlyHandlingDouble $onceOnlyHandling;

    private RefreshBellHandler $handler;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $this->bellInbox = new TenantScopedBellInbox($this->tenantContext, [
            self::ACME_FOODS => [self::OMAR => 3, self::MIA => 7],
            self::BETA_BAKERY => [self::OMAR => 40],
        ]);
        $hub = new MockHub('https://localhost/.well-known/mercure', new StaticTokenProvider('publisher-token'), function (Update $update): string {
            if (null !== $this->nextPublishFailure) {
                $failure = $this->nextPublishFailure;
                $this->nextPublishFailure = null;

                throw $failure;
            }
            $this->published[] = $update;

            return 'urn:uuid:published';
        });
        $twig = new Environment(new ArrayLoader([
            RefreshBellHandler::TEMPLATE => 'count={{ openCount }};toast={{ toast ?? "none" }}',
        ]));
        $this->onceOnlyHandling = new OnceOnlyHandlingDouble();
        $this->onceOnlyHandling->messageId = 'message-1';
        $this->handler = new RefreshBellHandler($hub, $this->bellInbox, $this->tenantContext, $twig, new MockClock(new \DateTimeImmutable(self::NOW)), $this->onceOnlyHandling);
    }

    #[Test]
    public function thePushIsAPrivateTurboStreamOnTheUsersOwnTopicWithHisCount(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));

        ($this->handler)(new RefreshBell(self::ACME_FOODS, self::OMAR, null));

        self::assertCount(1, $this->published);
        $update = $this->published[0];
        self::assertSame(['https://pinround.app/o/'.self::ACME_FOODS.'/u/'.self::OMAR], $update->getTopics());
        self::assertTrue($update->isPrivate());
        self::assertNull($update->getType(), 'The default SSE type "message" is what the page renders as a Turbo Stream.');
        self::assertSame('count=3;toast=none', $update->getData());
        self::assertSame([[self::OMAR, self::NOW]], $this->bellInbox->countedFor);
    }

    #[Test]
    public function aToastGoesIntoThePush(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));

        ($this->handler)(new RefreshBell(self::ACME_FOODS, self::OMAR, 'Lena answered your question.'));

        self::assertSame(['count=3;toast=Lena answered your question.'], array_map(static fn (Update $update): string => $update->getData(), $this->published));
    }

    #[Test]
    public function aPushDeliveredTwicePublishesItsToastOnce(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $push = new RefreshBell(self::ACME_FOODS, self::OMAR, 'Lena answered your question.');

        ($this->handler)($push);
        ($this->handler)($push);

        self::assertSame(['count=3;toast=Lena answered your question.'], array_map(static fn (Update $update): string => $update->getData(), $this->published));
        self::assertSame([RefreshBellHandler::HANDLER_NAME, RefreshBellHandler::HANDLER_NAME], $this->onceOnlyHandling->handlerNames);
    }

    #[Test]
    public function aPushTheHubRefusedIsPublishedByTheNextDelivery(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $push = new RefreshBell(self::ACME_FOODS, self::OMAR, 'Lena answered your question.');
        $this->nextPublishFailure = new \RuntimeException('The hub is down.');

        try {
            ($this->handler)($push);
            self::fail('The refused publish did not fail the delivery.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The hub is down.', $failure->getMessage());
        }
        self::assertSame([], $this->publishedData());

        ($this->handler)($push);

        self::assertSame(['count=3;toast=Lena answered your question.'], $this->publishedData());
    }

    /**
     * The data of the updates the hub took so far; read through a call, as the hub's callback adds to them behind the
     * analyser's back.
     *
     * @return list<string>
     */
    private function publishedData(): array
    {
        return array_map(static fn (Update $update): string => $update->getData(), $this->published);
    }

    #[Test]
    public function eachPushGoesOnlyToItsOwnUsersTopicWithThatUsersCount(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));

        ($this->handler)(new RefreshBell(self::ACME_FOODS, self::MIA, null));
        $this->onceOnlyHandling->messageId = 'message-2';
        ($this->handler)(new RefreshBell(self::ACME_FOODS, self::OMAR, 'Lena answered your question.'));

        self::assertSame([
            [['https://pinround.app/o/'.self::ACME_FOODS.'/u/'.self::MIA], 'count=7;toast=none'],
            [['https://pinround.app/o/'.self::ACME_FOODS.'/u/'.self::OMAR], 'count=3;toast=Lena answered your question.'],
        ], array_map(static fn (Update $update): array => [$update->getTopics(), $update->getData()], $this->published));
    }

    #[Test]
    public function aPushForAnotherOrganizationThanTheStampedOneIsRefusedBeforeAnyRead(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));

        try {
            ($this->handler)(new RefreshBell(self::BETA_BAKERY, self::OMAR, 'Lena answered your question.'));
            self::fail('The push for Beta Bakery ran under Acme Foods.');
        } catch (MessageForAnotherTenantRefused $refusal) {
            self::assertSame('A message for another organization cannot be dispatched or handled while this organization is set.', $refusal->getMessage());
        }

        self::assertSame([], $this->published);
        self::assertSame([], $this->bellInbox->countedFor);
        self::assertSame([], $this->onceOnlyHandling->handlerNames);
    }

    #[Test]
    public function aPushWithNoOrganizationSetIsRefusedBeforeAnyRead(): void
    {
        try {
            ($this->handler)(new RefreshBell(self::ACME_FOODS, self::OMAR, null));
            self::fail('The push ran with no organization set.');
        } catch (TenantNotSet $refusal) {
            self::assertSame('No organization is set for this unit of work.', $refusal->getMessage());
        }

        self::assertSame([], $this->published);
        self::assertSame([], $this->bellInbox->countedFor);
    }
}
