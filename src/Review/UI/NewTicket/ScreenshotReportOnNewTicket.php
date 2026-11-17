<?php

declare(strict_types=1);

namespace App\Review\UI\NewTicket;

use App\Review\Api\PlacedPin;
use App\Review\Api\RoundCommands;
use App\Review\Api\RoundRefused;
use App\Review\Api\ScreenshotWithPins;
use App\Review\Application\ReportPictures;
use App\Review\UI\PinView;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\NewTicketReport;
use App\Ticketing\Api\NewTicketReportRefused;

/**
 * NewTicketReport on RoundCommands::createReport, one screenshot per new ticket; the contract and the posted shape are
 * on {@see NewTicketReport}. The refusals createReport makes keep their RoundRefused message.
 */
final readonly class ScreenshotReportOnNewTicket implements NewTicketReport
{
    public const string REPORT_UNREADABLE = 'We could not read the screenshot part of the form. Try again.';
    public const string PIN_SPOT_UNREADABLE = 'Place each pin on the picture again.';
    public const string CONTENT_WITHOUT_SCREENSHOT = 'Add a screenshot, or clear its title, summary and pins.';
    public const string TOO_MANY_PINS = 'A picture holds at most '.RoundCommands::MAX_PINS_PER_SCREENSHOT.' pins.';

    public function __construct(
        private RoundCommands $roundCommands,
        private ReportPictures $reportPictures,
        private TenantContext $tenantContext,
    ) {
    }

    public function refusalOf(OrganizationId $organizationId, UserId $reporterId, array $postedReport): ?string
    {
        $this->requireTenant($organizationId);
        try {
            $screenshot = self::screenshotOf($postedReport);
        } catch (NewTicketReportRefused $unreadable) {
            return $unreadable->getMessage();
        }
        if (null === $screenshot) {
            return null;
        }

        return $this->reportPictures->refusalOf($organizationId, $reporterId, [$screenshot->mediaId]);
    }

    public function attach(OrganizationId $organizationId, UserId $reporterId, TicketId $ticketId, array $postedReport, \DateTimeImmutable $reportedAt): void
    {
        $this->requireTenant($organizationId);
        $screenshot = self::screenshotOf($postedReport);
        if (null === $screenshot) {
            return;
        }

        try {
            $this->roundCommands->createReport($ticketId, [$screenshot], $reporterId, $reportedAt);
        } catch (RoundRefused $refused) {
            throw new NewTicketReportRefused($refused->getMessage(), 0, $refused);
        }
    }

    /**
     * createReport saves under the request's tenant and never sees $organizationId, so a mismatch is refused here.
     *
     * @throws \LogicException when no tenant is set or it is not $organizationId
     */
    private function requireTenant(OrganizationId $organizationId): void
    {
        if (true !== $this->tenantContext->get()?->equals($organizationId)) {
            throw new \LogicException('The new ticket\'s report is read and saved under the tenant of its own organization.');
        }
    }

    /**
     * @param array<mixed> $postedReport
     *
     * @return ScreenshotWithPins|null null when no screenshot was posted
     *
     * @throws NewTicketReportRefused when the fields do not have the posted shape, or hold content but no screenshot
     */
    private static function screenshotOf(array $postedReport): ?ScreenshotWithPins
    {
        $mediaId = self::textOf($postedReport, 'media_id');
        $title = self::textOf($postedReport, 'title');
        $summary = self::textOf($postedReport, 'summary');
        $postedPins = $postedReport['pins'] ?? null;
        if (!\is_array($postedPins)) {
            throw new NewTicketReportRefused(self::REPORT_UNREADABLE);
        }
        // Before any pin is read, so a list sized from outside is never walked past the cap.
        if (\count($postedPins) > RoundCommands::MAX_PINS_PER_SCREENSHOT) {
            throw new NewTicketReportRefused(self::TOO_MANY_PINS);
        }
        if ('' === $mediaId) {
            if ('' !== trim($title) || '' !== trim($summary) || [] !== $postedPins) {
                throw new NewTicketReportRefused(self::CONTENT_WITHOUT_SCREENSHOT);
            }

            return null;
        }

        $pins = [];
        foreach ($postedPins as $number => $postedPin) {
            if (!\is_int($number) || !\is_array($postedPin)) {
                throw new NewTicketReportRefused(self::REPORT_UNREADABLE);
            }
            $pins[$number] = self::pinOf($postedPin);
        }
        ksort($pins);

        return new ScreenshotWithPins(
            $mediaId,
            '' === trim($title) ? null : $title,
            '' === trim($summary) ? null : $summary,
            array_values($pins),
        );
    }

    /**
     * @param array<mixed> $postedPin
     *
     * @throws NewTicketReportRefused when x or y is missing or not a plain decimal, or the note is not text
     */
    private static function pinOf(array $postedPin): PlacedPin
    {
        $xPercent = $postedPin['x'] ?? null;
        $yPercent = $postedPin['y'] ?? null;
        if (!\is_string($xPercent) || !\is_string($yPercent) || 1 !== preg_match(PinView::POSTED_PERCENT_PATTERN, $xPercent) || 1 !== preg_match(PinView::POSTED_PERCENT_PATTERN, $yPercent)) {
            throw new NewTicketReportRefused(self::PIN_SPOT_UNREADABLE);
        }

        return new PlacedPin((float) $xPercent, (float) $yPercent, \array_key_exists('note', $postedPin) ? self::textOf($postedPin, 'note') : '');
    }

    /**
     * PostgreSQL refuses a NUL byte or invalid UTF-8 in text, so neither may reach the insert.
     *
     * @param array<mixed> $fields
     *
     * @throws NewTicketReportRefused when the field is missing, not a string, not UTF-8 or holds a NUL byte
     */
    private static function textOf(array $fields, string $key): string
    {
        $text = $fields[$key] ?? null;
        if (!\is_string($text) || !mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
            throw new NewTicketReportRefused(self::REPORT_UNREADABLE);
        }

        // Browsers post a textarea's line breaks as CRLF, while its maxlength counts each as one character.
        return str_replace(["\r\n", "\r"], "\n", $text);
    }
}
