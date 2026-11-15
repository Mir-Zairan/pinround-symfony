<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Storage;

use Aws\Signature\S3SignatureV4;

/**
 * S3 SigV4 that also signs Content-Type and Content-Length into a presigned URL. The SDK leaves both out by default,
 * which would let a holder of the URL upload any type and any size.
 */
final class ContentPinnedS3Signature extends S3SignatureV4
{
    /**
     * @return array<mixed>
     */
    #[\Override]
    protected function getHeaderBlacklist(): array
    {
        $unsignedHeaders = parent::getHeaderBlacklist();
        unset($unsignedHeaders['content-length']);

        return $unsignedHeaders;
    }

    /**
     * @return array<mixed>
     */
    #[\Override]
    protected function getPresignHeaderDenyList(): array
    {
        $headersLeftOutOfUrls = parent::getPresignHeaderDenyList();
        unset($headersLeftOutOfUrls['content-type']);

        return $headersLeftOutOfUrls;
    }
}
