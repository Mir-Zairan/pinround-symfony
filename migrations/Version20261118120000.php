<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ast_local_package, the prompt packages issued for laptop AI (P08.T9), with row-level security. A package is used
 * once: the laptop's result lands on its row in the same UPDATE that sets used_at. Then resolve_laptop_package gets its
 * body: the organization and user of the package with that id, read through resolver_read. No entity maps the table,
 * so the schema_filter hides it.
 */
final class Version20261118120000 extends AbstractMigration
{
    private const string RESOLVE_LAPTOP_PACKAGE_HEAD = 'CREATE OR REPLACE FUNCTION public.resolve_laptop_package(p_package_id uuid)'
        .' RETURNS TABLE (organization_id uuid, user_id uuid) LANGUAGE sql STABLE SECURITY DEFINER'
        .' SET search_path = pg_catalog, public, pg_temp AS $resolver$ %s $resolver$';

    public function getDescription(): string
    {
        return 'Create ast_local_package with row-level security and give resolve_laptop_package its body';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE ast_local_package (organization_id UUID NOT NULL, id UUID NOT NULL, user_id UUID NOT NULL,'
            .' ticket_id UUID NOT NULL, purpose TEXT NOT NULL, prompt_version TEXT NOT NULL,'
            .' created_at TIMESTAMP WITH TIME ZONE NOT NULL, expires_at TIMESTAMP WITH TIME ZONE NOT NULL,'
            .' used_at TIMESTAMP WITH TIME ZONE DEFAULT NULL, result_text TEXT DEFAULT NULL, model TEXT DEFAULT NULL,'
            .' PRIMARY KEY (organization_id, id),'
            // 'intake' is for "Check with AI" on the laptop (P08.T9 step 4); nothing writes it yet.
            ." CONSTRAINT ast_local_package_purpose_check CHECK (purpose IN ('summary', 'intake')),"
            .' CONSTRAINT ast_local_package_prompt_version_check CHECK (char_length(prompt_version) BETWEEN 1 AND 200),'
            .' CONSTRAINT ast_local_package_expires_at_check CHECK (expires_at > created_at),'
            // A comment's cap (cnv_message_body_check), as the result is user-provided content.
            .' CONSTRAINT ast_local_package_result_text_check CHECK (char_length(result_text) BETWEEN 1 AND 10000),'
            // LocalModelName::PATTERN; in a PostgreSQL regex "$" matches only at the end of the string.
            ." CONSTRAINT ast_local_package_model_check CHECK (model ~ '^[a-z0-9:._-]{1,64}$'),"
            .' CONSTRAINT ast_local_package_result_check CHECK ((used_at IS NULL) = (result_text IS NULL) AND (used_at IS NULL) = (model IS NULL)))',
        );
        // The cap on open packages per person counts by these three.
        $this->addSql('CREATE INDEX ast_local_package_user_id_expires_at_idx ON ast_local_package (organization_id, user_id, expires_at)');
        // resolve_laptop_package runs before the package's organization is known, so this index does not start with organization_id.
        $this->addSql('CREATE UNIQUE INDEX ast_local_package_id_key ON ast_local_package (id)');

        foreach (TenantTable::enableRls('ast_local_package') as $statement) {
            $this->addSql($statement);
        }
        // UPDATE is for the one result a package takes; nothing deletes yet (the purge comes with the retention job).
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON ast_local_package TO pinround_app');
        $this->addSql('GRANT SELECT ON ast_local_package TO pinround_readonly');
        foreach (TenantTable::allowResolverRead('ast_local_package', ['id', 'organization_id', 'user_id']) as $statement) {
            $this->addSql($statement);
        }

        $this->replaceResolveLaptopPackageBody(
            'SELECT p.organization_id, p.user_id FROM public.ast_local_package p WHERE p.id = p_package_id',
        );
    }

    public function down(Schema $schema): void
    {
        $this->replaceResolveLaptopPackageBody('SELECT NULL::uuid, NULL::uuid WHERE false');
        $this->addSql('DROP TABLE ast_local_package');
    }

    /**
     * As in Version20261109100000: only the owner may replace a function, so the migrator acts as pinround_resolver,
     * which needs CREATE on the schema for it and keeps none afterwards; every attribute is repeated, as CREATE OR
     * REPLACE resets the ones left out.
     */
    private function replaceResolveLaptopPackageBody(string $body): void
    {
        $this->addSql('GRANT CREATE ON SCHEMA public TO pinround_resolver');
        $this->addSql('SET ROLE pinround_resolver');
        $this->addSql(\sprintf(self::RESOLVE_LAPTOP_PACKAGE_HEAD, $body));
        $this->addSql('RESET ROLE');
        $this->addSql('REVOKE CREATE ON SCHEMA public FROM pinround_resolver');
    }
}
