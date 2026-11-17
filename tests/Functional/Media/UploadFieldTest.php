<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UploadFieldTest extends WebTestCase
{
    #[Test]
    public function theGalleryRendersTheUploadFieldWithTheControllerItsTargetsAndAFormToken(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_gallery');

        self::assertResponseStatusCodeSame(200);
        $field = $crawler->filter('#gallery-upload[data-controller="presigned-upload"]');
        self::assertCount(1, $field);
        self::assertSame('/media/intents', $field->attr('data-presigned-upload-url-value'));
        self::assertSame('ticket', $field->attr('data-presigned-upload-target-kind-value'));
        self::assertSame('00000000-0000-7000-8000-000000000000', $field->attr('data-presigned-upload-target-id-value'));
        self::assertSame('/media/MEDIA_ID/status', $field->attr('data-presigned-upload-status-url-value'));
        $token = (string) $field->attr('data-presigned-upload-csrf-token-value');
        self::assertNotSame('', $token);
        self::assertCount(1, $field->filter('input[type="file"][accept="image/png,image/jpeg,image/webp"][data-presigned-upload-target="input"]'));
        self::assertCount(1, $field->filter('[role="status"][aria-live="polite"][data-presigned-upload-target="status"]'));
        self::assertCount(1, $field->filter('progress[hidden][data-presigned-upload-target="progress"]'));
        self::assertCount(1, $field->filter('img[hidden][alt][data-presigned-upload-target="preview"]'));
        self::assertCount(1, $field->filter('input[type="hidden"][name="media_id"][value=""][data-presigned-upload-target="mediaId"]'));
        self::assertCount(1, $crawler->filter('label[for="gallery-upload-file"]'));
    }
}
