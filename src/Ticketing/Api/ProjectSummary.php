<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * A project as the Team page lists it.
 */
final readonly class ProjectSummary
{
    /**
     * @param string $id              the project's uuid
     * @param string $key             the ticket key prefix, for example SHOP
     * @param int    $openTicketCount the project's tickets not yet done
     */
    public function __construct(
        private string $id,
        private string $key,
        private string $name,
        private int $openTicketCount,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function openTicketCount(): int
    {
        return $this->openTicketCount;
    }
}
