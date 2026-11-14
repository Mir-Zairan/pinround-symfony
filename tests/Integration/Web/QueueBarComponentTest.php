<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Web\UI\Component\QueueBar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class QueueBarComponentTest extends KernelTestCase
{
    use RendersTwigComponents;

    private const array PROPS = [
        'label' => 'Code reviews', 'place' => 2, 'count' => 4, 'previousUrl' => '/t/SHOP-1', 'nextUrl' => '/t/SHOP-3',
        'finishUrl' => '/tickets/queue/finish', 'stopUrl' => '/t/SHOP-2/queue/stop', 'csrfToken' => 'token',
    ];

    #[Test]
    public function aMiddleTicketShowsItsPlacePreviousNextTicketAndStop(): void
    {
        $bar = $this->render(self::PROPS);

        self::assertSame('Code reviews · 2 of 4', $bar->filter('.queue-bar__line')->text());
        self::assertSame(['/t/SHOP-1', null], [$bar->filter('.queue-bar__previous')->attr('href'), $bar->filter('.queue-bar__previous')->attr('aria-disabled')]);
        self::assertSame(['/t/SHOP-3', 'Next ticket'], [$bar->filter('.queue-bar__next')->attr('href'), $bar->filter('.queue-bar__next')->text()]);
        self::assertCount(0, $bar->filter('.queue-bar__finish'));
        self::assertSame(['/t/SHOP-2/queue/stop', 'token', 'Stop'], [
            $bar->filter('.queue-bar__stop')->closest('form')?->attr('action'), $bar->filter('.queue-bar__stop')->closest('form')?->filter('input[name="_csrf_token"]')->attr('value'), $bar->filter('.queue-bar__stop')->text(),
        ]);
    }

    #[Test]
    public function theFirstTicketDisablesPreviousWithNoLink(): void
    {
        $bar = $this->render(['previousUrl' => null, 'place' => 1] + self::PROPS);

        self::assertSame(['true', 'link', null], [$bar->filter('.queue-bar__previous')->attr('aria-disabled'), $bar->filter('.queue-bar__previous')->attr('role'), $bar->filter('.queue-bar__previous')->attr('href')]);
    }

    #[Test]
    public function theLastTicketShowsFinishInsteadOfNextTicket(): void
    {
        $bar = $this->render(['nextUrl' => null, 'place' => 4] + self::PROPS);

        self::assertCount(0, $bar->filter('.queue-bar__next'));
        self::assertSame(['Finish', '/tickets/queue/finish', 'token'], [
            $bar->filter('.queue-bar__finish')->text(), $bar->filter('.queue-bar__finish')->closest('form')?->attr('action'), $bar->filter('.queue-bar__finish')->closest('form')?->filter('input[name="_csrf_token"]')->attr('value'),
        ]);
    }

    #[Test]
    public function theLabelIsEscaped(): void
    {
        $html = (string) $this->renderTwigComponent(QueueBar::class, ['label' => '<b>Code</b>'] + self::PROPS);

        self::assertStringContainsString('&lt;b&gt;Code&lt;/b&gt;', $html);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function barsOfNoPlace(): iterable
    {
        yield 'a blank label' => [['label' => '  '], 'non-empty label'];
        yield 'place 0' => [['place' => 0], 'from 1 to its count'];
        yield 'a place past the count' => [['place' => 5], 'from 1 to its count'];
        yield 'no stop URL' => [['stopUrl' => ''], 'CSRF token'];
        yield 'no token' => [['csrfToken' => ''], 'CSRF token'];
    }

    /**
     * @param array<string, mixed> $props
     */
    #[Test]
    #[DataProvider('barsOfNoPlace')]
    public function aBarOfNoPlaceInAQueueFailsAtMount(array $props, string $messagePart): void
    {
        $this->assertFailsAtMount(QueueBar::class, $props + self::PROPS, $messagePart);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props): Crawler
    {
        $bar = new Crawler((string) $this->renderTwigComponent(QueueBar::class, $props));
        self::assertCount(1, $bar->filter('nav.queue-bar'));

        return $bar;
    }
}
