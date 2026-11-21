<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * The grants a self-registered OAuth client may use (ADR-0050). A closed set: the idn_oauth_client_grant_types_check
 * constraint holds the same values.
 */
enum OAuthGrantType: string
{
    case AuthorizationCode = 'authorization_code';
    case RefreshToken = 'refresh_token';
}
