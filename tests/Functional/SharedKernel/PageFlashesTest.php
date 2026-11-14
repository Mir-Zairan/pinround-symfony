<?php

declare(strict_types=1);

namespace App\Tests\Functional\SharedKernel;

use App\Identity\UI\Controller\ProfileController;
use App\Tests\Functional\Identity\LogsInUsers;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Finder\Finder;
use Zenstruck\Foundry\Test\Factories;

/**
 * A notice an action flashes before its redirect waits for the redirected page, through the real firewall: a live
 * update's fetch can reach the server first and must not use it up.
 */
final class PageFlashesTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;

    #[Test]
    public function aLiveRefreshLeavesAWaitingNoticeForTheNextPage(): void
    {
        $client = $this->loggedInClientWithASavedProfile();

        $refreshedPage = $client->request('GET', '/me/profile', server: ['HTTP_X_LIVE_REFRESH' => '1']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Profile', $refreshedPage->filter('main h1')->text());
        self::assertSame([], $this->noticesOn($refreshedPage));

        $redirectedPage = $client->request('GET', '/me/profile');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([ProfileController::PROFILE_SAVED], $this->noticesOn($redirectedPage));
    }

    #[Test]
    public function aPageShowsAWaitingNoticeOnce(): void
    {
        $client = $this->loggedInClientWithASavedProfile();

        $firstPage = $client->request('GET', '/me/profile');
        self::assertResponseStatusCodeSame(200);
        $nextPage = $client->request('GET', '/me/profile');
        self::assertResponseStatusCodeSame(200);

        self::assertSame([ProfileController::PROFILE_SAVED], $this->noticesOn($firstPage));
        self::assertSame([], $this->noticesOn($nextPage));
    }

    /**
     * Twig's own `app.flashes` would use a flash up on any fetch; every template reads them through PageFlashes.
     */
    #[Test]
    public function noTemplateReadsFlashesPastPageFlashes(): void
    {
        $templates = new Finder()->files()->in(\dirname(__DIR__, 3).'/src')->name('*.twig');
        $readersOfFlashes = [];
        $readersOfPageFlashes = [];
        foreach ($templates as $template) {
            $source = $template->getContents();
            if (1 === preg_match('/\bapp\s*\.\s*(flashes|session)\b/', $source)) {
                $readersOfFlashes[] = $template->getRelativePathname();
            }
            if (str_contains($source, 'page_flashes(')) {
                $readersOfPageFlashes[] = $template->getRelativePathname();
            }
        }

        self::assertSame([], $readersOfFlashes);
        self::assertContains('Web/UI/templates/base.html.twig', $readersOfPageFlashes);
    }

    private function loggedInClientWithASavedProfile(): KernelBrowser
    {
        $client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => '10.40.0.7']);
        $this->logIn($client, $this->createUserWithPassword('Mia Lund'));
        $client->request('GET', '/me/profile');
        self::assertResponseStatusCodeSame(200);
        $client->submitForm('Save', ['name' => 'Mia Berg', 'time_zone' => 'Asia/Kolkata', 'theme' => 'dark']);
        self::assertResponseRedirects('/me/profile', 302);

        return $client;
    }

    /**
     * @return list<string>
     */
    private function noticesOn(Crawler $page): array
    {
        return $page->filter('main .login__notice[role="status"]')->each(static fn (Crawler $notice): string => $notice->text());
    }
}
