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
 * AiSettingsQueries documents the stored shape. The warning names the organization and the setting's key, never a
 * stored value: a provider key put in the wrong field must not reach the log (ADR-0027). It is logged on each read of
 * such settings, as the Admin › AI writer stores known values only and the operator must hear of a row it did not.
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
        // false: no row; null: no ai key. The key fields stay in the database: a page never needs them. The parentheses
        // are needed: PostgreSQL binds - tighter than ->.
        $storedJson = $this->connection->fetchOne(
            "SELECT CASE WHEN jsonb_typeof(data->'ai') = 'object' THEN (data->'ai') - 'secret_enc' - 'key_id' ELSE data->'ai' END"
            .' FROM org_settings WHERE organization_id = ?',
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
            return new AiSettings(
                self::switchFrom($settings, 'on'),
                self::choiceFrom($settings, 'who', self::WHO_VALUES, false),
                self::choiceFrom($settings, 'send', self::SEND_VALUES, false),
                self::switchFrom($settings, 'sync'),
                self::choiceFrom($settings, 'where', array_combine(AiSettings::WHERE_VALUES, AiSettings::WHERE_VALUES), AiSettings::WHERE_CLOUD),
                self::switchFrom($settings, 'restricted_to_cloud'),
                self::textFrom($settings, 'provider'),
                self::textFrom($settings, 'provider_model'),
            );
        } catch (\UnexpectedValueException $unknown) {
            return $this->offForUnknown($organizationId, $unknown->getMessage());
        }
    }

    public function providerKeyOf(OrganizationId $organizationId): ?string
    {
        // false: no row. Each column is the JSON of the key, or null when it is missing (or ai is no object).
        $row = $this->connection->fetchNumeric(
            "SELECT data->'ai'->'secret_enc', data->'ai'->'key_id' FROM org_settings WHERE organization_id = ?",
            [$organizationId->toString()],
        );
        if (false === $row) {
            return null;
        }

        // A JSON null reads as a missing key.
        try {
            $secret = \is_string($row[0]) ? json_decode($row[0], false, 512, \JSON_THROW_ON_ERROR) : null;
            $keyId = \is_string($row[1]) ? json_decode($row[1], false, 512, \JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            throw new UndecryptableField('The stored AI provider key is nested too deep to read.');
        }
        if (null === $secret && null === $keyId) {
            return null;
        }

        $encrypted = \is_string($secret) ? base64_decode($secret, true) : false;
        // The stored value starts with the key id's length and the key id (FieldEncryption); key_id must name the same
        // key, so a row whose key_id says one key while the bytes use another is never trusted.
        if (false === $encrypted || '' === $encrypted || !\is_string($keyId) || substr($encrypted, 1, \ord($encrypted[0])) !== $keyId) {
            throw new UndecryptableField('The stored AI provider key is missing, is not base64, has no key id or names another key id than the one inside it.');
        }

        $providerKey = $this->fieldEncryption->decrypt($encrypted);

        return '' !== $providerKey ? $providerKey : throw new UndecryptableField('The stored AI provider key is empty.');
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
