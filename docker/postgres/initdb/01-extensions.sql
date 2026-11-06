-- pgvector is skipped: the stock postgres image does not ship it. Database roles belong to P00.T3.
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS citext;
CREATE EXTENSION IF NOT EXISTS pgcrypto;
