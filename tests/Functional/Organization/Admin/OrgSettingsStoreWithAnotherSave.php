<?php

declare(strict_types=1);

namespace App\Tests\Functional\Organization\Admin;

use App\Organization\Domain\OrgSettings;
use App\Organization\Domain\OrgSettingsStore;
use App\SharedKernel\Domain\OrganizationId;

/**
 * A settings store that runs another request's save once, right after its next read, as a concurrent save lands
 * between this request's read and its write. Everything else goes to the store it wraps.
 */
final class OrgSettingsStoreWithAnotherSave implements OrgSettingsStore
{
    public ?\Closure $anotherSaveAfterNextRead = null;

    public function __construct(private readonly OrgSettingsStore $store)
    {
    }

    public function of(OrganizationId $organizationId): ?OrgSettings
    {
        $settings = $this->store->of($organizationId);
        $anotherSave = $this->anotherSaveAfterNextRead;
        $this->anotherSaveAfterNextRead = null;
        if (null !== $anotherSave) {
            $anotherSave();
        }

        return $settings;
    }

    public function add(OrgSettings $settings): void
    {
        $this->store->add($settings);
    }

    public function save(OrgSettings $settings): void
    {
        $this->store->save($settings);
    }
}
