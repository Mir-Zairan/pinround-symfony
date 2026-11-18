<?php

declare(strict_types=1);

namespace App\Assist\Api;

use App\SharedKernel\Domain\UserId;

/**
 * A laptop's answer as it was kept: user-provided content, plain text that a page escapes when it shows it, with the
 * label that says who posted it and which local model wrote it. It is never the ticket's current summary by itself.
 */
final readonly class LocalResult
{
    public const string LABEL_FORMAT = 'posted by %s using local model %s (unverified)';

    /**
     * @param string $label LABEL_FORMAT with the poster's first name and the model
     */
    public function __construct(
        public LocalPackageId $packageId,
        public string $text,
        public LocalModelName $model,
        public UserId $posterId,
        public string $label,
        public \DateTimeImmutable $postedAt,
    ) {
    }
}
