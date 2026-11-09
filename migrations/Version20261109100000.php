<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\SharedKernel\Infrastructure\Doctrine\Migration\TenantTable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * org_invitation, a tenant table with row-level security: who was invited to an organization, with which org roles
 * and optionally which team. The app deletes an invitation when the same email is invited again. token_hash stays
 * NULL until the email worker attaches the link's token. Then resolve_invitation gets its body: the ids of the open
 * invitation with that token hash, read through resolver_read.
 */
final class Version20261109100000 extends AbstractMigration
{
    private const string RESOLVE_INVITATION_HEAD = 'CREATE OR REPLACE FUNCTION public.resolve_invitation(p_token_hash bytea)'
        .' RETURNS TABLE (invitation_id uuid, organization_id uuid) LANGUAGE sql STABLE SECURITY DEFINER'
        .' SET search_path = pg_catalog, public, pg_temp AS $resolver$ %s $resolver$';

    public function getDescription(): string
    {
        return 'Create org_invitation with row-level security and give resolve_invitation its body';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE org_invitation (organization_id UUID NOT NULL, id UUID NOT NULL, email CITEXT NOT NULL,'
            .' org_roles TEXT[] NOT NULL, team_id UUID DEFAULT NULL, team_role TEXT DEFAULT NULL, token_hash BYTEA DEFAULT NULL,'
            .' invited_by UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,'
            .' expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, accepted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,'
            .' PRIMARY KEY (organization_id, id),'
            .' CONSTRAINT org_invitation_email_check CHECK (email::text = lower(email::text)),'
            ." CONSTRAINT org_invitation_org_roles_check CHECK (org_roles <@ ARRAY['admin', 'manager']::text[]),"
            // A team has exactly one lead, so an invitation never makes one.
            ." CONSTRAINT org_invitation_team_role_check CHECK (team_role IN ('member', 'viewer')),"
            .' CONSTRAINT org_invitation_team_check CHECK ((team_id IS NULL) = (team_role IS NULL)))',
        );
        // resolve_invitation runs before any tenant is known, so this index does not start with organization_id.
        $this->addSql('CREATE UNIQUE INDEX org_invitation_token_hash_key ON org_invitation (token_hash)');
        // One invitation not yet accepted per email; an expired one counts until a new invitation removes it.
        $this->addSql('CREATE UNIQUE INDEX org_invitation_organization_id_email_key ON org_invitation (organization_id, email) WHERE (accepted_at IS NULL)');

        foreach (TenantTable::enableRls('org_invitation') as $sql) {
            $this->addSql($sql);
        }
        $this->addSql('GRANT SELECT ON org_invitation TO pinround_readonly');
        $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON org_invitation TO pinround_app');
        foreach (TenantTable::allowResolverRead('org_invitation', ['id', 'organization_id', 'token_hash', 'accepted_at', 'expires_at']) as $sql) {
            $this->addSql($sql);
        }

        $this->replaceResolveInvitationBody(
            'SELECT i.id, i.organization_id FROM public.org_invitation i'
            .' WHERE i.token_hash = p_token_hash AND i.accepted_at IS NULL AND i.expires_at > now()',
        );
    }

    public function down(Schema $schema): void
    {
        $this->replaceResolveInvitationBody('SELECT NULL::uuid, NULL::uuid WHERE false');
        $this->addSql('DROP TABLE org_invitation');
    }

    /**
     * As in Version20261108140000: only the owner may replace a function, so the migrator acts as pinround_resolver,
     * which needs CREATE on the schema for it and keeps none afterwards; every attribute is repeated, as CREATE OR
     * REPLACE resets the ones left out.
     */
    private function replaceResolveInvitationBody(string $body): void
    {
        $this->addSql('GRANT CREATE ON SCHEMA public TO pinround_resolver');
        $this->addSql('SET ROLE pinround_resolver');
        $this->addSql(\sprintf(self::RESOLVE_INVITATION_HEAD, $body));
        $this->addSql('RESET ROLE');
        $this->addSql('REVOKE CREATE ON SCHEMA public FROM pinround_resolver');
    }
}
