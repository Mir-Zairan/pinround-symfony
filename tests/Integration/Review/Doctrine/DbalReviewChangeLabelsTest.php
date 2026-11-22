<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Doctrine;

use App\Review\Infrastructure\Doctrine\DbalReviewChangeLabels;
use App\SharedKernel\Application\TenantContext;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\ReviewedPin;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The pin numbers and area names Review gives the ticket's history, on the real tables under row-level security.
 */
final class DbalReviewChangeLabelsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f9d01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f9d02';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000f9d03';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f9d04';
    private const string REPORT = '0197a3c0-0000-7000-8000-0000000f9d11';
    private const string CHECKLIST = '0197a3c0-0000-7000-8000-0000000f9d12';
    private const string REVIEW = '0197a3c0-0000-7000-8000-0000000f9d13';
    private const string BETA_REVIEW = '0197a3c0-0000-7000-8000-0000000f9d14';
    private const string CART_BEFORE = '0197a3c0-0000-7000-8000-0000000f9d21';
    private const string MENU_BEFORE = '0197a3c0-0000-7000-8000-0000000f9d22';
    private const string CART_AFTER = '0197a3c0-0000-7000-8000-0000000f9d23';
    private const string CART_AFTER_ZOOMED = '0197a3c0-0000-7000-8000-0000000f9d24';
    private const string BETA_PICTURE = '0197a3c0-0000-7000-8000-0000000f9d25';
    private const string BETA_AFTER = '0197a3c0-0000-7000-8000-0000000f9d26';
    private const string TOTAL_PIN = '0197a3c0-0000-7000-8000-0000000f9d31';
    private const string MENU_PIN = '0197a3c0-0000-7000-8000-0000000f9d32';
    private const string BETA_PIN = '0197a3c0-0000-7000-8000-0000000f9d33';
    private const string TOTAL_ITEM = '0197a3c0-0000-7000-8000-0000000f9d41';
    private const string BETA_ITEM = '0197a3c0-0000-7000-8000-0000000f9d42';
    private const string TOTALS_AREA = '0197a3c0-0000-7000-8000-0000000f9d51';
    private const string ZOOMED_AREA = '0197a3c0-0000-7000-8000-0000000f9d52';
    private const string BETA_AREA = '0197a3c0-0000-7000-8000-0000000f9d53';
    private const string UNKNOWN = '0197a3c0-0000-7000-8000-0000000f9d99';
    private const string MEDIA = '0197a3c0-0000-7000-8000-0000000f9d61';
    private const string CREATED_AT = '2027-03-15 10:00:00+00';

    protected function setUp(): void
    {
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->roundRows()->addAllOf(self::ACME_FOODS, [
            self::round(self::ACME_FOODS, self::REPORT, 'report', 0),
            self::round(self::ACME_FOODS, self::CHECKLIST, 'checklist', null),
            self::round(self::ACME_FOODS, self::REVIEW, 'review', 2),
            self::screenshot(self::ACME_FOODS, self::CART_BEFORE, self::REPORT, 0),
            self::screenshot(self::ACME_FOODS, self::MENU_BEFORE, self::REPORT, 1),
            self::screenshot(self::ACME_FOODS, self::CART_AFTER, self::REVIEW, 0),
            self::screenshot(self::ACME_FOODS, self::CART_AFTER_ZOOMED, self::REVIEW, 1),
            self::pin(self::ACME_FOODS, self::TOTAL_PIN, self::CART_BEFORE, 3),
            self::pin(self::ACME_FOODS, self::MENU_PIN, self::MENU_BEFORE, 1),
            self::checkItem(self::ACME_FOODS, self::TOTAL_ITEM, self::CHECKLIST, self::TOTAL_PIN),
            // Inserted second at the higher position, so the area that names the pin is picked by position, not by row order.
            self::area(self::ACME_FOODS, self::ZOOMED_AREA, self::REVIEW, 'Zoomed totals', 1, self::CART_BEFORE, self::CART_AFTER_ZOOMED),
            self::area(self::ACME_FOODS, self::TOTALS_AREA, self::REVIEW, 'Totals', 0, self::CART_BEFORE, self::CART_AFTER),
        ]);
        $this->roundRows()->addAllOf(self::BETA_BAKERY, [
            self::round(self::BETA_BAKERY, self::BETA_REVIEW, 'review', 1),
            self::screenshot(self::BETA_BAKERY, self::BETA_PICTURE, self::BETA_REVIEW, 0),
            self::screenshot(self::BETA_BAKERY, self::BETA_AFTER, self::BETA_REVIEW, 1),
            self::pin(self::BETA_BAKERY, self::BETA_PIN, self::BETA_PICTURE, 7),
            self::checkItem(self::BETA_BAKERY, self::BETA_ITEM, self::BETA_REVIEW, self::BETA_PIN),
            self::area(self::BETA_BAKERY, self::BETA_AREA, self::BETA_REVIEW, 'Bread list', 0, self::BETA_PICTURE, self::BETA_AFTER),
        ]);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function aCheckItemIsNamedByItsPinsNumber(): void
    {
        self::assertSame(3, $this->asAcmeFoods(fn (): ?int => $this->labels()->checkItemPinNumberOf(self::TOTAL_ITEM)));
    }

    #[Test]
    public function aReviewedPinIsNamedByItsNumberAndTheFirstAreaOfTheRoundThatComparesItsPicture(): void
    {
        self::assertEquals(new ReviewedPin(3, 'Totals'), $this->asAcmeFoods(fn (): ?ReviewedPin => $this->labels()->reviewedPinOf(self::REVIEW, self::TOTAL_PIN)));
    }

    #[Test]
    public function aPinNoAreaOfTheRoundComparesHasNoLabel(): void
    {
        self::assertNull($this->asAcmeFoods(fn (): ?ReviewedPin => $this->labels()->reviewedPinOf(self::REVIEW, self::MENU_PIN)));
        self::assertNull($this->asAcmeFoods(fn (): ?ReviewedPin => $this->labels()->reviewedPinOf(self::CHECKLIST, self::TOTAL_PIN)));
    }

    #[Test]
    public function anAreaIsNamedByItsName(): void
    {
        self::assertSame('Zoomed totals', $this->asAcmeFoods(fn (): ?string => $this->labels()->areaNameOf(self::ZOOMED_AREA)));
    }

    #[Test]
    public function anotherOrganizationsIdsHaveNoLabel(): void
    {
        self::assertSame(
            [null, null, null],
            $this->asAcmeFoods(fn (): array => [
                $this->labels()->checkItemPinNumberOf(self::BETA_ITEM),
                $this->labels()->reviewedPinOf(self::BETA_REVIEW, self::BETA_PIN),
                $this->labels()->areaNameOf(self::BETA_AREA),
            ]),
        );
        self::assertSame(7, $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): ?int => $this->labels()->checkItemPinNumberOf(self::BETA_ITEM)), 'The rows exist in their own organization.');
    }

    #[Test]
    public function unknownAndMalformedIdsHaveNoLabel(): void
    {
        self::assertSame(
            [null, null, null, null, null, null, null],
            $this->asAcmeFoods(fn (): array => [
                $this->labels()->checkItemPinNumberOf(self::UNKNOWN),
                $this->labels()->reviewedPinOf(self::REVIEW, self::UNKNOWN),
                $this->labels()->areaNameOf(self::UNKNOWN),
                $this->labels()->checkItemPinNumberOf('not-a-uuid'),
                $this->labels()->reviewedPinOf('not-a-uuid', self::TOTAL_PIN),
                $this->labels()->reviewedPinOf(self::REVIEW, 'not-a-uuid'),
                $this->labels()->areaNameOf(''),
            ]),
        );
    }

    #[Test]
    public function labelsAreReadOnlyInATransaction(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('none is open');

        $this->labels()->areaNameOf(self::TOTALS_AREA);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $read
     *
     * @return T
     */
    private function asAcmeFoods(\Closure $read): mixed
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, $read);
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function round(string $organizationId, string $roundId, string $kind, ?int $number): array
    {
        return ['rvw_round', [
            'organization_id' => $organizationId, 'id' => $roundId, 'ticket_id' => self::TICKET, 'owner_id' => self::LENA, 'kind' => $kind,
            'number' => $number, 'shared_at' => self::CREATED_AT, 'state' => 'open', 'version' => 1, 'created_at' => self::CREATED_AT,
        ]];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function screenshot(string $organizationId, string $screenshotId, string $roundId, int $position): array
    {
        return ['rvw_screenshot', [
            'organization_id' => $organizationId, 'id' => $screenshotId, 'round_id' => $roundId, 'media_id' => self::MEDIA,
            'position' => $position, 'created_at' => self::CREATED_AT,
        ]];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function pin(string $organizationId, string $pinId, string $screenshotId, int $number): array
    {
        return ['rvw_pin', [
            'organization_id' => $organizationId, 'id' => $pinId, 'screenshot_id' => $screenshotId, 'number' => $number, 'x_pct' => '40.00',
            'y_pct' => '60.00', 'note' => 'Looks off', 'source' => 'human', 'author_id' => self::LENA, 'created_at' => self::CREATED_AT,
        ]];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function checkItem(string $organizationId, string $itemId, string $roundId, string $pinId): array
    {
        return ['rvw_check_item', [
            'organization_id' => $organizationId, 'id' => $itemId, 'round_id' => $roundId, 'pin_id' => $pinId, 'state' => 'open',
            'created_at' => self::CREATED_AT,
        ]];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function area(string $organizationId, string $areaId, string $roundId, string $name, int $position, string $beforeId, string $afterId): array
    {
        return ['rvw_area', [
            'organization_id' => $organizationId, 'id' => $areaId, 'round_id' => $roundId, 'name' => $name, 'position' => $position,
            'before_screenshot_id' => $beforeId, 'after_screenshot_id' => $afterId, 'created_at' => self::CREATED_AT,
        ]];
    }

    private function labels(): DbalReviewChangeLabels
    {
        return new DbalReviewChangeLabels($this->entityManager()->getConnection(), self::getContainer()->get(TenantContext::class));
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function roundRows(): RoundRows
    {
        return new RoundRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
