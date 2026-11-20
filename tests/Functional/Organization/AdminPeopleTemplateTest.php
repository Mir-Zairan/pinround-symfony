<?php

declare(strict_types=1);

namespace App\Tests\Functional\Organization;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The people section of admin/people.html.twig with nobody to list. Over HTTP the first page always holds the Admin
 * who opens it, so the first page's empty state is proven here, beside a later page's, on the tab's own block.
 */
final class AdminPeopleTemplateTest extends KernelTestCase
{
    #[Test]
    public function anEmptyFirstPageSaysNoOneHasJoinedYetWithNoLinkBack(): void
    {
        $peopleSection = $this->peopleSectionWith(peopleFirstPage: null);

        self::assertSame('No one has joined yet.', $peopleSection->filter('.empty-state')->text());
        self::assertCount(0, $peopleSection->filter('a'));
    }

    #[Test]
    public function anEmptyLaterPageLinksBackToTheFirstPageWithTheOtherListsPagesKept(): void
    {
        $peopleSection = $this->peopleSectionWith(peopleFirstPage: ['tokens_after_expires_at' => '2099-01-01T00:00:00+00:00', 'tokens_after_id' => '0198f000-0000-7000-8000-0000000002ff']);

        self::assertCount(0, $peopleSection->filter('.empty-state'));
        self::assertSame('No more people. Back to the first page', $peopleSection->filter('p')->text());
        self::assertSame(
            ['/admin/people?tokens_after_expires_at=2099-01-01T00:00:00%2B00:00&tokens_after_id=0198f000-0000-7000-8000-0000000002ff'],
            $peopleSection->filter('a')->each(static fn (Crawler $link): ?string => $link->attr('href')),
        );
    }

    /**
     * @param array<string, string>|null $peopleFirstPage
     */
    private function peopleSectionWith(?array $peopleFirstPage): Crawler
    {
        $request = Request::create('/admin/people');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);
        try {
            $tab = self::getContainer()->get('twig')->load('@Organization/admin/people.html.twig')->renderBlock('admin_tab', [
                'people' => [],
                'peopleNextPage' => null,
                'peopleFirstPage' => $peopleFirstPage,
                'invitationsFirstPage' => null,
                'tokensFirstPage' => null,
                'pendingInvitations' => [],
                'nextPage' => null,
                'liveTokens' => [],
                'tokenOwnerNames' => [],
                'tokensNextPage' => null,
                'shownPagesQuery' => [],
                'revokeTokenCsrfId' => 'organization_admin_people_token_revoke',
                'typedEmail' => '',
                'emailError' => null,
                'formError' => null,
            ]);
        } finally {
            $requestStack->pop();
        }

        $peopleSection = new Crawler($tab)->filter('.people__members');
        self::assertCount(1, $peopleSection);

        return $peopleSection;
    }
}
