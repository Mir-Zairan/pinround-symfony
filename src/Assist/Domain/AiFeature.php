<?php

declare(strict_types=1);

namespace App\Assist\Domain;

/**
 * What a person asks the server-side AI for (ai-architecture.md, AiGateway contract). The value names the prompt template
 * and the use case in `ast_usage`.
 */
enum AiFeature: string
{
    case Summary = 'summary';
    case Explain = 'explain';
    case Intake = 'intake';
}
