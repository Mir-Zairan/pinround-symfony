<?php

declare(strict_types=1);

namespace App\Identity\Api;

/**
 * The voter refused to let the request's person end the OAuth connection: it is another person's and they are no Admin
 * of its organization with the admin login, or the request came with a token rather than a browser session. Nothing
 * was changed; the message is the reason to show (403).
 */
final class OAuthConnectionRefused extends \RuntimeException
{
    public const string NOT_YOURS = 'Only its person or an Admin of this organization can end this connection.';

    public function __construct()
    {
        parent::__construct(self::NOT_YOURS);
    }
}
