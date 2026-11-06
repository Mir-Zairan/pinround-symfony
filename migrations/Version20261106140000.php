<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106140000 extends AbstractMigration
{
    /**
     * Stubs that return no row until the phase that adds each table replaces the body with
     * CREATE OR REPLACE, keeping the signature. They return ids only (data-model.md).
     *
     * @var array<string, array{arguments: string, argumentTypes: string, returns: list<string>}>
     */
    private const array RESOLVERS = [
        'resolve_api_token' => ['arguments' => 'token_hash bytea', 'argumentTypes' => 'bytea', 'returns' => ['token_id', 'organization_id', 'user_id']],
        'resolve_user_orgs' => ['arguments' => 'user_id uuid', 'argumentTypes' => 'uuid', 'returns' => ['organization_id']],
        'resolve_invitation' => ['arguments' => 'token_hash bytea', 'argumentTypes' => 'bytea', 'returns' => ['invitation_id', 'organization_id']],
        'resolve_sso_provider' => ['arguments' => 'issuer text, domain text', 'argumentTypes' => 'text, text', 'returns' => ['provider_config_id', 'organization_id']],
        'resolve_jira_connection' => ['arguments' => 'connection_id uuid', 'argumentTypes' => 'uuid', 'returns' => ['organization_id']],
        'resolve_laptop_package' => ['arguments' => 'package_id uuid', 'argumentTypes' => 'uuid', 'returns' => ['organization_id', 'user_id']],
    ];

    public function getDescription(): string
    {
        return 'Create the six resolver functions, owned by pinround_resolver and executable by pinround_app only';
    }

    public function up(Schema $schema): void
    {
        foreach (self::RESOLVERS as $name => $resolver) {
            $columns = array_map(static fn (string $column): string => $column.' uuid', $resolver['returns']);
            $noRow = 'SELECT '.implode(', ', array_fill(0, \count($resolver['returns']), 'NULL::uuid')).' WHERE false';
            $function = \sprintf('public.%s(%s)', $name, $resolver['argumentTypes']);

            // pg_temp is not listed, so it is searched first for tables; no pinround role has TEMPORARY (roles.sql).
            $this->addSql(\sprintf(
                'CREATE FUNCTION public.%s(%s) RETURNS TABLE (%s) LANGUAGE sql STABLE SECURITY DEFINER'
                .' SET search_path = pg_catalog, public AS $resolver$ %s $resolver$',
                $name,
                $resolver['arguments'],
                implode(', ', $columns),
                $noRow,
            ));
            // Before the owner change: the migrator does not inherit pinround_resolver's rights, so it
            // could not grant on the function afterwards. The new owner takes these grants over.
            $this->addSql(\sprintf('REVOKE EXECUTE ON FUNCTION %s FROM PUBLIC', $function));
            $this->addSql(\sprintf('GRANT EXECUTE ON FUNCTION %s TO pinround_app', $function));
        }

        // A new owner must hold CREATE on the schema; pinround_resolver keeps none afterwards.
        $this->addSql('GRANT CREATE ON SCHEMA public TO pinround_resolver');
        foreach (self::RESOLVERS as $name => $resolver) {
            $this->addSql(\sprintf('ALTER FUNCTION public.%s(%s) OWNER TO pinround_resolver', $name, $resolver['argumentTypes']));
        }
        $this->addSql('REVOKE CREATE ON SCHEMA public FROM pinround_resolver');
    }

    public function down(Schema $schema): void
    {
        // Only the owner may drop, and the migrator holds pinround_resolver's rights only after SET ROLE.
        $this->addSql('SET ROLE pinround_resolver');
        foreach (self::RESOLVERS as $name => $resolver) {
            $this->addSql(\sprintf('DROP FUNCTION public.%s(%s)', $name, $resolver['argumentTypes']));
        }
        $this->addSql('RESET ROLE');
    }
}
