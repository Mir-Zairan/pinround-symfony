<?php

declare(strict_types=1);

namespace App\Review\UI\Component;

use App\Media\Api\MediaImageStatus;
use App\Media\Api\SignedUrls;
use App\Media\Api\UploadedMedia;
use App\Organization\Api\CurrentActor;
use App\Review\Api\RoundCommands;
use App\Review\UI\PinView;
use App\Review\UI\ScreenshotView;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The Screenshot section of a form that reports a problem (P05.T5; Report a problem reuses it in P06.T1): one picture
 * uploaded or pasted through the presigned-upload controller, its title and summary, and pins with notes on it. It
 * sits inside the caller's form and posts screenshot_media_id, screenshot_title, screenshot_summary and
 * pins[n][x], pins[n][y], pins[n][note], the shape NewTicketReport reads.
 *
 * After a refused submit it shows `postedReport` again. The title and summary are always kept. The picture and its pins
 * are kept only when the picture is the logged-in person's own upload of their organization and not rejected; a
 * pending one shows "Processing…". A malformed field is left out, never an error. Renders inside the request
 * transaction, whose tenant is the logged-in person's organization.
 */
#[AsTwigComponent(name: 'ScreenshotReportField', template: '@Review/components/ScreenshotReportField.html.twig')]
final class ScreenshotReportField
{
    public const string PINS_NONE = 'Pins are optional, but they help the developer find the problem fast.';
    public const string PINS_BECOME_CHECKS = 'Each pin becomes a checklist item for the developer and the tester.';
    public const string VARIANT = 'view';
    public const string ALT_TEXT = 'The screenshot you added';
    public const string NOTE_PLACEHOLDER = 'What is wrong here?';
    public const string CAPTURE_HELP = 'Open the page with the problem and click the pinround extension’s icon. It takes the screenshot and makes a report draft with it, which you check and send. To get the extension, ask your admin.';

    /** A media id is a UUID; anything longer is malformed and is never looked up. */
    private const int MEDIA_ID_MAX_LENGTH = 36;

    /** What the upload is for, as the media_upload intent names it: a MediaTargetAuthorizer kind and an id. */
    public string $uploadTargetKind;
    public string $uploadTargetId;

    /** @var array<mixed> the fields as posted: media_id, title, summary and pins; empty on a fresh form */
    public array $postedReport = [];

    /** Why the server refused the screenshot part, shown above it and tied to its fields. */
    public ?string $error = null;

    /** Gives the section's elements unique ids; lowercase letters, digits and dashes. */
    public string $fieldId = 'screenshot';

    /** Report a problem offers "Capture with pinround", which explains the browser extension (P10.T8 step 3). */
    public bool $offersCapture = false;

    public string $title = '';
    public string $summary = '';

    /** The kept picture's media id, or '' when none is kept. */
    public string $mediaId = '';

    /** The kept picture with its pins, or a picture-less view with no pins when none is kept. */
    public ScreenshotView $view;

    public function __construct(
        private readonly UploadedMedia $uploadedMedia,
        private readonly SignedUrls $signedUrls,
        private readonly CurrentActor $currentActor,
        private readonly Security $security,
        private readonly ClockInterface $clock,
    ) {
    }

    #[PostMount]
    public function keepPostedReport(): void
    {
        $this->title = self::textOf($this->postedReport['title'] ?? null);
        $this->summary = self::textOf($this->postedReport['summary'] ?? null);
        $this->view = $this->keptPicture() ?? $this->viewWithoutPicture();
    }

    public function hasPicture(): bool
    {
        return '' !== $this->mediaId;
    }

    public function pinCountText(): string
    {
        $count = \count($this->view->pins);
        if (0 === $count) {
            return self::PINS_NONE;
        }

        return \sprintf('%d %s. %s', $count, 1 === $count ? 'pin' : 'pins', self::PINS_BECOME_CHECKS);
    }

    public function titleMaxLength(): int
    {
        return ScreenshotView::TITLE_MAX_LENGTH;
    }

    public function summaryMaxLength(): int
    {
        return ScreenshotView::SUMMARY_MAX_LENGTH;
    }

    public function notePlaceholder(): string
    {
        return self::NOTE_PLACEHOLDER;
    }

    public function noteMaxLength(): int
    {
        return PinView::NOTE_MAX_LENGTH;
    }

