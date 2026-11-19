<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Seeds Acme Foods' pictures (med_object) through the migrator, committed. The using test creates the people
 * (CreatesAcmeFoodsTeams) in its setUp and removes the rows in its tearDown.
 */
trait SeedsMediaObjects
{
    /**
     * @param 'ready'|'pending'|'rejected' $status
     *
     * @return string the media id
     */
    private function addMedia(string $status, string $uploader = 'Mia'): string
    {
        $mediaId = Uuid::v7()->toRfc4122();
        $parameters = [
            $this->acmeFoods->toString(), $mediaId, 'uploads/'.$mediaId, $this->people[$uploader]->id()->toString(), $status,
            'rejected' === $status ? 'The file is not a picture.' : null,
            'ready' === $status ? hash('sha256', $mediaId) : null,
            'ready' === $status ? 1280 : null,
            'ready' === $status ? 800 : null,
            'ready' === $status ? json_encode(['view' => 'media/'.$mediaId.'/view.webp', 'thumb' => 'media/'.$mediaId.'/thumb.webp'], \JSON_THROW_ON_ERROR) : null,
        ];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO med_object (organization_id, id, storage_key, mime, bytes, uploaded_by, created_at, status, reject_reason, sha256, width, height, variants)
             VALUES (?, ?, ?, 'image/png', 2048, ?, '2026-11-05 08:00:00+00', ?, ?, ?, ?, ?, CAST(? AS jsonb))",
            $parameters,
        ));

        return $mediaId;
    }
}
