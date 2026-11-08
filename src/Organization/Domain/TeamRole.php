<?php

declare(strict_types=1);

namespace App\Organization\Domain;

/**
 * What a person may do in one team (personas-and-roles.md, Team roles). A team has exactly one Lead.
 */
enum TeamRole: string
{
    case Lead = 'lead';
    case Member = 'member';
    case Viewer = 'viewer';
}
