-- Observability: searching headers, and the indexes the analytics page reads.
--
-- The log already searches the path, the endpoint and the raw body. The header
-- block was the remaining gap: "which deliveries carried X-GitHub-Event: push"
-- could only be answered by opening rows one by one, and the operator could not
-- tell a provider's traffic from their own.
--
-- `headers` is `jsonb`, and the filter compares its text form, so the index is on
-- the same expression. A GIN trigram index is the only kind that serves
-- `ILIKE '%…%'`: a plain btree cannot answer a substring, and `jsonb_path_ops`
-- would only help fully specified paths. Verified with `EXPLAIN
-- (enable_seqscan = off)`: the query plans as a Bitmap Index Scan on this index.
CREATE INDEX IF NOT EXISTS incoming_requests_headers_trgm_idx
    ON incoming_requests USING gin ((headers::text) gin_trgm_ops);

-- Analytics aggregates steps by time window. `(run_id, id)` is the per-run read
-- path and cannot answer "the last 24 hours", which is now a page of its own.
CREATE INDEX IF NOT EXISTS workflow_run_steps_created_at_idx
    ON workflow_run_steps (created_at DESC);

-- `type` carries four values, so a composite is what makes "p95 per action type
-- over a window" read the index instead of the table.
CREATE INDEX IF NOT EXISTS workflow_run_steps_type_created_at_idx
    ON workflow_run_steps (type, created_at DESC);
