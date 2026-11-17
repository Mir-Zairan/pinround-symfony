<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Organization\Api\Event\SettingsChanged;
use App\Organization\Domain\OrgSettings;
use App\Organization\Domain\OrgSettingsStore;
use App\SharedKernel\Application\FieldEncryption;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Changes the organization's AI settings from the Admin › AI tab (ai-architecture.md, "Org AI policy"), one setting at
 * a time, leaving the others as they are, and publishes SettingsChanged for each change. Only an Admin may run it: the
 * caller asks ADMIN_AREA and ADMIN_AI first, and RequiresRecentAuth guards the provider key. Its write shares the
 * caller's transaction, which must have begun with $organizationId as the tenant. When another transaction changed the
 * settings since they were loaded, the store's version check fails (Doctrine's OptimisticLockException) and the caller
 * rolls back.
 */
final readonly class ChangeAiSettings
{
    /** Every provider key we know of is far shorter; the cap keeps a pasted page out of the encrypted field. */
    public const int PROVIDER_KEY_MAX_LENGTH = 512;

    public const string UNKNOWN_CHOICE = 'Choose one of the options shown.';
    public const string PROVIDER_KEY_MISSING = 'Paste the API key first.';
    public const string PROVIDER_KEY_TOO_LONG = 'This API key is too long. A key has at most 512 characters.';
    public const string PROVIDER_KEY_NOT_PRINTABLE = 'An API key has only letters, digits and symbols, with no spaces. Paste it again.';

    /** The setting path SettingsChanged names for the provider key, and the after value it carries instead of the key. */
    public const string PROVIDER_KEY_SETTING = 'ai.provider_key';
    public const string PROVIDER_KEY_CHANGED = 'changed';

    // Printable ASCII without the space; \z, because $ would also accept a trailing newline.
    private const string PRINTABLE_KEY = '/^[\x21-\x7E]+\z/';

    public function __construct(
        private OrgSettingsStore $settings,
        private OrganizationEvents $events,
        private FieldEncryption $fieldEncryption,
    ) {
    }

    /**
     * Stores the value of $choice for $setting (a key of OrgSettings::AI_CHOICES). Choosing what is stored already
     * writes nothing and publishes nothing.
     *
     * @throws ChangeAiSettingsRefused with UNKNOWN_CHOICE when $setting or $choice is not one the tab offers
     * @throws \LogicException         when the organization has no settings row
     */
    public function changeSetting(OrganizationId $organizationId, string $setting, string $choice, UserId $actor, \DateTimeImmutable $at): void
    {
        if (!\array_key_exists($choice, OrgSettings::AI_CHOICES[$setting] ?? [])) {
            throw new ChangeAiSettingsRefused(self::UNKNOWN_CHOICE);
        }

        $settings = $this->settingsOf($organizationId);
        $before = $settings->changeAiSetting($setting, $choice);
        $after = OrgSettings::AI_CHOICES[$setting][$choice];
        if ($before === $after) {
            return;
        }

        $this->settings->save($settings);
        $this->events->publish(new SettingsChanged($organizationId->toString(), 'ai.'.$setting, $before, $after, $actor->toString(), $at));
    }

    /**
     * Encrypts the organization's own provider key, with spaces and line breaks around it removed, and stores it in
     * place of any earlier one. Every call counts as a change: the old key is never decrypted to compare.
     *
     * @throws ChangeAiSettingsRefused   with PROVIDER_KEY_MISSING, PROVIDER_KEY_TOO_LONG or PROVIDER_KEY_NOT_PRINTABLE
     * @throws \LogicException           when the organization has no settings row
     * @throws \InvalidArgumentException when the field encryption keys are not configured
     */
    public function changeProviderKey(OrganizationId $organizationId, #[\SensitiveParameter] string $providerKey, UserId $actor, \DateTimeImmutable $at): void
    {
        $providerKey = trim($providerKey);
        if ('' === $providerKey) {
            throw new ChangeAiSettingsRefused(self::PROVIDER_KEY_MISSING);
        }
        if (\strlen($providerKey) > self::PROVIDER_KEY_MAX_LENGTH) {
            throw new ChangeAiSettingsRefused(self::PROVIDER_KEY_TOO_LONG);
        }
        if (1 !== preg_match(self::PRINTABLE_KEY, $providerKey)) {
            throw new ChangeAiSettingsRefused(self::PROVIDER_KEY_NOT_PRINTABLE);
        }

        $settings = $this->settingsOf($organizationId);
        $settings->changeAiProviderKey($this->fieldEncryption->encrypt($providerKey));
        $this->settings->save($settings);
        $this->events->publish(new SettingsChanged($organizationId->toString(), self::PROVIDER_KEY_SETTING, null, self::PROVIDER_KEY_CHANGED, $actor->toString(), $at));
    }

    /**
     * Whether the organization stored a provider key of its own; it is never read back.
     */
    public function hasProviderKey(OrganizationId $organizationId): bool
    {
        return $this->settings->of($organizationId)?->hasAiProviderKey() ?? false;
    }

    private function settingsOf(OrganizationId $organizationId): OrgSettings
    {
        return $this->settings->of($organizationId) ?? throw new \LogicException('The organization has no settings.');
    }
}
