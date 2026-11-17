<?php

declare(strict_types=1);

namespace App\Organization\Application;

/**
 * ChangeAiSettings refused the value it was given; nothing was stored. Its message is one of ChangeAiSettings' plain
 * sentences, so the Admin › AI tab may show it as it is. In the Application, so the UI may catch it.
 */
final class ChangeAiSettingsRefused extends \DomainException
{
}
