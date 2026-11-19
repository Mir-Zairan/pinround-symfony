<?php

declare(strict_types=1);

namespace App\Review\Application\ReportDraft;

use App\Review\Api\PlacedPin;
use App\Review\Api\ReportDraftContent;
use App\Review\Api\ReportDraftRefused;
use App\Review\Api\ReportDrafts;
use App\Review\Api\RoundRefused;
use App\Review\Api\ScreenshotWithPins;
use App\Review\Domain\Pin;
use App\Review\Domain\Screenshot;

/**
 * The rules of a report draft's content, the same caps as a ticket and a round (FR-RND-16, ADR-0040). Lengths count
 * characters, as PostgreSQL's char_length does; titles, summaries, notes and the detail are trimmed, and a blank
 * picture title or summary is none.
 */
final class ReportDraftContentRules
{
    public const int TITLE_MAX_LENGTH = 200;
    public const int DETAIL_MAX_LENGTH = 50000;

    // Version nibble 7 and variant bits 10xx, as Media's ids are; \z, because $ would also accept a trailing newline.
    private const string CANONICAL_UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    /**
     * @return ReportDraftContent the content trimmed, with media ids in lowercase
     *
     * @throws ReportDraftRefused with the first rule it breaks, checked title, detail, then each picture in order
     */
    public static function checked(ReportDraftContent $content): ReportDraftContent
    {
        $title = trim($content->title);
        if ('' === $title) {
            throw ReportDraftRefused::because(ReportDraftRefused::TITLE_BLANK);
        }
        if (mb_strlen($title) > self::TITLE_MAX_LENGTH) {
            throw ReportDraftRefused::because(ReportDraftRefused::TITLE_TOO_LONG);
        }
        $detail = trim($content->detail);
        if (mb_strlen($detail) > self::DETAIL_MAX_LENGTH) {
            throw ReportDraftRefused::because(ReportDraftRefused::DETAIL_TOO_LONG);
        }
        if (\count($content->screenshots) > ReportDrafts::MAX_SCREENSHOTS) {
            throw ReportDraftRefused::because(ReportDraftRefused::TOO_MANY_SCREENSHOTS);
        }
        $screenshots = [];
        $mediaIds = [];
        foreach ($content->screenshots as $screenshot) {
            $checked = self::checkedScreenshot($screenshot);
            if (isset($mediaIds[$checked->mediaId])) {
                throw ReportDraftRefused::because(ReportDraftRefused::SAME_PICTURE_TWICE);
            }
            $mediaIds[$checked->mediaId] = true;
            $screenshots[] = $checked;
        }

        return new ReportDraftContent($title, $detail, $screenshots);
    }

    /**
     * @throws ReportDraftRefused with the first rule the picture breaks
     */
    public static function checkedScreenshot(ScreenshotWithPins $screenshot): ScreenshotWithPins
    {
        $mediaId = strtolower($screenshot->mediaId);
        if (1 !== preg_match(self::CANONICAL_UUID_V7, $mediaId)) {
            throw ReportDraftRefused::because(RoundRefused::MEDIA_NOT_UPLOADED_BY_YOU);
        }
        $title = self::blankToNull($screenshot->title);
        if (null !== $title && mb_strlen($title) > Screenshot::TITLE_MAX_LENGTH) {
            throw ReportDraftRefused::because(ReportDraftRefused::PICTURE_TITLE_TOO_LONG);
        }
        $summary = self::blankToNull($screenshot->summary);
        if (null !== $summary && mb_strlen($summary) > Screenshot::SUMMARY_MAX_LENGTH) {
            throw ReportDraftRefused::because(ReportDraftRefused::PICTURE_SUMMARY_TOO_LONG);
        }
        if (\count($screenshot->pins) > Screenshot::MAX_PINS) {
            throw ReportDraftRefused::because(ReportDraftRefused::TOO_MANY_PINS);
        }
        $pins = [];
        foreach ($screenshot->pins as $pin) {
            if (!is_finite($pin->xPercent) || !is_finite($pin->yPercent) || $pin->xPercent < 0 || $pin->xPercent > 100 || $pin->yPercent < 0 || $pin->yPercent > 100) {
                throw ReportDraftRefused::because(ReportDraftRefused::PIN_OUTSIDE);
            }
            $note = trim($pin->note);
            if (mb_strlen($note) > Pin::NOTE_MAX_LENGTH) {
                throw ReportDraftRefused::because(ReportDraftRefused::PIN_NOTE_TOO_LONG);
            }
            $pins[] = new PlacedPin(round($pin->xPercent, 2), round($pin->yPercent, 2), $note);
        }

        return new ScreenshotWithPins($mediaId, $title, $summary, $pins);
    }

    private static function blankToNull(?string $text): ?string
    {
        $trimmed = null === $text ? '' : trim($text);

        return '' === $trimmed ? null : $trimmed;
    }
}
