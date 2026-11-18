<?php

declare(strict_types=1);

namespace App\Assist\Application\LocalAi;

use App\Assist\Api\LocalResult;

/**
 * A laptop's result as it is kept on its package, with the template version its package's prompt was built from,
 * which a summary made of it names.
 */
final readonly class PostedLocalResult
{
    /**
     * @param \DateTimeImmutable|null $madeMainAt when it was made the main summary; null while it is not
     */
    public function __construct(public LocalResult $result, public string $promptVersion, public ?\DateTimeImmutable $madeMainAt = null)
    {
    }
}
