<?php

declare(strict_types=1);

namespace App\Organization\Domain;

/**
 * What a person does in a team. Not a permission: it decides whose turn it is (testing goes to the team's tester).
 */
enum Job: string
{
    case Tester = 'tester';
    case Developer = 'developer';
    case Other = 'other';
}
