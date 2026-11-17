<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Api\AiSettings;
use App\Organization\Api\AiSettingsQueries;
use App\SharedKernel\Application\FieldEncryption;
use App\SharedKernel\Application\UndecryptableField;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * AiSettingsQueries documents the stored shape. The warnings name the organization and the setting's key, never a
 * stored value: a provider key put in the wrong field must not reach the log (ADR-0027).
 */
final readonly class DbalAiSettingsQueries implements AiSettingsQueries
{
    private const array WHO_VALUES = ['everyone' => false, 'leads_only' => true];

    private const array SEND_VALUES = ['title_and_text' => false, 'title_only' => true];

    public function __construct(
        private Connection $connection,
        private FieldEncryption $fieldEncryption,
        private LoggerInterface $logger,
    ) {
    }

    public function aiSettingsOf(OrganizationId $organizationId): AiSettings
    {
        // false: no row; null: no ai key.
        $storedJson = $this->connection->fetchOne(
            "SELECT data->'ai' FROM org_settings WHERE organization_id = ?",
            [$organizationId->toString()],
        );
        if (!\is_string($storedJson)) {
            return AiSettings::off();
        }

        try {
            $stored = json_decode($storedJson, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Only a value nested deeper than 512 levels gets here, as PostgreSQL gives valid JSON.
            return $this->offForUnknown($organizationId, 'ai');
        }
        if (null === $stored) {
            return AiSettings::off();
        }
        if (!$stored instanceof \stdClass) {
            return $this->offForUnknown($organizationId, 'ai');
        }

        $settings = (array) $stored;
        try {
            $isOn = self::switchFrom($settings, 'on');
            $isForLeadsOnly = self::choiceFrom($settings, 'who', self::WHO_VALUES, false);
            $isTitleOnly = self::choiceFrom($settings, 'send', self::SEND_VALUES, false);
            $isSummaryOnSyncOn = self::switchFrom($settings, 'sync');
            $whereAiRuns = self::choiceFrom($settings, 'where', array_combine(AiSettings::WHERE_VALUES, AiSettings::WHERE_VALUES), AiSettings::WHERE_CLOUD);
            $isRestrictedToCloudAllowed = self::switchFrom($settings, 'restricted_to_cloud');
            $provider = self::textFrom($settings, 'provider');
            $providerModel = self::textFrom($settings, 'provider_model');
        } catch (\UnexpectedValueException $unknown) {
            return $this->offForUnknown($organizationId, $unknown->getMessage());
        }

        $providerKey = $this->providerKeyFrom($organizationId, $settings['secret_enc'] ?? null, $settings['key_id'] ?? null);

        return new AiSettings(
            $isOn,
            $isForLeadsOnly,
            $isTitleOnly,
            $isSummaryOnSyncOn,
            $whereAiRuns,
            $isRestrictedToCloudAllowed,
            $provider,
            $providerModel,
            $providerKey,
            null === $providerKey && (null !== ($settings['secret_enc'] ?? null) || null !== ($settings['key_id'] ?? null)),
        );
    }

    /**
     * @param mixed $secret the stored secret_enc
     * @param mixed $keyId  the stored key_id
     *
     * @return non-empty-string|null null when the organization stored no key, or one that cannot be read
     */
    private function providerKeyFrom(OrganizationId $organizationId, mixed $secret, mixed $keyId): ?string
    {
        if (null === $secret && null === $keyId) {
            return null;
        }

        $encrypted = \is_string($secret) ? base64_decode($secret, true) : false;
        // The stored value starts with the key id's length and the key id (FieldEncryption); key_id must name the same
        // key, so a row whose key_id says one key while the bytes use another is never trusted.
        if (false === $encrypted || '' === $encrypted || !\is_string($keyId) || substr($encrypted, 1, \ord($encrypted[0])) !== $keyId) {
            return $this->noKeyForUnreadable($organizationId, null);
        }

        try {
            $providerKey = $this->fieldEncryption->decrypt($encrypted);
        } catch (UndecryptableField $undecryptable) {
            return $this->noKeyForUnreadable($organizationId, $undecryptable);
        }

        return '' === $providerKey ? $this->noKeyForUnreadable($organizationId, null) : $providerKey;
    }

    private function noKeyForUnreadable(OrganizationId $organizationId, ?UndecryptableField $undecryptable): null
    {
        // UndecryptableField's message names at most a valid key id, never the stored bytes.
        $this->logger->warning('org_settings holds an AI provider key that cannot be read; AI calls get no key.', [
            'organization_id' => $organizationId->toString(),
            'reason' => $undecryptable?->getMessage() ?? 'secret_enc or key_id is missing, malformed or names another key',
        ]);

        return null;
    }

    private function offForUnknown(OrganizationId $organizationId, string $key): AiSettings
    {
        $this->logger->warning('org_settings holds an unknown AI setting; AI help is off.', [
            'organization_id' => $organizationId->toString(),
            'key' => $key,
        ]);

        return AiSettings::off();
    }

    /**
     * @param array<array-key, mixed> $settings
     *
     * @throws \UnexpectedValueException naming the key when the stored value is not a JSON boolean
     */
    private static function switchFrom(array $settings, string $key): bool
    {
        $stored = $settings[$key] ?? false;

        return \is_bool($stored) ? $stored : throw new \UnexpectedValueException($key);
    }

    /**
     * @template T
     *
     * @param array<array-key, mixed> $settings
     * @param array<string, T>        $valuesByStored
     * @param T                       $default
     *
     * @return T
     *
     * @throws \UnexpectedValueException naming the key when the stored value is not one of $valuesByStored's keys
     */
    private static function choiceFrom(array $settings, string $key, array $valuesByStored, mixed $default): mixed
    {
        $stored = $settings[$key] ?? null;
        if (null === $stored) {
            return $default;
        }

        return \is_string($stored) && \array_key_exists($stored, $valuesByStored) ? $valuesByStored[$stored] : throw new \UnexpectedValueException($key);
    }

    /**
     * @param array<array-key, mixed> $settings
     *
     * @throws \UnexpectedValueException naming the key when the stored value is not a string
     */
    private static function textFrom(array $settings, string $key): ?string
    {
        $stored = $settings[$key] ?? null;

        return null === $stored || \is_string($stored) ? $stored : throw new \UnexpectedValueException($key);
    }
}
