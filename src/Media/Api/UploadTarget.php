<?php

declare(strict_types=1);

namespace App\Media\Api;

/**
 * What an upload is for, as the voter subject of MediaPermission: Media knows no tickets or rounds, so the module that
 * owns the kind decides through its MediaTargetAuthorizer.
 */
final readonly class UploadTarget
{
    /**
     * @param string $kind the MediaTargetAuthorizer::targetKind() of the owning module
     * @param string $id   the target's id in that module
     */
    public function __construct(
        public string $kind,
        public string $id,
    ) {
    }
}
