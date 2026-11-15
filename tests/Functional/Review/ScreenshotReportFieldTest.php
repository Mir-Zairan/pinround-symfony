<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Media\Api\MediaImageStatus;
use App\Media\Application\SignedVariantUrls;
use App\Media\Application\UploaderMediaStates;
use App\Organization\Api\ActorContext;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\Roles;
use App\Organization\Infrastructure\Security\RequestScopedCurrentActor;
use App\Review\Api\RoundCommands;
use App\Review\UI\Component\ScreenshotReportField;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Review\Application\InMemoryUploadedMedia;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * The Screenshot section of the new ticket form, as a fresh form and as the form shows it again after a refused
 * submit. Lena of Acme Foods is logged in; Acme Foods holds a ready, a pending and a rejected picture of hers and a
 * ready one of Omar's, Beta Bakery a ready one of hers. The clock is fixed in March 2027.
 */
final class ScreenshotReportFieldTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f6701';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f6702';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f6711';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f6712';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000f6721';
    private const string LENAS_READY_MEDIA = '0197a3c0-0000-7000-8000-0000000f6741';
    private const string LENAS_PENDING_MEDIA = '0197a3c0-0000-7000-8000-0000000f6742';
    private const string LENAS_REJECTED_MEDIA = '0197a3c0-0000-7000-8000-0000000f6743';
    private const string OMARS_READY_MEDIA = '0197a3c0-0000-7000-8000-0000000f6744';
    private const string LENAS_MEDIA_AT_BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f6745';
    private const string UNKNOWN_MEDIA = '0197a3c0-0000-7000-8000-0000000f6749';
    private const string NOW = '2027-03-15 10:20:30 UTC';
    private const string SIGNED_AT = '2027-03-15T10:20:00+00:00';
    private const string PINS_TWO = '2 pins. Each pin becomes a checklist item for the developer and the tester.';

    /** Whether Lena is logged in; the actor is set right before the render, as a used container service is fixed. */
    private bool $isLenaLoggedIn = true;
    private InMemoryUploadedMedia $uploadedMedia;
    private RecordingSignedUrls $signedUrls;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $request = Request::create('https://localhost/tickets/new');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);
        $container->set('clock', new MockClock(self::NOW));
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $this->uploadedMedia = new InMemoryUploadedMedia($this->tenantContext(), [
            [self::ACME_FOODS, self::LENAS_READY_MEDIA, self::LENA, MediaImageStatus::Ready],
            [self::ACME_FOODS, self::LENAS_PENDING_MEDIA, self::LENA, MediaImageStatus::Pending],
            [self::ACME_FOODS, self::LENAS_REJECTED_MEDIA, self::LENA, MediaImageStatus::Rejected],
            [self::ACME_FOODS, self::OMARS_READY_MEDIA, self::OMAR, MediaImageStatus::Ready],
            [self::BETA_BAKERY, self::LENAS_MEDIA_AT_BETA_BAKERY, self::LENA, MediaImageStatus::Ready],
        ]);
        $container->set(UploaderMediaStates::class, $this->uploadedMedia);
        $this->signedUrls = new RecordingSignedUrls($this->tenantContext(), [
            [self::ACME_FOODS, self::LENAS_READY_MEDIA, MediaImageStatus::Ready],
            [self::ACME_FOODS, self::LENAS_PENDING_MEDIA, MediaImageStatus::Pending],
            [self::ACME_FOODS, self::LENAS_REJECTED_MEDIA, MediaImageStatus::Rejected],
            [self::ACME_FOODS, self::OMARS_READY_MEDIA, MediaImageStatus::Ready],
            [self::BETA_BAKERY, self::LENAS_MEDIA_AT_BETA_BAKERY, MediaImageStatus::Ready],
        ]);
        $container->set(SignedVariantUrls::class, $this->signedUrls);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('request_stack')->pop();
        $this->tenantContext()->clear();
        parent::tearDown();
    }

    #[Test]
    public function aFreshFormOffersTheUploadAndHidesThePictureArea(): void
    {
        $section = $this->renderField();

        self::assertSame('Screenshot', $section->filter('h2#screenshot-heading')->text());
        self::assertSame('screenshot-heading', $section->attr('aria-labelledby'));
        self::assertSame('presigned-upload screenshot-report', $section->attr('data-controller'));
        self::assertSame('new_ticket', $section->attr('data-presigned-upload-target-kind-value'));
        self::assertSame(self::SHOP_TEAM, $section->attr('data-presigned-upload-target-id-value'));
        self::assertSame('/media/intents', $section->attr('data-presigned-upload-url-value'));
        self::assertNotSame('', (string) $section->attr('data-presigned-upload-csrf-token-value'));
        self::assertSame('#screenshot-placer', $section->attr('data-screenshot-report-pin-placer-outlet'));
        self::assertNull($section->attr('data-media-state'));
        self::assertSame('Upload a file', $section->filter('label[for="screenshot-file"]')->text());
        self::assertCount(1, $section->filter('input#screenshot-file[type="file"][accept="image/png,image/jpeg,image/webp"][data-presigned-upload-target="input"]'));
        self::assertSame('', $section->filter('input[type="hidden"][name="screenshot_media_id"][data-presigned-upload-target="mediaId"]')->attr('value'));
        self::assertCount(1, $section->filter('[data-screenshot-report-target="picture"][hidden]'));
        self::assertCount(1, $section->filter('button[data-screenshot-report-target="remove"][hidden]'));
        self::assertSame('Remove screenshot', $section->filter('button[data-screenshot-report-target="remove"]')->text());
        self::assertCount(0, $section->filter('[data-screenshot-report-target="empty"][hidden]'));
        $preview = $section->filter('#screenshot-frame img[data-presigned-upload-target="preview"]');
        self::assertCount(1, $preview);
        self::assertNull($preview->attr('src'));
        self::assertNotNull($preview->attr('hidden'));
        self::assertCount(1, $section->filter('div.pin-placer#screenshot-placer[data-controller="pin-placer"] #screenshot-frame [data-pin-placer-target="layer"]'));
        self::assertCount(0, $section->filter('.pin'));
        self::assertCount(0, $section->filter('.screenshot__placeholder'));
        self::assertSame('Click on the screenshot to drop a pin. Then say what is wrong at that pin.', $section->filter('.screenshot-report__hint')->text());
        self::assertSame(ScreenshotReportField::PINS_NONE, $section->filter('[data-screenshot-report-target="count"]')->text());
        self::assertSame('What is wrong here?', $section->filter('.pin-placer')->attr('data-pin-placer-note-placeholder-value'));
        self::assertSame('', $section->filter('input#screenshot-title[name="screenshot_title"][maxlength="120"]')->attr('value'));
        self::assertSame('', $section->filter('textarea#screenshot-summary[name="screenshot_summary"][maxlength="600"]')->text());
        self::assertSame('Picture title · optional', $section->filter('label[for="screenshot-title"]')->text());
        self::assertSame('Picture summary · optional', $section->filter('label[for="screenshot-summary"]')->text());
        self::assertCount(0, $section->filter('#screenshot-error'));
        self::assertCount(0, $section->filter('[aria-describedby]'));
        self::assertSame([], $this->uploadedMedia->askedMediaIds, 'No media id was posted, so none is read.');
    }

    #[Test]
    public function theReportersOwnReadyPictureComesBackWithItsPinsTitleAndSummary(): void
    {
        $section = $this->renderField([
            'media_id' => strtoupper(self::LENAS_READY_MEDIA),
            'title' => 'Checkout total',
            'summary' => "The total is cut off.\r\nOnly on small screens.",
            'pins' => [
                3 => ['x' => '80.25', 'y' => '10', 'note' => 'Cut off here'],
                1 => ['x' => '12.50', 'y' => '40.00', 'note' => ''],
            ],
        ]);

        self::assertSame(self::LENAS_READY_MEDIA, $section->filter('input[name="screenshot_media_id"]')->attr('value'));
        self::assertSame([[self::LENAS_READY_MEDIA]], $this->uploadedMedia->askedMediaIds);
        self::assertSame([[self::LENAS_READY_MEDIA, 'view', self::SIGNED_AT]], $this->signedUrls->asked);
        $image = $section->filter('#screenshot-frame img.screenshot__image[data-presigned-upload-target="preview"]');
        self::assertSame('https://storage.test/view/'.self::LENAS_READY_MEDIA.'?at='.self::SIGNED_AT, $image->attr('src'));
        self::assertSame('1600', $image->attr('width'));
        self::assertSame('900', $image->attr('height'));
        self::assertNull($image->attr('hidden'));
        self::assertSame(ScreenshotReportField::ALT_TEXT, $image->attr('alt'));
        self::assertCount(0, $section->filter('[data-screenshot-report-target="picture"][hidden]'));
        self::assertCount(0, $section->filter('button[data-screenshot-report-target="remove"][hidden]'));
        self::assertCount(1, $section->filter('[data-screenshot-report-target="empty"][hidden]'));
        self::assertCount(0, $section->filter('.screenshot__placeholder'));
        $markers = $section->filter('#screenshot-frame .pin');
        self::assertSame(['1', '3'], $markers->each(static fn (Crawler $marker): string => (string) $marker->attr('data-pin-number')));
        self::assertSame(['12.50', '80.25'], $markers->each(static fn (Crawler $marker): string => (string) $marker->attr('data-x-percent')));
        self::assertSame(['40.00', '10.00'], $markers->each(static fn (Crawler $marker): string => (string) $marker->attr('data-y-percent')));
        $notes = $section->filter('textarea.pin-list__note');
        self::assertSame(['pins[1][note]', 'pins[3][note]'], $notes->each(static fn (Crawler $note): string => (string) $note->attr('name')));
        self::assertSame(['', 'Cut off here'], $notes->each(static fn (Crawler $note): string => $note->text()));
        self::assertSame(['What is wrong here?', 'What is wrong here?'], $notes->each(static fn (Crawler $note): string => (string) $note->attr('placeholder')));
        self::assertSame(self::PINS_TWO, $section->filter('[data-screenshot-report-target="count"]')->text());
        self::assertSame('Checkout total', $section->filter('input[name="screenshot_title"]')->attr('value'));
        self::assertSame("The total is cut off.\nOnly on small screens.", $section->filter('textarea[name="screenshot_summary"]')->text(normalizeWhitespace: false));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function picturesThatAreNotKept(): iterable
    {
        yield 'another person\'s picture' => [self::OMARS_READY_MEDIA];
        yield 'the reporter\'s rejected picture' => [self::LENAS_REJECTED_MEDIA];
        yield 'the reporter\'s picture at another organization' => [self::LENAS_MEDIA_AT_BETA_BAKERY];
        yield 'an unknown picture' => [self::UNKNOWN_MEDIA];
        yield 'a malformed id' => ['not-a-media-id'];
    }

    #[Test]
    #[DataProvider('picturesThatAreNotKept')]
    public function aPictureThatIsNotTheReportersOwnUsableOneIsDroppedWithItsPinsButTheTextStays(string $mediaId): void
    {
        $section = $this->renderField([
            'media_id' => $mediaId,
            'title' => 'Checkout total',
            'summary' => 'The total is cut off.',
            'pins' => [1 => ['x' => '12.50', 'y' => '40.00', 'note' => 'Here']],
        ]);

        self::assertSame([[$mediaId]], $this->uploadedMedia->askedMediaIds);
        self::assertSame('', $section->filter('input[name="screenshot_media_id"]')->attr('value'));
        self::assertNull($section->filter('#screenshot-frame img')->attr('src'));
        self::assertStringNotContainsString('storage.test', $section->html());
        self::assertCount(1, $section->filter('[data-screenshot-report-target="picture"][hidden]'));
        self::assertCount(1, $section->filter('button[data-screenshot-report-target="remove"][hidden]'));
        self::assertCount(0, $section->filter('.pin'));
        self::assertCount(0, $section->filter('textarea.pin-list__note'));
        self::assertSame(ScreenshotReportField::PINS_NONE, $section->filter('[data-screenshot-report-target="count"]')->text());
        self::assertSame('Checkout total', $section->filter('input[name="screenshot_title"]')->attr('value'));
        self::assertSame('The total is cut off.', $section->filter('textarea[name="screenshot_summary"]')->text());
    }

    #[Test]
    public function withNoLoggedInActorNoPictureIsKeptAndNoneIsRead(): void
    {
        $this->isLenaLoggedIn = false;

        $section = $this->renderField(['media_id' => self::LENAS_READY_MEDIA, 'title' => 'Checkout total', 'summary' => '', 'pins' => [1 => ['x' => '1', 'y' => '2', 'note' => '']]]);

        self::assertSame('', $section->filter('input[name="screenshot_media_id"]')->attr('value'));
        self::assertCount(0, $section->filter('.pin'));
        self::assertSame([], $this->uploadedMedia->askedMediaIds);
        self::assertSame('Checkout total', $section->filter('input[name="screenshot_title"]')->attr('value'));
    }

    #[Test]
    public function theReportersPendingPictureShowsProcessingAndKeepsItsPins(): void
    {
        $section = $this->renderField([
            'media_id' => self::LENAS_PENDING_MEDIA,
            'title' => '',
            'summary' => '',
            'pins' => [1 => ['x' => '12.50', 'y' => '40.00', 'note' => 'Here'], 2 => ['x' => '50', 'y' => '50', 'note' => '']],
        ]);

        self::assertSame(self::LENAS_PENDING_MEDIA, $section->filter('input[name="screenshot_media_id"]')->attr('value'));
        self::assertSame([], $this->signedUrls->asked, 'A pending picture has no URL to sign.');
        $placeholder = $section->filter('#screenshot-frame.screenshot__frame--placeholder [data-screenshot-report-target="placeholder"]');
        self::assertSame('Processing…', $placeholder->text());
        self::assertSame('status', $placeholder->attr('role'));
        $image = $section->filter('#screenshot-frame img');
        self::assertNull($image->attr('src'));
        self::assertNotNull($image->attr('hidden'));
        self::assertNull($section->attr('data-media-state'), 'Nothing polls a re-rendered picture, so the submit is not held.');
        self::assertCount(0, $section->filter('[data-screenshot-report-target="picture"][hidden]'));
        self::assertSame(['1', '2'], $section->filter('.pin')->each(static fn (Crawler $marker): string => (string) $marker->attr('data-pin-number')));
        self::assertSame(self::PINS_TWO, $section->filter('[data-screenshot-report-target="count"]')->text());
    }

    #[Test]
    public function theErrorShowsAboveTheFieldsAndDescribesThem(): void
    {
        $section = $this->renderField(['media_id' => '', 'title' => 'Checkout total', 'summary' => '', 'pins' => []], 'Add a screenshot, or clear its title, summary and pins.');

        self::assertSame('Add a screenshot, or clear its title, summary and pins.', $section->filter('#screenshot-error')->text());
        self::assertSame(
            ['screenshot-file', 'screenshot-title', 'screenshot-summary'],
            $section->filter('[aria-describedby="screenshot-error"]')->each(static fn (Crawler $field): string => (string) $field->attr('id')),
        );
        self::assertSame(['h2', 'p'], \array_slice($section->children()->each(static fn (Crawler $child): string => $child->nodeName()), 0, 2));
    }

    #[Test]
    public function malformedPinsAreLeftOutAndTheWellFormedOnesKept(): void
    {
        $section = $this->renderField([
            'media_id' => self::LENAS_READY_MEDIA,
            'title' => '',
            'summary' => '',
            'pins' => [
                'first' => ['x' => '10', 'y' => '10', 'note' => 'key is not a number'],
                0 => ['x' => '10', 'y' => '10', 'note' => 'number 0'],
                2 => ['x' => '-5', 'y' => '10', 'note' => 'signed'],
                3 => ['x' => '101', 'y' => '10', 'note' => 'past the picture'],
                4 => ['x' => '1e2', 'y' => '10', 'note' => 'exponent'],
                5 => ['x' => '10', 'note' => 'no y'],
                6 => ['x' => '10', 'y' => '10', 'note' => ['a list']],
                7 => 'not a pin',
                8 => ['x' => ['10'], 'y' => '10', 'note' => 'x is a list'],
                9 => ['x' => '100', 'y' => '0', 'note' => "\xC3\x28"],
                10 => ['x' => '20.5', 'y' => '30', 'note' => str_repeat('é', 1001)],
                11 => ['x' => '40', 'y' => '60'],
            ],
        ]);

        self::assertSame(['9', '10', '11'], $section->filter('.pin')->each(static fn (Crawler $marker): string => (string) $marker->attr('data-pin-number')));
        $notes = $section->filter('textarea.pin-list__note')->each(static fn (Crawler $note): string => $note->text(normalizeWhitespace: false));
        self::assertSame(['', str_repeat('é', 1000), ''], $notes, 'A note that is not UTF-8 is empty and one past the limit is cut.');
        self::assertSame('3 pins. '.ScreenshotReportField::PINS_BECOME_CHECKS, $section->filter('[data-screenshot-report-target="count"]')->text());
    }

    #[Test]
    public function aTitleOrSummaryThatIsNotUtf8TextAndAMediaIdThatIsNotTextCountAsEmpty(): void
    {
        $section = $this->renderField([
            'media_id' => [self::LENAS_READY_MEDIA],
            'title' => ['Checkout total'],
            'summary' => "The total \xC3\x28 is cut off.",
            'pins' => 'not a list',
        ]);

        self::assertSame('', $section->filter('input[name="screenshot_title"]')->attr('value'));
        self::assertSame('', $section->filter('textarea[name="screenshot_summary"]')->text());
        self::assertSame('', $section->filter('input[name="screenshot_media_id"]')->attr('value'));
        self::assertSame([], $this->uploadedMedia->askedMediaIds);
        self::assertCount(0, $section->filter('.pin'));
    }

    #[Test]
    public function aNulByteIsDroppedFromTheTextShownAgain(): void
    {
        $section = $this->renderField(['media_id' => '', 'title' => "Check\0out", 'summary' => '', 'pins' => []]);

        self::assertSame('Checkout', $section->filter('input[name="screenshot_title"]')->attr('value'));
    }

    #[Test]
    public function atMostTheCapOfPinsIsShownAgain(): void
    {
        $pins = [];
        for ($number = 1; $number <= RoundCommands::MAX_PINS_PER_SCREENSHOT + 1; ++$number) {
            $pins[$number] = ['x' => '10', 'y' => '10', 'note' => ''];
        }

        $section = $this->renderField(['media_id' => self::LENAS_READY_MEDIA, 'title' => '', 'summary' => '', 'pins' => $pins]);

        self::assertCount(RoundCommands::MAX_PINS_PER_SCREENSHOT, $section->filter('.pin'));
        self::assertCount(0, $section->filter('.pin[data-pin-number="'.(RoundCommands::MAX_PINS_PER_SCREENSHOT + 1).'"]'));
    }

    /**
     * @param array<mixed> $postedReport
     */
    private function renderField(array $postedReport = [], ?string $error = null): Crawler
    {
        $this->useActor($this->isLenaLoggedIn ? new ActorContext(new UserId(self::LENA), new OrganizationId(self::ACME_FOODS), Roles::none()) : null);
        $html = (string) $this->renderTwigComponent(ScreenshotReportField::class, [
            'uploadTargetKind' => 'new_ticket',
            'uploadTargetId' => self::SHOP_TEAM,
            'postedReport' => $postedReport,
            'error' => $error,
        ]);
        $section = new Crawler($html)->filter('section#screenshot.screenshot-report');
        self::assertCount(1, $section);

        return $section;
    }

    private function useActor(?ActorContext $actor): void
    {
        self::getContainer()->set(RequestScopedCurrentActor::class, new readonly class($actor) implements CurrentActor, ResetInterface {
            public function __construct(private ?ActorContext $actor)
            {
            }

            public function actorFor(mixed $tokenUser): ?ActorContext
            {
                return $this->actor;
            }

            public function reset(): void
            {
            }
        });
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
