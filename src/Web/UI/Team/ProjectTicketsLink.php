<?php

declare(strict_types=1);

namespace App\Web\UI\Team;

use App\Ticketing\Api\ProjectSummary;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The address of the Tickets list filtered to one project, for the "See tickets" link of the Team page.
 */
final readonly class ProjectTicketsLink
{
    public const string TICKETS_ROUTE = 'ticketing_tickets';

    public function __construct(private UrlGeneratorInterface $urlGenerator)
    {
    }

    /**
     * @return string|null null while no phase has built the Tickets route, so the page shows no link to nowhere
     */
    public function urlOf(ProjectSummary $project): ?string
    {
        try {
            return $this->urlGenerator->generate(self::TICKETS_ROUTE, ['project' => $project->key()]);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
