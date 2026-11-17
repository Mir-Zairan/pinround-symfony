<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Domain;

use App\Organization\Domain\OrgSettings;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[Test]
    public function changeAiSettingStoresTheChoicesValueOnlyAndGivesTheOneBefore(): void
    {
        $settings = $this->newSettings();
        $expected = $settings->data();
        $expected['ai']['where'] = 'laptop_first';
        $expected['ai']['on'] = false;

        self::assertSame('cloud', $settings->changeAiSetting('where', 'laptop_first'));
        self::assertTrue($settings->changeAiSetting('on', 'off'));
        self::assertNull($settings->changeAiSetting('restricted_to_cloud', 'on'));
        $expected['ai']['restricted_to_cloud'] = true;

        self::assertSame($expected, $settings->data());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedAiChoices(): iterable
    {
        yield 'an unknown setting' => ['model', 'qwen3:4b', '"model" is not an AI setting.'];
        yield 'the key fields' => ['secret_enc', 'on', '"secret_enc" is not an AI setting.'];
        yield 'an unknown choice' => ['where', 'moon', '"moon" is not a choice of the AI setting "where".'];
        yield 'a stored value instead of a choice' => ['on', '1', '"1" is not a choice of the AI setting "on".'];
        yield 'another case' => ['who', 'Everyone', '"Everyone" is not a choice of the AI setting "who".'];
    }

    #[Test]
    #[DataProvider('refusedAiChoices')]
    public function changeAiSettingRefusesWhatTheTabDoesNotOffer(string $setting, string $choice, string $reason): void
    {
        $settings = $this->newSettings();
        $before = $settings->data();

        try {
            $settings->changeAiSetting($setting, $choice);
            self::fail('A choice the tab does not offer was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame($reason, $exception->getMessage());
        }

        self::assertSame($before, $settings->data());
    }

    #[Test]
    public function changeAiSettingStartsAgainFromTheOneSettingWhenAiIsMissingOrNoObject(): void
    {
        $settings = $this->newSettings();
        foreach ([null, 'on', ['cloud', 'laptop']] as $unreadable) {
            $this->replaceAi($settings, $unreadable);

            self::assertNull($settings->changeAiSetting('who', 'leads_only'));
            self::assertSame(['who' => 'leads_only'], $settings->data()['ai']);
        }
    }

    #[Test]
    public function aStoredValueThatIsNeitherABoolNorAStringIsGivenAsNone(): void
    {
        $settings = $this->newSettings();
        $this->replaceAi($settings, ['on' => 1]);

        self::assertNull($settings->changeAiSetting('on', 'on'));
        self::assertSame(['on' => true], $settings->data()['ai']);
    }

    #[Test]
    public function changeAiProviderKeyStoresItInBase64WithTheKeyIdFromInsideIt(): void
    {
        $settings = $this->newSettings();
        self::assertFalse($settings->hasAiProviderKey());
        $expected = $settings->data();
        $encrypted = "\x04k-26".str_repeat("\xAB", 40);
        $expected['ai']['secret_enc'] = base64_encode($encrypted);
        $expected['ai']['key_id'] = 'k-26';

        $settings->changeAiProviderKey($encrypted);

        self::assertSame($expected, $settings->data());
        self::assertTrue($settings->hasAiProviderKey());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAnEncryptedKey(): iterable
    {
        yield 'empty' => [''];
        yield 'a key id length of zero' => ["\x00rest"];
        yield 'nothing after the key id' => ["\x04k-26"];
        yield 'shorter than its key id' => ["\x09k-26"];
    }

    #[Test]
    #[DataProvider('notAnEncryptedKey')]
    public function changeAiProviderKeyRefusesAValueNotInTheStoredFormat(string $encrypted): void
    {
        $settings = $this->newSettings();
        $before = $settings->data();

        try {
            $settings->changeAiProviderKey($encrypted);
            self::fail('A value without a key id was stored.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('An encrypted provider key starts with the id of its key.', $exception->getMessage());
        }

        self::assertSame($before, $settings->data());
        self::assertFalse($settings->hasAiProviderKey());
    }

    #[Test]
    public function aKeyIdAloneCountsAsASavedKey(): void
    {
        $settings = $this->newSettings();
        $this->replaceAi($settings, ['on' => true, 'key_id' => 'k-26']);

        self::assertTrue($settings->hasAiProviderKey());

        $this->replaceAi($settings, ['on' => true, 'secret_enc' => null, 'key_id' => null]);

        self::assertFalse($settings->hasAiProviderKey());
    }

    private function newSettings(): OrgSettings
    {
        return new OrgSettings(new OrganizationId(self::ACME_FOODS), new UserId(self::SARA), new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
    }

    /**
     * Sets data.ai as a row written before this code, or by hand, may hold it.
     */
    private function replaceAi(OrgSettings $settings, mixed $ai): void
    {
        $data = $settings->data();
        $data['ai'] = $ai;
        new \ReflectionProperty(OrgSettings::class, 'data')->setValue($settings, $data);
    }
}
