<?php

declare(strict_types=1);

namespace App\Assist\Application\Policy;

/**
 * Where an AI request goes once the policy allows it (ai-architecture.md, "Org AI policy").
 */
enum AiRoute: string
{
    /** The server calls the organization's cloud provider. */
    case Cloud = 'cloud';
    /** The server sends the prompt package to the browser, which asks Ollama on the laptop. */
    case Laptop = 'laptop';
    /** Nothing is sent: the person sees how to set up Ollama. */
    case SetupSheet = 'setup_sheet';
}
