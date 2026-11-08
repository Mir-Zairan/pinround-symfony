<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Http;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Twig\Environment;

/**
 * Stands in, in the test environment only, for pages a later phase builds, so MainMenuTest sees their menu items as
 * links (config/routes/test_fixtures.yaml). It renders the shell with an empty main.
 */
#[AsController]
final readonly class MenuPageStandInController
{
    public function __construct(private Environment $twig)
    {
    }

    public function openPage(): Response
    {
        return new Response($this->twig->render('@Web/base.html.twig'));
    }
}
