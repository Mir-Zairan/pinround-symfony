<?php

declare(strict_types=1);

namespace App\Tests\Integration\Media\Persistence;

use App\SharedKernel\Application\TenantContext;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The CHECKs of Version20261115120000 and Version20261115140000 refuse every row the domain could not write, named in
 * the error; the tables' grants, row-level security and indexes are pinned in DatabaseRolesTest with the other tenant
 * tables. Every row is written as pinround_app under its organization and rolled back.
 */
final class MediaTableTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000e1301';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000e1302';
    private const string ROW = '0197a3c0-0000-7000-8000-0000000e1311';
    private const string SHA256 = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';
    private const string VARIANTS = '{"thumb": "acme/media/thumb.webp", "full": "acme/media/full.webp"}';

    /**
     * @return iterable<string, array{string, array<string, int|string|null>}> the table and the columns that differ
     *                                                                         from its valid row
     */
    public static function validRows(): iterable
    {
        yield 'an open upload intent' => ['med_upload_intent', []];
        yield 'a used upload intent' => ['med_upload_intent', ['used_at' => '2027-03-15 10:07:30+00']];
        yield 'an upload intent used a second before it expires' => ['med_upload_intent', ['used_at' => '2027-03-15 10:14:59+00']];
        yield 'an upload intent for two media types' => ['med_upload_intent', ['mime_allowed' => '{"image/png","image/webp"}']];
        yield 'a target kind of 40 characters' => ['med_upload_intent', ['target_kind' => str_repeat('é', 40)]];
        yield 'a target id of 100 characters' => ['med_upload_intent', ['target_id' => str_repeat('é', 100)]];
        yield 'a pending picture' => ['med_object', []];
        yield 'a ready picture' => ['med_object', ['status' => 'ready', 'sha256' => self::SHA256, 'width' => 1440, 'height' => 900, 'variants' => self::VARIANTS]];
        yield 'a ready picture with one variant' => ['med_object', ['status' => 'ready', 'sha256' => self::SHA256, 'width' => 1440, 'height' => 900, 'variants' => '{"full": "acme/media/full.webp"}']];
        yield 'a rejected picture with a reason of 200 characters' => ['med_object', ['status' => 'rejected', 'reject_reason' => str_repeat('é', 200)]];
        yield 'a rejected picture with a reason of two lines' => ['med_object', ['status' => 'rejected', 'reject_reason' => "Not a picture.\nTry again."]];
    }

    /**
     * @return iterable<string, array{string, array<string, int|string|null>, string}> the table, the columns that
     *                                                                                 differ from its valid row and the
     *                                                                                 constraint that refuses them
     */
    public static function refusedRows(): iterable
    {
        yield 'an unknown purpose' => ['med_upload_intent', ['purpose' => 'avatar'], 'med_upload_intent_purpose_check'];
        yield 'a maximum size of zero' => ['med_upload_intent', ['max_bytes' => 0], 'med_upload_intent_max_bytes_check'];
        yield 'no allowed media type' => ['med_upload_intent', ['mime_allowed' => '{}'], 'med_upload_intent_mime_allowed_check'];
        yield 'an empty allowed media type' => ['med_upload_intent', ['mime_allowed' => '{"image/png",""}'], 'med_upload_intent_mime_allowed_check'];
        yield 'a NULL allowed media type' => ['med_upload_intent', ['mime_allowed' => '{"image/png",NULL}'], 'med_upload_intent_mime_allowed_check'];
        yield 'allowed media types nested in two dimensions' => ['med_upload_intent', ['mime_allowed' => '{{"image/png"},{"image/webp"}}'], 'med_upload_intent_mime_allowed_check'];
        yield 'an empty target kind' => ['med_upload_intent', ['target_kind' => ''], 'med_upload_intent_target_kind_check'];
        yield 'a target kind of 41 characters' => ['med_upload_intent', ['target_kind' => str_repeat('é', 41)], 'med_upload_intent_target_kind_check'];
        yield 'an empty target id' => ['med_upload_intent', ['target_id' => ''], 'med_upload_intent_target_id_check'];
        yield 'a target id of 101 characters' => ['med_upload_intent', ['target_id' => str_repeat('é', 101)], 'med_upload_intent_target_id_check'];
        yield 'an empty upload storage key' => ['med_upload_intent', ['storage_key' => ''], 'med_upload_intent_storage_key_check'];
        yield 'an upload intent used at its expiry' => ['med_upload_intent', ['used_at' => '2027-03-15 10:15:00+00'], 'med_upload_intent_used_at_check'];
        yield 'an upload intent used after its expiry' => ['med_upload_intent', ['used_at' => '2027-03-15 10:20:00+00'], 'med_upload_intent_used_at_check'];
        yield 'an empty picture storage key' => ['med_object', ['storage_key' => ''], 'med_object_storage_key_check'];
        yield 'an empty media type' => ['med_object', ['mime' => ''], 'med_object_mime_check'];
        yield 'an unknown status' => ['med_object', ['status' => 'done'], 'med_object_status_check'];
        yield 'a ready picture without a hash' => ['med_object', ['status' => 'ready', 'width' => 1440, 'height' => 900, 'variants' => self::VARIANTS], 'med_object_ready_check'];
        yield 'a ready picture without variants' => ['med_object', ['status' => 'ready', 'sha256' => self::SHA256, 'width' => 1440, 'height' => 900], 'med_object_ready_check'];
        yield 'a pending picture with a hash' => ['med_object', ['sha256' => self::SHA256], 'med_object_ready_check'];
        yield 'a rejected picture without a reason' => ['med_object', ['status' => 'rejected'], 'med_object_rejected_check'];
        yield 'a pending picture with a reason' => ['med_object', ['reject_reason' => 'Not a picture'], 'med_object_rejected_check'];
        yield 'an empty reason' => ['med_object', ['status' => 'rejected', 'reject_reason' => ''], 'med_object_reject_reason_check'];
        yield 'a reason of 201 characters' => ['med_object', ['status' => 'rejected', 'reject_reason' => str_repeat('é', 201)], 'med_object_reject_reason_check'];
        yield 'a reason with a leading space' => ['med_object', ['status' => 'rejected', 'reject_reason' => ' Not a picture.'], 'med_object_reject_reason_check'];
        yield 'a reason ending in CRLF' => ['med_object', ['status' => 'rejected', 'reject_reason' => "Not a picture.\r\n"], 'med_object_reject_reason_check'];
        yield 'a reason ending in a vertical tab' => ['med_object', ['status' => 'rejected', 'reject_reason' => "Not a picture.\v"], 'med_object_reject_reason_check'];
        yield 'a reason of only a tab' => ['med_object', ['status' => 'rejected', 'reject_reason' => "\t"], 'med_object_reject_reason_check'];
        yield 'an uppercase hash' => ['med_object', ['status' => 'ready', 'sha256' => strtoupper(self::SHA256), 'width' => 1440, 'height' => 900, 'variants' => self::VARIANTS], 'med_object_sha256_check'];
        yield 'a hash with a trailing newline' => ['med_object', ['status' => 'ready', 'sha256' => self::SHA256."\n", 'width' => 1440, 'height' => 900, 'variants' => self::VARIANTS], 'med_object_sha256_check'];
        yield 'a size of zero' => ['med_object', ['bytes' => 0], 'med_object_bytes_check'];
        yield 'a width of zero' => ['med_object', ['status' => 'ready', 'sha256' => self::SHA256, 'width' => 0, 'height' => 900, 'variants' => self::VARIANTS], 'med_object_dimensions_check'];
        yield 'a negative height' => ['med_object', ['status' => 'ready', 'sha256' => self::SHA256, 'width' => 1440, 'height' => -1, 'variants' => self::VARIANTS], 'med_object_dimensions_check'];
        yield 'variants as a list' => ['med_object', ['status' => 'ready', 'sha256' => self::SHA256, 'width' => 1440, 'height' => 900, 'variants' => '["acme/media/full.webp"]'], 'med_object_variants_check'];
        yield 'no variant' => ['med_object', ['status' => 'ready', 'sha256' => self::SHA256, 'width' => 1440, 'height' => 900, 'variants' => '{}'], 'med_object_variants_check'];
        yield 'a variant without a name' => ['med_object', ['status' => 'ready', 'sha256' => self::SHA256, 'width' => 1440, 'height' => 900, 'variants' => '{"full": "acme/media/full.webp", "": "acme/media/thumb.webp"}'], 'med_object_variants_check'];
        yield 'a variant without a storage key' => ['med_object', ['status' => 'ready', 'sha256' => self::SHA256, 'width' => 1440, 'height' => 900, 'variants' => '{"full": "acme/media/full.webp", "thumb": ""}'], 'med_object_variants_check'];
        yield 'a variant whose storage key is a number' => ['med_object', ['status' => 'ready', 'sha256' => self::SHA256, 'width' => 1440, 'height' => 900, 'variants' => '{"full": "acme/media/full.webp", "thumb": 1}'], 'med_object_variants_check'];
        yield 'a variant whose storage key is null' => ['med_object', ['status' => 'ready', 'sha256' => self::SHA256, 'width' => 1440, 'height' => 900, 'variants' => '{"full": "acme/media/full.webp", "thumb": null}'], 'med_object_variants_check'];
    }

    /**
     * @param array<string, int|string|null> $changedColumns
     */
    #[Test]
    #[DataProvider('validRows')]
    public function theDatabaseTakesARowTheDomainCanWrite(string $table, array $changedColumns): void
    {
        self::assertSame(1, $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int|string => $this->insert($table, $changedColumns)));
    }

    /**
     * @param array<string, int|string|null> $changedColumns
     */
    #[Test]
    #[DataProvider('refusedRows')]
    public function theDatabaseRefusesARowTheDomainCannotWrite(string $table, array $changedColumns, string $constraint): void
    {
        try {
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int|string => $this->insert($table, $changedColumns));
            self::fail(\sprintf('%s took a row that %s should refuse.', $table, $constraint));
        } catch (DriverException $refusal) {
            self::assertSame('23514', $refusal->getSQLState());
            self::assertStringContainsString(\sprintf('violates check constraint "%s"', $constraint), $refusal->getMessage());
        }

        self::assertSame(0, $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->connection()->fetchOne(\sprintf('SELECT count(*) FROM %s WHERE id = ?', $table), [self::ROW])));
    }

    /**
     * @param array<string, int|string|null> $changedColumns
     */
    private function insert(string $table, array $changedColumns): int|string
    {
        $validRow = match ($table) {
            'med_upload_intent' => ['organization_id' => self::ACME_FOODS, 'id' => self::ROW, 'user_id' => self::MIA, 'purpose' => 'screenshot', 'target_kind' => 'ticket', 'target_id' => 'SHOP-12', 'max_bytes' => 10_485_760, 'mime_allowed' => '{"image/png"}', 'storage_key' => 'acme/uploads/'.self::ROW, 'expires_at' => '2027-03-15 10:15:00+00', 'used_at' => null],
            'med_object' => ['organization_id' => self::ACME_FOODS, 'id' => self::ROW, 'storage_key' => 'acme/uploads/'.self::ROW, 'mime' => 'image/png', 'bytes' => 524_288, 'uploaded_by' => self::MIA, 'created_at' => '2027-03-15 10:08:00+00', 'status' => 'pending', 'reject_reason' => null, 'sha256' => null, 'width' => null, 'height' => null, 'variants' => null],
            default => throw new \LogicException(\sprintf('No test row for %s.', $table)),
        };
        $row = array_replace($validRow, $changedColumns);
        // Typed placeholders, so a text parameter reaches the array and jsonb columns as their type.
        $castTypes = ['mime_allowed' => 'text[]', 'variants' => 'jsonb', 'expires_at' => 'timestamptz', 'used_at' => 'timestamptz', 'created_at' => 'timestamptz'];
        $placeholders = array_map(static fn (string $column): string => isset($castTypes[$column]) ? \sprintf('CAST(? AS %s)', $castTypes[$column]) : '?', array_keys($row));

        return $this->connection()->executeStatement(
            \sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', array_keys($row)), implode(', ', $placeholders)),
            array_values($row),
        );
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }
}
