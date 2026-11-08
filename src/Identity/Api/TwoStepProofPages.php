<?php

declare(strict_types=1);

namespace App\Identity\Api;

/**
 * Where a page of another module sends a logged-in person who lacks a two-step login proof, such as the admin gate of
 * Organization (ADR-0012), and the way back. The path back is kept only when it is a page of this app.
 */
interface TwoStepProofPages
{
    /**
     * @param mixed $request the HttpFoundation Request of the page that needs the proof
     *
     * @return string the path of "Confirm it's you", which leads back once the password and a code are right: to the
     *                page asked for on a GET, else to the page of the form, else Home
     *
     * @throws \InvalidArgumentException when $request is no HttpFoundation Request
     */
    public function confirmItsYouPathFor(mixed $request): string;

    /**
     * Keeps the way back in the request's session for the "Continue" link after the setup, and shows $notice on the
     * setup page.
     *
     * @param mixed  $request the HttpFoundation Request of the page that needs the proof
     * @param string $notice  plain text for the person, such as why the page needs two-step login
     *
     * @return string the path of the two-step login setup page
     *
     * @throws \InvalidArgumentException when $request is no HttpFoundation Request
     */
    public function twoStepSetupPathFor(mixed $request, string $notice): string;
}
