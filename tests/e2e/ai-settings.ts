import { test } from '@playwright/test';
import { runAsDatabaseOwner } from './database';

/** The ai key of org_settings.data before a spec changed it: null when the key was missing. */
export interface StoredAiSettings {
  hasSettingsRow: boolean;
  aiJson: string | null;
}

function organizationIdSql(organizationSlug: string): string {
  return `(SELECT organization_id FROM org_organization WHERE slug = '${organizationSlug}')`;
}

function sqlText(text: string): string {
  return `'${text.replaceAll("'", "''")}'`;
}

export function readAiSettings(organizationSlug: string): StoredAiSettings {
  const [row] = runAsDatabaseOwner(`
    SELECT s.organization_id IS NOT NULL, coalesce(s.data ? 'ai', false), coalesce((s.data->'ai')::text, '')
    FROM org_organization o LEFT JOIN org_settings s ON s.organization_id = o.organization_id
    WHERE o.slug = '${organizationSlug}'`);
  if (undefined === row) {
    throw new Error(`The demo organization ${organizationSlug} is missing: run make seed.`);
  }
  const [hasSettingsRow, hasAiKey, ...aiJsonParts] = row.split('|');
  return { hasSettingsRow: hasSettingsRow === 't', aiJson: hasAiKey === 't' ? aiJsonParts.join('|') : null };
}

export function storeAiSettings(organizationSlug: string, ai: object): void {
  runAsDatabaseOwner(`
    INSERT INTO org_settings (organization_id, data, created_at)
    SELECT organization_id, jsonb_build_object('ai', ${sqlText(JSON.stringify(ai))}::jsonb), TIMESTAMPTZ '2026-11-01 08:00:00+00'
    FROM org_organization WHERE slug = '${organizationSlug}'
    ON CONFLICT (organization_id) DO UPDATE SET data = org_settings.data || EXCLUDED.data, version = org_settings.version + 1`);
}

/** Puts back only the ai key, so a setting another spec changed meanwhile stays. */
export function restoreAiSettings(organizationSlug: string, previous: StoredAiSettings): void {
  const organizationId = organizationIdSql(organizationSlug);
  if (null !== previous.aiJson) {
    runAsDatabaseOwner(`UPDATE org_settings SET data = jsonb_set(data, '{ai}', ${sqlText(previous.aiJson)}::jsonb), version = version + 1
      WHERE organization_id = ${organizationId}`);
    return;
  }
  runAsDatabaseOwner(`UPDATE org_settings SET data = data - 'ai', version = version + 1 WHERE organization_id = ${organizationId}`
    + (previous.hasSettingsRow ? '' : `; DELETE FROM org_settings WHERE organization_id = ${organizationId} AND data = '{}'::jsonb`));
}

/** Stores ai before each test of the calling describe and puts the organization's own AI settings back after it. */
export function useAiSettingsInEachTest(organizationSlug: string, ai: object): void {
  let previousAiSettings: StoredAiSettings | null = null;

  test.beforeEach(() => {
    previousAiSettings = readAiSettings(organizationSlug);
    storeAiSettings(organizationSlug, ai);
  });

  test.afterEach(() => {
    if (null !== previousAiSettings) {
      restoreAiSettings(organizationSlug, previousAiSettings);
      previousAiSettings = null;
    }
  });
}
