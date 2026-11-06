<?php

declare(strict_types=1);

namespace App\Web\UI\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ComponentGalleryController extends AbstractController
{
    // Routed from config/routes/component_gallery.yaml in dev and test only; no #[Route] here on purpose.
    public function __invoke(Request $request): Response
    {
        $theme = $request->query->getString('theme');

        return $this->render('@Web/gallery.html.twig', [
            'theme' => \in_array($theme, ['light', 'dark'], true) ? $theme : null,
        ]);
    }
}
