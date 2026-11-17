<?php

declare(strict_types=1);

namespace App\Review\UI\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\UX\Turbo\TurboBundle;
use Twig\Environment;

/**
 * Answers a Review form's refusal: a Turbo form with the toast alone as a stream, which reads nothing, under the
 * refusal's status; any other form with what the controller redirects to, the page the form was on with the toast
 * flashed. Modelled on Conversation's ThreadController::respondToRefusal, copied as Review's UI may not import it
 * (deptrac).
 */
final readonly class RefusalAnswers
{
    public function __construct(private Environment $twig)
    {
    }

    /**
     * @param \Closure(string): Response $redirectWithToast the redirect of a form without Turbo, given the toast to flash
     */
    public function answer(Request $request, RefusedWithToast $refused, \Closure $redirectWithToast): Response
    {
        if (TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            return $redirectWithToast($refused->toast());
        }

        return new Response(
            $this->twig->render('@Web/_toast_stream.html.twig', ['message' => $refused->toast()]),
            $refused->status(),
            ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
        );
    }
}
