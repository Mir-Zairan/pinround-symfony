<?php

declare(strict_types=1);

namespace App\Organization\Domain;

/**
 * A company-wide role. Roles add up: a person can have none, one or both (personas-and-roles.md, Role model).
 */
enum OrgRole: string
{
    case Admin = 'admin';
    case Manager = 'manager';
}
