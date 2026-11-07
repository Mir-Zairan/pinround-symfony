<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The Organization tables (data-model.md, Organization), all tenant tables with row-level security; org_organization's
 * key is its own organization_id, so an organization's row is visible only while it is the tenant. No foreign key
 * points at idn_user: global tables are reached only through Identity. pinround_readonly reads under the tenant
 * policy. The app may delete memberships and team members (people leave), never organizations, teams or settings.
 * Then resolve_user_orgs gets its body: the organization ids of one user's memberships, read through resolver_read.
 */
final class Version20261108140000 extends AbstractMigration
{
    private const string RESOLVE_USER_ORGS_HEAD = 'CREATE OR REPLACE FUNCTION public.resolve_user_orgs(p_user_id uuid)'
        .' RETURNS TABLE (organization_id uuid) LANGUAGE sql STABLE SECURITY DEFINER'
        .' SET search_path = pg_catalog, public, pg_temp AS $resolver$ %s $resolver$';

    public function getDescription(): string
    {
        return 'Create the Organization tables with row-level security and give resolve_user_orgs its body';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE org_organization (organization_id UUID NOT NULL, name TEXT NOT NULL, slug TEXT NOT NULL,'
            .' status TEXT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (organization_id),'
            ." CONSTRAINT org_organization_status_check CHECK (status IN ('active')))",
        );
        // Slugs are unique across organizations, so this is the one index that does not start with organization_id.
        $this->addSql('CREATE UNIQUE INDEX org_organization_slug_key ON org_organization (slug)');

        $this->addSql(
            'CREATE TABLE org_membership (organization_id UUID NOT NULL, user_id UUID NOT NULL, org_roles TEXT[] NOT NULL,'
            .' joined_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (organization_id, user_id),'
            ." CONSTRAINT org_membership_org_roles_check CHECK (org_roles <@ ARRAY['admin', 'manager']::text[]))",
        );

        $this->addSql(
            'CREATE TABLE org_team (id UUID NOT NULL, organization_id UUID NOT NULL, name CITEXT NOT NULL,'
            .' lead_user_id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))',
        );
        // citext: two teams of one organization cannot share a name in any letter case.
        $this->addSql('CREATE UNIQUE INDEX org_team_organization_id_name_key ON org_team (organization_id, name)');

        $this->addSql(
            'CREATE TABLE org_team_member (organization_id UUID NOT NULL, team_id UUID NOT NULL, user_id UUID NOT NULL,'
            .' team_role TEXT NOT NULL, job TEXT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,'
            .' PRIMARY KEY (organization_id, team_id, user_id),'
            .' CONSTRAINT org_team_member_team_id_fkey FOREIGN KEY (team_id) REFERENCES org_team (id),'
            ." CONSTRAINT org_team_member_team_role_check CHECK (team_role IN ('lead', 'member', 'viewer')),"
            ." CONSTRAINT org_team_member_job_check CHECK (job IN ('tester', 'developer', 'other')))",
        );
        $this->addSql('CREATE INDEX org_team_member_organization_id_user_id_idx ON org_team_member (organization_id, user_id)');
        $this->addSql('CREATE INDEX org_team_member_team_id_idx ON org_team_member (team_id)');

        $this->addSql(
            'CREATE TABLE org_settings (organization_id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, data JSONB NOT NULL,'
            .' created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (organization_id))',
        );

        foreach (['org_organization', 'org_membership', 'org_team', 'org_team_member', 'org_settings'] as $table) {
            foreach (TenantTable::enableRls($table) as $sql) {
                $this->addSql($sql);
            }
            $this->addSql(\sprintf('GRANT SELECT ON %s TO pinround_readonly', $table));
        }
        $this->addSql('GRANT SELECT, INSERT, UPDATE ON org_organization, org_team, org_settings TO pinround_app');
        $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON org_membership, org_team_member TO pinround_app');
        foreach (TenantTable::allowResolverRead('org_membership', ['organization_id', 'user_id']) as $sql) {
            $this->addSql($sql);
        }

        $this->replaceResolveUserOrgsBody('SELECT m.organization_id FROM public.org_membership m WHERE m.user_id = p_user_id ORDER BY m.organization_id');
    }

    public function down(Schema $schema): void
    {
        $this->replaceResolveUserOrgsBody('SELECT NULL::uuid WHERE false');
        $this->addSql('DROP TABLE org_settings');
        $this->addSql('DROP TABLE org_team_member');
        $this->addSql('DROP TABLE org_team');
        $this->addSql('DROP TABLE org_membership');
        $this->addSql('DROP TABLE org_organization');
    }

    /**
     * Only the owner may replace a function, so the migrator acts as pinround_resolver, which needs CREATE on the
     * schema for it and keeps none afterwards. The signature stays, and with it the owner and the EXECUTE grants;
     * every attribute is repeated, as CREATE OR REPLACE resets the ones left out.
     */
    private function replaceResolveUserOrgsBody(string $body): void
    {
        $this->addSql('GRANT CREATE ON SCHEMA public TO pinround_resolver');
        $this->addSql('SET ROLE pinround_resolver');
        $this->addSql(\sprintf(self::RESOLVE_USER_ORGS_HEAD, $body));
        $this->addSql('RESET ROLE');
        $this->addSql('REVOKE CREATE ON SCHEMA public FROM pinround_resolver');
    }
}
