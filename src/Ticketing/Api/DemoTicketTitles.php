<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * The tickets of a project by title, for the dev environment's demo seed steps of other modules, which keep a ticket
 * whose title the project holds already instead of making it again. Nothing implements it in prod.
 */
interface DemoTicketTitles
{
    /**
     * Reads under row-level security, so it needs an open transaction whose tenant is the project's organization.
     *
     * @param string $projectKey matched exactly, for example SHOP
     *
     * @return array<string, string> each ticket's key by its title; of two tickets with one title, the one with the
     *                               higher number; empty when the organization has no such project
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function keysByTitleIn(string $projectKey): array;
}
