-- Search indexes for the free-text filter.
--
-- `?q=` matches `path`, `endpoint` and `raw_body`. Only `raw_body` had a trigram
-- index (migration 006), so a query that matched on path or endpoint alone fell back
-- to a sequential scan of every stored request — the exact cost the body index was
-- added to avoid. All three branches are now indexable, so the planner can pick a
-- BitmapOr instead of scanning the table.

CREATE INDEX IF NOT EXISTS incoming_requests_path_trgm_idx
    ON incoming_requests USING gin (path gin_trgm_ops);

CREATE INDEX IF NOT EXISTS incoming_requests_endpoint_trgm_idx
    ON incoming_requests USING gin (endpoint gin_trgm_ops);
