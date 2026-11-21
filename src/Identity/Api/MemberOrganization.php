<?php

declare(strict_types=1);

namespace App\Identity\Api;

use App\SharedKernel\Domain\OrganizationId;

/**
 * An organization a person is a member of, with its name, for the person to pick one on the OAuth consent page.
 */
final readonly class MemberOrganization
{
    public function __construct(public OrganizationId $id, public string $name)
    {
    }
}
