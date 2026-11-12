<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The search column of tkt_ticket and its GIN indexes (ADR-0022): a stored tsvector of key and title (weight A) and
 * description (B) in the simple configuration without accents, and trigrams of the title for typos. A GIN index has no
 * key order to lead with organization_id; the query's organization filter and row-level security narrow its matches.
 */
final class Version20261112100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the generated search column of tkt_ticket with its full-text and trigram GIN indexes';
    }

    public function up(Schema $schema): void
    {
        // pg_trgm comes from Version20261106120000.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS unaccent');
        // unaccent() is only STABLE, since it finds its dictionary through search_path, so a generated column cannot
        // call it. Naming the dictionary and fixing search_path makes the result depend on the text alone.
        $this->addSql(
            'CREATE FUNCTION public.tkt_unaccent(text) RETURNS text LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE'
            .' SET search_path = pg_catalog, pg_temp'
            ." RETURN public.unaccent('public.unaccent'::regdictionary, \$1)",
        );
        $this->addSql(
            'ALTER TABLE tkt_ticket ADD COLUMN search tsvector GENERATED ALWAYS AS ('
            ."setweight(to_tsvector('simple'::regconfig, public.tkt_unaccent(key)), 'A')"
            ." || setweight(to_tsvector('simple'::regconfig, public.tkt_unaccent(title)), 'A')"
            ." || setweight(to_tsvector('simple'::regconfig, public.tkt_unaccent(description)), 'B')) STORED",
        );
        $this->addSql('CREATE INDEX tkt_ticket_search_idx ON tkt_ticket USING gin (search)');
        $this->addSql('CREATE INDEX tkt_ticket_title_trgm_idx ON tkt_ticket USING gin (title gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX tkt_ticket_title_trgm_idx');
        $this->addSql('ALTER TABLE tkt_ticket DROP COLUMN search');
        $this->addSql('DROP FUNCTION public.tkt_unaccent(text)');
        // The extension stays: up() skips it when it already exists, so down() cannot know whether up() created it.
    }
}
