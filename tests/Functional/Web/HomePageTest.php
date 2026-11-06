<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomePageTest extends WebTestCase
{
    public function testHomePageRendersTheEmptyShell(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextSame('title', 'pinround');
    }
}
