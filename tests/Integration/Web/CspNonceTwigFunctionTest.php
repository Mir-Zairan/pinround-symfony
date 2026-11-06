<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Web\Infrastructure\Security\CspNonce;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Templates and the P00.T8 security-headers subscriber must see one nonce per request, and worker mode
 * (ADR-0008) must never hand the previous request's nonce to the next one.
 */
final class CspNonceTwigFunctionTest extends KernelTestCase
{
    #[Test]
    public function theTwigFunctionRendersTheNonceTheServiceReturns(): void
    {
        $serviceNonce = self::getContainer()->get(CspNonce::class)->current();

        self::assertSame($serviceNonce.'|'.$serviceNonce, $this->renderNonceTwice());
    }

    #[Test]
    public function theKernelResetBetweenRequestsGivesTheTwigFunctionANewNonce(): void
    {
        $container = self::getContainer();
        $firstRequestNonce = $this->renderNonce();

        $container->get('services_resetter')->reset();

        $secondRequestNonce = $this->renderNonce();
        self::assertNotSame($firstRequestNonce, $secondRequestNonce);
        self::assertSame($container->get(CspNonce::class)->current(), $secondRequestNonce);
    }

    #[Test]
    public function theImportmapScriptTagsCarryTheNonce(): void
    {
        $nonce = self::getContainer()->get(CspNonce::class)->current();

        $html = $this->twig()->createTemplate("{{ importmap('app', {nonce: csp_nonce()}) }}")->render();

        preg_match_all('#<script\b[^>]*>#', $html, $scriptTags);
        // The polyfill is off (asset_mapper.yaml), so only the importmap JSON and the entry script print.
        self::assertSame(
            ['<script type="importmap" nonce="'.$nonce.'">', '<script type="module" nonce="'.$nonce.'">'],
            $scriptTags[0],
        );
    }

    private function renderNonce(): string
    {
        return $this->twig()->createTemplate('{{ csp_nonce() }}')->render();
    }

    private function renderNonceTwice(): string
    {
        return $this->twig()->createTemplate('{{ csp_nonce() }}|{{ csp_nonce() }}')->render();
    }

    private function twig(): Environment
    {
        return self::getContainer()->get('twig');
    }
}
