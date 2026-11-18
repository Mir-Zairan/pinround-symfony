<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Application\Gateway;

use App\Organization\Api\AiSettings;
use App\Organization\Api\AiSettingsQueries;
use App\SharedKernel\Application\UndecryptableField;
use App\SharedKernel\Domain\OrganizationId;

/**
 * One organization's AI settings and provider key, as DbalAiSettingsQueries reads them: another organization has none
 * (AI off, no key), and an unreadable key throws UndecryptableField with a message that never holds the key.
 */
final class StoredAiSettings implements AiSettingsQueries
{
    /** @var list<string> the organization of each key read */
    public array $keyReads = [];

    /**
     * @param non-empty-string|null $providerKey
     */
    public function __construct(
        private readonly OrganizationId $organizationId,
        private readonly AiSettings $settings,
        #[\SensitiveParameter]
        private readonly ?string $providerKey = null,
        private readonly bool $isKeyUnreadable = false,
    ) {
    }

    public function aiSettingsOf(OrganizationId $organizationId): AiSettings
    {
        return $organizationId->equals($this->organizationId) ? $this->settings : AiSettings::off();
    }

    public function providerKeyOf(OrganizationId $organizationId): ?string
    {
        $this->keyReads[] = $organizationId->toString();
        if (!$organizationId->equals($this->organizationId)) {
            return null;
        }
        if ($this->isKeyUnreadable) {
            throw UndecryptableField::altered('change-me-test');
        }

        return $this->providerKey;
    }
}
