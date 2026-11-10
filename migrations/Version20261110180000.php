<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives every team a slug, unique within its organization (TeamSlug). Teams that have none get one made from the
 * name by TeamSlug::fromName's rule, copied here as SQL (TeamSlugBackfillTest compares the two), with "-2", "-3"…
 * on a clash, in the order the teams were created, as CreateTeam would have given them.
 *
 * Reading the teams of every organization is a read FORCE row-level security cannot allow (see Version20261110140000),
 * so the DO block lifts FORCE on org_team for the migrator, its owner, and forces it again before it ends. The block is
 * one statement: if it fails, the lift rolls back with it, and other sessions never see it.
 */
final class Version20261110180000 extends AbstractMigration
{
    private const string BACKFILL = <<<'SQL'
        DO $backfill$
        DECLARE
            team record;
            candidate text;
            suffix int;
        BEGIN
            ALTER TABLE org_team NO FORCE ROW LEVEL SECURITY;
            FOR team IN
                SELECT t.organization_id, t.id, coalesce(nullif(rtrim(left(btrim(lower(regexp_replace(
                    translate(
                        replace(replace(replace(replace(replace(replace(replace(replace(replace(t.name::text,
                            'Æ', 'ae'), 'Þ', 'th'), 'ß', 'ss'), 'æ', 'ae'), 'þ', 'th'), 'Ĳ', 'ij'), 'ĳ', 'ij'), 'Œ', 'oe'), 'œ', 'oe'),
                        'ÀÁÂÃÄÅÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝàáâãäåçèéêëìíîïðñòóôõöøùúûüýÿĀāĂăĄąĆćĈĉĊċČčĎďĐđĒēĔĕĖėĘęĚěĜĝĞğĠġĢģĤĥĦħĨĩĪīĬĭĮįİıĴĵĶķĸĹĺĻļĽľĿŀŁłŃńŅņŇňŉŊŋŌōŎŏŐőŔŕŖŗŘřŚśŜŝŞşŠšŢţŤťŦŧŨũŪūŬŭŮůŰűŲųŴŵŶŷŸŹźŻżŽžſ',
                        'aaaaaaceeeeiiiidnoooooouuuuyaaaaaaceeeeiiiidnoooooouuuuyyaaaaaaccccccccddddeeeeeeeeeegggggggghhhhiiiiiiiiiijjkkkllllllllllnnnnnnnnnoooooorrrrrrssssssssttttttuuuuuuuuuuuuwwyyyzzzzzzs'
                    ),
                    '[^A-Za-z0-9]+', '-', 'g')), '-'), 50), '-'), ''), 'team') AS base
                FROM org_team t
                WHERE t.slug IS NULL
                ORDER BY t.created_at, t.id
            LOOP
                candidate := team.base;
                suffix := 1;
                WHILE EXISTS (SELECT 1 FROM org_team taken WHERE taken.organization_id = team.organization_id AND taken.slug = candidate) LOOP
                    suffix := suffix + 1;
                    candidate := team.base || '-' || suffix;
                END LOOP;
                UPDATE org_team SET slug = candidate WHERE organization_id = team.organization_id AND id = team.id;
            END LOOP;
            ALTER TABLE org_team FORCE ROW LEVEL SECURITY;
        END
        $backfill$
        SQL;

    public function getDescription(): string
    {
        return 'Give every team a slug unique within its organization';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE org_team ADD slug TEXT');
        $this->addSql(self::BACKFILL);
        $this->addSql('ALTER TABLE org_team ALTER slug SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX org_team_organization_id_slug_key ON org_team (organization_id, slug)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX org_team_organization_id_slug_key');
        $this->addSql('ALTER TABLE org_team DROP slug');
    }
}
