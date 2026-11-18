<?php

declare(strict_types=1);

namespace App\Organization\Api\Event;

/**
 * One organization setting changed. Recorded only when before and after differ, except for "ai.provider_key": every
 * saved key counts as a change, and no secret travels here, so before is null and after is "changed".
 */
final readonly class SettingsChanged implements OrganizationEvent
{
    /**
     * @param string           $setting       the setting's path in org_settings.data, such as "ai.where"
     * @param bool|string|null $settingBefore the value stored before; null when there was none
     * @param bool|string      $settingAfter  the value stored now
     */
    public function __construct(
        public string $organizationId,
        public string $setting,
        public bool|string|null $settingBefore,
        public bool|string $settingAfter,
        public ?string $actorId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
