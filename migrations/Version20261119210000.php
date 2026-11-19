<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Takes pinround_readonly's SELECT on api_idempotency_key back (granted by Version20261119200000): a stored answer is
 * the response word for word, the presigned put_url of POST /api/v1/uploads included, and support tooling has no use
 * for it that would justify reading a live upload URL.
 */
final class Version20261119210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Revoke pinround_readonly SELECT on api_idempotency_key, whose stored answers hold presigned upload URLs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('REVOKE SELECT ON api_idempotency_key FROM pinround_readonly');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('GRANT SELECT ON api_idempotency_key TO pinround_readonly');
    }
}
