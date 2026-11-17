<?php

declare(strict_types=1);

namespace App\Assist\Application\LocalAi;

use App\Assist\Api\LocalAiPurpose;
use App\Assist\Application\PromptVersion;

/**
 * A stored laptop package as the result intake needs it: what it is for, the template version its prompt was built
 * from, and whether it may still take a result.
 */
final readonly class IssuedLocalPackage
{
    /**
     * @param bool $isOpen not used and not expired at the moment it was read
     */
    public function __construct(
        public LocalAiPurpose $purpose,
        public PromptVersion $promptVersion,
        public bool $isOpen,
    ) {
    }
}