    /** "screenshot-error" with the default fieldId; the new ticket form's functional tests read the error by it. */
    public function errorId(): string
    {
        return $this->fieldId.'-error';
    }

    public function placerId(): string
    {
        return $this->fieldId.'-placer';
    }

    public function frameId(): string
    {
        return $this->fieldId.'-frame';
    }

    private function keptPicture(): ?ScreenshotView
    {
        $postedMediaId = $this->postedReport['media_id'] ?? null;
        if (!\is_string($postedMediaId) || '' === $postedMediaId || \strlen($postedMediaId) > self::MEDIA_ID_MAX_LENGTH) {
            return null;
        }
        $actor = $this->currentActor->actorFor($this->security->getUser());
        if (null === $actor) {
            return null;
        }
        $mediaId = strtolower($postedMediaId);
        $state = $this->uploadedMedia->statesOf($actor->organizationId(), $actor->userId(), [$mediaId])[$mediaId] ?? null;
        if (null === $state || MediaImageStatus::Rejected === $state) {
            return null;
        }
        $pins = $this->postedPins();
        if (MediaImageStatus::Pending === $state) {
            $this->mediaId = $mediaId;

            return new ScreenshotView($this->fieldId, null, null, null, self::ALT_TEXT, pins: $pins, status: MediaImageStatus::Pending);
        }

        $image = $this->signedUrls->forVariants($actor->organizationId(), [$mediaId], self::VARIANT, $this->clock->now())[$mediaId] ?? null;
        if (null === $image || MediaImageStatus::Rejected === $image->status) {
            return null;
        }
        $this->mediaId = $mediaId;
        if (MediaImageStatus::Ready !== $image->status || null === $image->url || null === $image->width || null === $image->height) {
            return new ScreenshotView($this->fieldId, null, null, null, self::ALT_TEXT, pins: $pins, status: MediaImageStatus::Pending);
        }

        return new ScreenshotView($this->fieldId, $image->url, $image->width, $image->height, self::ALT_TEXT, pins: $pins);
    }

    private function viewWithoutPicture(): ScreenshotView
    {
        return new ScreenshotView($this->fieldId, null, null, null, self::ALT_TEXT, status: MediaImageStatus::Pending);
    }

    /**
     * The well-formed posted pins, at most RoundCommands::MAX_PINS_PER_SCREENSHOT; one with a number below 1, a spot
     * that is not a plain decimal from 0 to 100, or a note that is not a string is left out; a note that is not UTF-8
     * counts as empty. A note past the limit is cut, as the pin-placer controller cuts it.
     *
     * @return list<PinView>
     */
    private function postedPins(): array
    {
        $postedPins = $this->postedReport['pins'] ?? null;
        if (!\is_array($postedPins)) {
            return [];
        }

        $pins = [];
        foreach (\array_slice($postedPins, 0, RoundCommands::MAX_PINS_PER_SCREENSHOT, true) as $number => $postedPin) {
            if (!\is_int($number) || $number < 1 || !\is_array($postedPin)) {
                continue;
            }
            $xPercent = self::percentOf($postedPin['x'] ?? null);
            $yPercent = self::percentOf($postedPin['y'] ?? null);
            $note = $postedPin['note'] ?? '';
            if (null === $xPercent || null === $yPercent || !\is_string($note)) {
                continue;
            }
            $pins[] = new PinView($number, $xPercent, $yPercent, mb_substr(self::textOf($note), 0, PinView::NOTE_MAX_LENGTH));
        }

        return $pins;
    }

    private static function percentOf(mixed $posted): ?float
    {
        if (!\is_string($posted) || 1 !== preg_match(PinView::POSTED_PERCENT_PATTERN, $posted)) {
            return null;
        }
        $percent = (float) $posted;

        return $percent <= 100.0 ? $percent : null;
    }

    /**
     * Posted text as the field shows it again. Anything but a UTF-8 string counts as empty, as Twig's escaper throws on
     * other bytes; a NUL byte, which HTML cannot carry, is dropped.
     */
    private static function textOf(mixed $posted): string
    {
        if (!\is_string($posted) || !mb_check_encoding($posted, 'UTF-8')) {
            return '';
        }

        return str_replace(["\0", "\r\n", "\r"], ['', "\n", "\n"], $posted);
    }
}
