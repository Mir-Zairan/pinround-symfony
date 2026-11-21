<?php

declare(strict_types=1);

namespace App\SharedKernel\UI;

use Symfony\Component\HttpFoundation\Request;

/**
 * The one origin besides 'self' a page's forms may send the browser to, for that response only: the OAuth consent
 * page, whose Allow and Deny answer with a redirect to the app's registered address (ADR-0050). Browsers apply the
 * page's form-action to every redirect after a form is posted, so with 'self' alone the app never gets its answer.
 * The security-headers subscriber reads it from the request when it builds the response's policy; every other page
 * keeps form-action 'self'. A request attribute ends with its request, so worker mode never carries it to the next one
 * (ADR-0008).
 */
final class CspFormActionOrigin
{
    private const string REQUEST_ATTRIBUTE = '_csp_form_action_origin';

    /**
     * @throws \InvalidArgumentException when $origin is not an http or https origin with a plain lowercase host
     */
    public static function allowOn(Request $request, string $origin): void
    {
        if (1 !== preg_match(CspConnectSources::ORIGIN_PATTERN, $origin)) {
            throw new \InvalidArgumentException('A form-action origin must be http or https with a plain lowercase host and no path.');
        }
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $origin);
    }

    /**
     * @return string|null null when the page allowed none, or when the attribute holds anything allowOn() would refuse
     *                     (a route parameter of that name, say), so nothing unchecked reaches the header
     */
    public static function allowedOn(Request $request): ?string
    {
        $origin = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        return \is_string($origin) && 1 === preg_match(CspConnectSources::ORIGIN_PATTERN, $origin) ? $origin : null;
    }
}
