<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI;

use App\Review\UI\PinView;
use App\Review\UI\ScreenshotStatus;
use App\Review\UI\ScreenshotView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScreenshotViewTest extends TestCase
{
    #[Test]
    public function itRefusesATitleOver120Characters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A picture title holds at most 120 characters.');

        self::view(title: str_repeat('é', 121));
    }

    #[Test]
    public function itAcceptsATitleOfExactly120Characters(): void
    {
        self::assertSame(120, mb_strlen((string) self::view(title: str_repeat('é', 120))->title));
    }

    #[Test]
    public function itRefusesASummaryOver600Characters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A picture summary holds at most 600 characters.');

        self::view(summary: str_repeat('é', 601));
    }

    #[Test]
    public function itAcceptsASummaryOfExactly600Characters(): void
    {
        self::assertSame(600, mb_strlen((string) self::view(summary: str_repeat('é', 600))->summary));
    }

    #[Test]
    public function aBlankTitleAndSummaryBecomeNull(): void
    {
        $view = self::view(title: " \r\n ", summary: '');

        self::assertNull($view->title);
        self::assertNull($view->summary);
    }

    #[Test]
    public function itRefusesAnIdThatCouldBreakOutOfAStyleRule(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A screenshot id uses lowercase letters, digits and dashes, and starts with a letter.');

        new ScreenshotView("shot-1{}\n", '/i.png', 800, 450, 'Checkout');
    }

    #[Test]
    public function itRefusesAZeroSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A screenshot needs a width and a height of at least 1.');

        new ScreenshotView('shot-1', '/i.png', 0, 450, 'Checkout');
    }

    #[Test]
    public function aScreenshotIsReadyUnlessToldOtherwise(): void
    {
        $view = self::view();

        self::assertSame(ScreenshotStatus::Ready, $view->status);
        self::assertTrue($view->isReady());
        self::assertFalse($view->isPending());
    }

    /**
     * @return iterable<string, array{?string, ?int, ?int}>
     */
    public static function readyScreenshotsWithoutTheirPicture(): iterable
    {
        yield 'no URL' => [null, 800, 450];
        yield 'an empty URL' => ['', 800, 450];
        yield 'no size' => ['/i.png', null, null];
    }

    #[Test]
    #[DataProvider('readyScreenshotsWithoutTheirPicture')]
    public function aReadyScreenshotNeedsItsPicturesUrlAndSize(?string $imageUrl, ?int $width, ?int $height): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A ready screenshot needs the URL, the width and the height of its picture.');

        new ScreenshotView('shot-1', $imageUrl, $width, $height, 'Checkout');
    }

    /**
     * @return iterable<string, array{ScreenshotStatus}>
     */
    public static function statusesWithoutAPicture(): iterable
    {
        yield 'pending' => [ScreenshotStatus::Pending];
        yield 'rejected' => [ScreenshotStatus::Rejected];
    }

    #[Test]
    #[DataProvider('statusesWithoutAPicture')]
    public function aScreenshotThatIsNotReadyHasNoPictureToShow(ScreenshotStatus $status): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only a ready screenshot has a picture to show.');

        new ScreenshotView('shot-1', '/i.png', 800, 450, 'Checkout', status: $status);
    }

    #[Test]
    #[DataProvider('statusesWithoutAPicture')]
    public function aScreenshotThatIsNotReadyMayKnowItsSizeOrNot(ScreenshotStatus $status): void
    {
        $sized = new ScreenshotView('shot-1', null, 800, 450, 'Checkout', status: $status);
        $unsized = new ScreenshotView('shot-2', null, null, null, 'Checkout', status: $status);

        self::assertSame([800, 450, null], [$sized->width, $sized->height, $sized->imageUrl]);
        self::assertSame([null, null], [$unsized->width, $unsized->height]);
        self::assertFalse($sized->isReady());
        self::assertSame(ScreenshotStatus::Pending === $status, $sized->isPending());
    }

    #[Test]
    public function aWidthWithoutAHeightIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A screenshot knows both its width and its height, or neither.');

        new ScreenshotView('shot-1', null, 800, null, 'Checkout', status: ScreenshotStatus::Pending);
    }

    #[Test]
    public function aPendingScreenshotOfZeroHeightIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A screenshot needs a width and a height of at least 1.');

        new ScreenshotView('shot-1', null, 800, 0, 'Checkout', status: ScreenshotStatus::Pending);
    }

    #[Test]
    public function pinsKeepTheirOwnNumbersAndComeBackInNumberOrder(): void
    {
        $view = self::view(pins: [
            new PinView(5, 10.0, 10.0, 'five'),
            new PinView(2, 20.0, 20.0, 'two'),
            new PinView(3, 30.0, 30.0, 'three'),
        ]);

        self::assertSame([2, 3, 5], array_map(static fn (PinView $pin): int => $pin->number, $view->pins));
        self::assertSame(['two', 'three', 'five'], array_map(static fn (PinView $pin): string => $pin->note, $view->pins));
    }

    #[Test]
    public function numberingIsPerScreenshot(): void
    {
        $first = self::view(pins: [new PinView(1, 10.0, 10.0, 'a'), new PinView(2, 10.0, 10.0, 'b')]);
        $second = self::view(pins: [new PinView(1, 10.0, 10.0, 'c')]);

        self::assertSame(1, $second->pins[0]->number);
        self::assertCount(2, $first->pins);
    }

    #[Test]
    public function itRefusesTwoPinsWithTheSameNumber(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Pin 2 appears twice on one screenshot.');

        self::view(pins: [new PinView(2, 10.0, 10.0, 'a'), new PinView(2, 20.0, 20.0, 'b')]);
    }

    /**
     * @param list<PinView> $pins
     */
    private static function view(?string $title = null, ?string $summary = null, array $pins = []): ScreenshotView
    {
        return new ScreenshotView('shot-1', '/media/shop-checkout.png', 800, 450, 'Acme Foods checkout of project SHOP', $title, $summary, $pins);
    }
}
