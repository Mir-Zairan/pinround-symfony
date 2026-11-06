SELECT 1;
SELECT current_setting('app.org_id', true);
SELECT NULLIF(current_setting('app.org_id', true), '')::uuid;
