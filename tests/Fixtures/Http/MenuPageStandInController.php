<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Http;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Twig\Environment;

/**
 * The shell with an empty main, in the test environment only (test_menu_shell in config/routes/test_fixtures.yaml): a
 * page's own query count is its count minus this one's, as the shell's menu reads the same for both.
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
