<?php

declare(strict_types=1);

namespace App\Tests\Integration\Conversation;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The linkify filter as templates get it: registered, and its HTML not escaped a second time by autoescape.
 */
final class LinkifyFilterTest extends KernelTestCase
{
    #[Test]
    public function aTemplateRendersTheLinkOnceEscaped(): void
    {
        $twig = self::getContainer()->get('twig');

        $html = $twig->createTemplate('{{ body|linkify }}')->render(['body' => "<b>Look</b> https://acme.test/a?x=1&y=2\nThanks"]);

        self::assertSame(
            "&lt;b&gt;Look&lt;/b&gt; <a href=\"https://acme.test/a?x=1&amp;y=2\" rel=\"noopener noreferrer\" target=\"_blank\">https://acme.test/a?x=1&amp;y=2</a><br>\nThanks",
            $html,
        );
    }
}
