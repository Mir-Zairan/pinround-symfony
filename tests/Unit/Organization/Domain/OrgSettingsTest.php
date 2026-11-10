<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Domain;

use App\Organization\Domain\OrgSettings;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OrgSettingsTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000000a';
    private const string SARA = '0197a3c0-0000-7000-8000-000000000051';

    #[Test]
    public function newSettingsHoldTheDefaultsWithTheFirstAdminAsTriager(): void
    {
        $createdAt = new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
        $settings = new OrgSettings(new OrganizationId(self::ACME_FOODS), new UserId(self::SARA), $createdAt);

        self::assertSame(self::ACME_FOODS, $settings->organizationId()->toString());
        self::assertSame($createdAt, $settings->createdAt());
        self::assertSame(1, $settings->version());
        self::assertSame(self::SARA, $settings->triagerId()->toString());
        self::assertSame([
            'ping_limit_hours' => 4,
            'auto_clear' => true,
            'quiet_hours' => false,
            'lead_check' => false,
            'triager_id' => self::SARA,
            'step_policy' => 'extend',
            'ai' => ['on' => true, 'who' => 'everyone', 'send' => 'title_and_text', 'sync' => true, 'where' => 'cloud', 'model' => 'qwen3:4b'],
            'retention' => '2y',
        ], $settings->data());
    }

    #[Test]
    public function changeStepPolicyChangesThatSettingOnly(): void
    {
        $settings = new OrgSettings(new OrganizationId(self::ACME_FOODS), new UserId(self::SARA), new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
        $expected = $settings->data();
        $expected['step_policy'] = 'locked';

        $settings->changeStepPolicy('locked');

        self::assertSame($expected, $settings->data());
    }

    #[Test]
    public function changeStepPolicyRefusesAnUnknownPolicy(): void
    {
        $settings = new OrgSettings(new OrganizationId(self::ACME_FOODS), new UserId(self::SARA), new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));

        try {
            $settings->changeStepPolicy('open');
            self::fail('An unknown step policy was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('"open" is not a step policy.', $exception->getMessage());
        }

        self::assertSame('extend', $settings->data()['step_policy']);
    }
}
