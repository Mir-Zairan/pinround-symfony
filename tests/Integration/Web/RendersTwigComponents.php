<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;
use Twig\Error\RuntimeError;

/**
 * Helpers shared by the component tests; use it in a KernelTestCase.
 */
trait RendersTwigComponents
{
    use InteractsWithTwigComponents;

    /**
     * @param array<string, mixed> $variables
     */
    private function renderTemplate(string $source, array $variables = []): string
    {
        return self::getContainer()->get('twig')->createTemplate($source)->render($variables);
    }

    /**
     * @param class-string             $component
     * @param array<string, mixed>     $props
     * @param class-string<\Throwable> $cause
     */
    private function assertFailsAtMount(string $component, array $props, string $messagePart, string $cause = \InvalidArgumentException::class): void
    {
        try {
            $this->renderTwigComponent($component, $props, content: 'x');
            self::fail('The component must not render.');
        } catch (RuntimeError $error) {
            self::assertInstanceOf($cause, $error->getPrevious());
            self::assertStringContainsString($messagePart, $error->getMessage());
        }
    }
}
