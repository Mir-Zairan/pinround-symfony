-- Database roles of ADR-0005, run by the Postgres admin account on each database
-- (dev: pinround and pinround_test; CI: pinround_test; prod: at install, again after the first
-- migration, and after upgrades that change this file). Safe to re-run: it creates what is
-- missing and re-asserts the rest.
--
--   psql -v ON_ERROR_STOP=1 -d <database> \
--        -v app_password=... -v migrator_password=... \
--        -v retention_password=... -v readonly_password=... -f roles.sql
--
-- Passwords arrive as psql variables only; :'name' quotes them as SQL literals.

\set ON_ERROR_STOP on

\if :{?app_password}
\else
\set app_password ''
\endif
\if :{?migrator_password}
\else
\set migrator_password ''
\endif
\if :{?retention_password}
\else
\set retention_password ''
\endif
\if :{?readonly_password}
\else
\set readonly_password ''
\endif

-- PostgreSQL would turn an empty password into "no password", and the role could not log in.
SELECT 'DO $missing$ BEGIN RAISE EXCEPTION ''roles.sql needs -v app_password, migrator_password, retention_password and readonly_password, none empty''; END $missing$'
WHERE '' IN (:'app_password', :'migrator_password', :'retention_password', :'readonly_password')
\gexec

-- Roles are cluster-wide: the second database's run finds them and only re-asserts them.
SELECT format('CREATE ROLE %I', role_name)
FROM unnest(ARRAY['pinround_app', 'pinround_migrator', 'pinround_retention', 'pinround_readonly']) AS role_name
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = role_name)
\gexec

ALTER ROLE pinround_app WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD :'app_password';
ALTER ROLE pinround_migrator WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD :'migrator_password';
-- The only BYPASSRLS role: the nightly retention purge. Its table rights come with that job (P11).
ALTER ROLE pinround_retention WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION BYPASSRLS PASSWORD :'retention_password';
ALTER ROLE pinround_readonly WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD :'readonly_password';

-- The database owner owns schema public through pg_database_owner (PostgreSQL 15+) and has
-- CREATE on the database, which trusted extensions such as pg_trgm need.
SELECT format('ALTER DATABASE %I OWNER TO pinround_migrator', current_database())
\gexec

-- Older databases have objects the admin account created; the migrator must own them all.
-- Tables first: re-owning a table also re-owns its identity and serial sequences, which
-- refuse a direct ALTER SEQUENCE ... OWNER.
SELECT format(
    CASE c.relkind
        WHEN 'v' THEN 'ALTER VIEW %I.%I OWNER TO pinround_migrator'
        WHEN 'm' THEN 'ALTER MATERIALIZED VIEW %I.%I OWNER TO pinround_migrator'
        WHEN 'f' THEN 'ALTER FOREIGN TABLE %I.%I OWNER TO pinround_migrator'
        ELSE 'ALTER TABLE %I.%I OWNER TO pinround_migrator'
    END,
    n.nspname, c.relname)
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public'
  AND c.relkind IN ('r', 'p', 'v', 'm', 'f')
  AND c.relowner <> 'pinround_migrator'::regrole
  AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid AND d.deptype = 'e')
\gexec

SELECT format('ALTER SEQUENCE %I.%I OWNER TO pinround_migrator', n.nspname, c.relname)
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public'
  AND c.relkind = 'S'
  AND c.relowner <> 'pinround_migrator'::regrole
  AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid AND d.deptype = 'e')
\gexec

-- Nobody gets a right through PUBLIC: no CONNECT, no TEMPORARY, no schema USAGE.
SELECT format('REVOKE ALL ON DATABASE %I FROM PUBLIC', current_database())
\gexec
REVOKE ALL ON SCHEMA public FROM PUBLIC;

SELECT format('GRANT CONNECT ON DATABASE %I TO pinround_app, pinround_migrator, pinround_retention, pinround_readonly', current_database())
\gexec
GRANT USAGE ON SCHEMA public TO pinround_app, pinround_retention, pinround_readonly;

-- Tables the migrator creates from now on. No TRUNCATE, REFERENCES or TRIGGER for anyone.
ALTER DEFAULT PRIVILEGES FOR ROLE pinround_migrator IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO pinround_app;
ALTER DEFAULT PRIVILEGES FOR ROLE pinround_migrator IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO pinround_app;
ALTER DEFAULT PRIVILEGES FOR ROLE pinround_migrator IN SCHEMA public
    GRANT SELECT ON TABLES TO pinround_readonly;

-- Tables that already exist. Child partitions are skipped: rights live on the parent only,
-- so nobody can read a partition directly and skip its policy (data-model.md).
SELECT format('GRANT SELECT, INSERT, UPDATE, DELETE ON %1$I.%2$I TO pinround_app; GRANT SELECT ON %1$I.%2$I TO pinround_readonly', n.nspname, c.relname)
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public'
  AND c.relkind IN ('r', 'p', 'v', 'm', 'f')
  AND NOT c.relispartition
  AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid AND d.deptype = 'e')
\gexec
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO pinround_app;

-- Migration bookkeeping is the migrator's alone; the app never reads it. A new database gets this
-- table, with the default rights above, from its first migration, so the script runs again after it.
SELECT 'REVOKE ALL ON public.doctrine_migration_versions FROM pinround_app'
WHERE to_regclass('public.doctrine_migration_versions') IS NOT NULL
\gexec
