<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Api\Event\SettingsChanged;
use App\Organization\Application\ChangeAiSettings;
use App\Organization\Application\ChangeAiSettingsRefused;
use App\Organization\Domain\OrgSettings;
use App\Organization\Domain\OrgSettingsStore;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Encryption\SodiumFieldEncryption;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChangeAiSettingsTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000a0a';
    private const string SARA = '0197a3c0-0000-7000-8000-000000000a51';
    private const string AT = '2027-03-16 09:00:00 UTC';
    private const string PROVIDER_KEY = 'sk-test-0123456789abcdef';

    /**
     * @return iterable<string, array{string, string, bool|string|null, bool|string}>
     */
    public static function changes(): iterable
    {
        yield 'a choice' => ['where', 'laptop_first', 'cloud', 'laptop_first'];
        yield 'a switch turned off' => ['on', 'off', true, false];
        yield 'a setting new settings do not hold' => ['provider', 'anthropic', null, 'anthropic'];
    }

    #[Test]
    #[DataProvider('changes')]
    public function aChangeIsSavedOnceAndPublishedWithTheValueBeforeAndAfter(string $setting, string $choice, bool|string|null $before, bool|string $after): void
    {
        $store = self::storeWith(self::newSettings());
        $events = new RecordingOrganizationEvents();

        self::changeAiSettings($store, $events)->changeSetting(self::acmeFoods(), $setting, $choice, new UserId(self::SARA), new \DateTimeImmutable(self::AT));

        self::assertSame($after, self::aiOf($store)[$setting]);
        self::assertSame(1, $store->saves);
        self::assertEquals([new SettingsChanged(self::ACME_FOODS, 'ai.'.$setting, $before, $after, self::SARA, new \DateTimeImmutable(self::AT))], $events->published);
    }

    #[Test]
    public function choosingWhatIsStoredSavesAndPublishesNothing(): void
    {
        $store = self::storeWith(self::newSettings());
        $events = new RecordingOrganizationEvents();
        $before = $store->settings?->data();

        self::changeAiSettings($store, $events)->changeSetting(self::acmeFoods(), 'who', 'everyone', new UserId(self::SARA), new \DateTimeImmutable(self::AT));

        self::assertSame($before, $store->settings?->data());
        self::assertSame(0, $store->saves);
        self::assertSame([], $events->published);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function choicesTheTabDoesNotOffer(): iterable
    {
        yield 'an unknown setting' => ['model', 'qwen3:4b'];
        yield 'the encrypted key' => ['secret_enc', 'on'];
        yield 'the key id' => ['key_id', 'on'];
        yield 'an empty setting' => ['', 'on'];
        yield 'an unknown choice' => ['where', 'moon'];
        yield 'the stored value instead of the choice' => ['on', '1'];
        yield 'another case' => ['who', 'Everyone'];
        yield 'an empty choice' => ['send', ''];
    }

    #[Test]
    #[DataProvider('choicesTheTabDoesNotOffer')]
    public function aChoiceTheTabDoesNotOfferIsRefusedAndNothingChanges(string $setting, string $choice): void
    {
        $store = self::storeWith(self::newSettings());
        $events = new RecordingOrganizationEvents();
        $before = $store->settings?->data();

        try {
            self::changeAiSettings($store, $events)->changeSetting(self::acmeFoods(), $setting, $choice, new UserId(self::SARA), new \DateTimeImmutable(self::AT));
            self::fail('A choice the tab does not offer was accepted.');
        } catch (ChangeAiSettingsRefused $refusal) {
            self::assertSame(ChangeAiSettings::UNKNOWN_CHOICE, $refusal->getMessage());
        }

        self::assertSame($before, $store->settings?->data());
        self::assertSame(0, $store->saves);
        self::assertSame([], $events->published);
    }

    #[Test]
    public function anOrganizationWithoutSettingsIsRefusedAndNothingIsPublished(): void
    {
        $events = new RecordingOrganizationEvents();

        try {
            self::changeAiSettings(self::storeWith(null), $events)->changeSetting(self::acmeFoods(), 'on', 'off', new UserId(self::SARA), new \DateTimeImmutable(self::AT));
            self::fail('A change without settings was accepted.');
        } catch (\LogicException $exception) {
            self::assertSame('The organization has no settings.', $exception->getMessage());
        }

        self::assertSame([], $events->published);
    }

    /**
     * Surrounding spaces, tabs and line breaks (CRLF from a pasted line) are removed before it is encrypted.
     */
    #[Test]
    public function theProviderKeyIsStoredEncryptedWithItsKeyIdAndPublishedWithoutTheKey(): void
    {
        $store = self::storeWith(self::newSettings());
        $events = new RecordingOrganizationEvents();

        self::changeAiSettings($store, $events)->changeProviderKey(self::acmeFoods(), " \t".self::PROVIDER_KEY."\r\n", new UserId(self::SARA), new \DateTimeImmutable(self::AT));

        $ai = self::aiOf($store);
        self::assertIsString($ai['secret_enc']);
        $encrypted = base64_decode($ai['secret_enc'], true);
        self::assertIsString($encrypted);
        self::assertSame(self::PROVIDER_KEY, self::fieldEncryption()->decrypt($encrypted));
        self::assertSame('unit', $ai['key_id']);
        self::assertStringNotContainsString(self::PROVIDER_KEY, json_encode($store->settings?->data(), \JSON_THROW_ON_ERROR));
        self::assertSame(1, $store->saves);
        self::assertEquals([new SettingsChanged(self::ACME_FOODS, 'ai.provider_key', null, 'changed', self::SARA, new \DateTimeImmutable(self::AT))], $events->published);
    }

    /**
     * The old key is never decrypted to compare, so the same key again is saved and published again.
     */
    #[Test]
    public function everySavedProviderKeyCountsAsAChange(): void
    {
        $store = self::storeWith(self::newSettings());
        $events = new RecordingOrganizationEvents();
        $changeAiSettings = self::changeAiSettings($store, $events);

        $changeAiSettings->changeProviderKey(self::acmeFoods(), self::PROVIDER_KEY, new UserId(self::SARA), new \DateTimeImmutable(self::AT));
        $firstSecret = self::aiOf($store)['secret_enc'];
        $changeAiSettings->changeProviderKey(self::acmeFoods(), self::PROVIDER_KEY, new UserId(self::SARA), new \DateTimeImmutable(self::AT));

        self::assertNotSame($firstSecret, self::aiOf($store)['secret_enc']);
        self::assertSame(2, $store->saves);
        self::assertCount(2, $events->published);
    }

    #[Test]
    public function aProviderKeyOfExactlyTheLongestLengthIsStored(): void
    {
        $store = self::storeWith(self::newSettings());
        $longest = str_repeat('k', ChangeAiSettings::PROVIDER_KEY_MAX_LENGTH);

        self::changeAiSettings($store, new RecordingOrganizationEvents())->changeProviderKey(self::acmeFoods(), $longest, new UserId(self::SARA), new \DateTimeImmutable(self::AT));

        $secret = self::aiOf($store)['secret_enc'];
        self::assertIsString($secret);
        self::assertSame($longest, self::fieldEncryption()->decrypt((string) base64_decode($secret, true)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedProviderKeys(): iterable
    {
        yield 'empty' => ['', ChangeAiSettings::PROVIDER_KEY_MISSING];
        yield 'only spaces and line breaks' => [" \r\n\t ", ChangeAiSettings::PROVIDER_KEY_MISSING];
        yield 'one character too long' => [str_repeat('k', ChangeAiSettings::PROVIDER_KEY_MAX_LENGTH + 1), ChangeAiSettings::PROVIDER_KEY_TOO_LONG];
        yield 'a pasted page' => [str_repeat('k', 100_000), ChangeAiSettings::PROVIDER_KEY_TOO_LONG];
        yield 'a space inside' => ['sk-test 0123', ChangeAiSettings::PROVIDER_KEY_NOT_PRINTABLE];
        yield 'a line break inside' => ["sk-test\n0123", ChangeAiSettings::PROVIDER_KEY_NOT_PRINTABLE];
        yield 'an escape character' => ["sk-test\e[31m0123", ChangeAiSettings::PROVIDER_KEY_NOT_PRINTABLE];
        yield 'a byte order mark' => ["\u{FEFF}sk-test-0123", ChangeAiSettings::PROVIDER_KEY_NOT_PRINTABLE];
        yield 'a letter outside ASCII' => ['sk-tést-0123', ChangeAiSettings::PROVIDER_KEY_NOT_PRINTABLE];
        yield 'a line separator' => ["sk-test\u{2028}0123", ChangeAiSettings::PROVIDER_KEY_NOT_PRINTABLE];
    }

    #[Test]
    #[DataProvider('refusedProviderKeys')]
    public function aProviderKeyThatIsNoKeyIsRefusedAndNothingChanges(string $providerKey, string $reason): void
    {
        $store = self::storeWith(self::newSettings());
        $events = new RecordingOrganizationEvents();
        $before = $store->settings?->data();

        try {
            self::changeAiSettings($store, $events)->changeProviderKey(self::acmeFoods(), $providerKey, new UserId(self::SARA), new \DateTimeImmutable(self::AT));
            self::fail('A value that is no provider key was stored.');
        } catch (ChangeAiSettingsRefused $refusal) {
            self::assertSame($reason, $refusal->getMessage());
        }

        self::assertSame($before, $store->settings?->data());
        self::assertSame(0, $store->saves);
        self::assertSame([], $events->published);
    }

    #[Test]
    public function hasProviderKeyIsTrueOnlyOnceAKeyIsSaved(): void
    {
        $events = new RecordingOrganizationEvents();
        self::assertFalse(self::changeAiSettings(self::storeWith(null), $events)->hasProviderKey(self::acmeFoods()));

        $store = self::storeWith(self::newSettings());
        $changeAiSettings = self::changeAiSettings($store, $events);
        self::assertFalse($changeAiSettings->hasProviderKey(self::acmeFoods()));

        $changeAiSettings->changeProviderKey(self::acmeFoods(), self::PROVIDER_KEY, new UserId(self::SARA), new \DateTimeImmutable(self::AT));

        self::assertTrue($changeAiSettings->hasProviderKey(self::acmeFoods()));
    }

    private static function changeAiSettings(OrgSettingsStore $store, RecordingOrganizationEvents $events): ChangeAiSettings
    {
        return new ChangeAiSettings($store, $events, self::fieldEncryption());
    }

    private static function fieldEncryption(): SodiumFieldEncryption
    {
        return new SodiumFieldEncryption('unit:'.base64_encode(str_repeat('u', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), 'unit');
    }

    private static function acmeFoods(): OrganizationId
    {
        return new OrganizationId(self::ACME_FOODS);
    }

    private static function newSettings(): OrgSettings
    {
        return new OrgSettings(self::acmeFoods(), new UserId(self::SARA), new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
    }

    /**
     * @param OrgSettingsStore&object{settings: ?OrgSettings, saves: int} $store
     *
     * @return array<array-key, mixed>
     */
    private static function aiOf(OrgSettingsStore $store): array
    {
        $ai = $store->settings?->data()['ai'] ?? null;
        self::assertIsArray($ai);

        return $ai;
    }

    /**
     * Like DoctrineOrgSettingsStore, it saves only the settings of() gave, and finds them for their organization only.
     *
     * @return OrgSettingsStore&object{settings: ?OrgSettings, saves: int}
     */
    private static function storeWith(?OrgSettings $settings): OrgSettingsStore
    {
        return new class($settings) implements OrgSettingsStore {
            public int $saves = 0;

            public function __construct(public ?OrgSettings $settings)
            {
            }

            public function of(OrganizationId $organizationId): ?OrgSettings
            {
                return null !== $this->settings && $this->settings->organizationId()->equals($organizationId) ? $this->settings : null;
            }

            public function add(OrgSettings $settings): void
            {
                throw new \LogicException('ChangeAiSettings adds no settings.');
            }

            public function save(OrgSettings $settings): void
            {
                if ($settings !== $this->settings) {
                    throw new \LogicException('Only settings found through of() or added before are saved.');
                }
                ++$this->saves;
            }
        };
    }
}
