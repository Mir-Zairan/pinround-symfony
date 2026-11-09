<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Demo;

use App\Identity\Api\UserQueries;

final readonly class SeedDemoProjects
{
    public function __construct(private UserQueries $users)
    {
    }

    public function saraId(): ?string
    {
        return $this->users->idByEmail('sara@acme-foods.example');
    }
}
