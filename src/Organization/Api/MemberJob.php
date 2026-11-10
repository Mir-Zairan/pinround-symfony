<?php

declare(strict_types=1);

namespace App\Organization\Api;

/**
 * What a person does in a team, for other modules. Not a permission: it decides whose turn it is (testing goes to the
 * team's tester).
 */
enum MemberJob: string
{
    case Developer = 'developer';
    case Tester = 'tester';
    case Other = 'other';
}
