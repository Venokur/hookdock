-- Security and retention (iteration 2).
--
-- rate_limits: fixed-window counters for the login form and the public catcher.
-- In Postgres rather than in-process: a per-container counter would reset on every
-- `compose up`, which is exactly when an attacker would retry.

CREATE TABLE IF NOT EXISTS rate_limits (
    bucket       VARCHAR(64)  NOT NULL,
    subject      VARCHAR(160) NOT NULL,
    window_start TIMESTAMPTZ  NOT NULL,
    hits         INTEGER      NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket, subject, window_start)
);

CREATE INDEX IF NOT EXISTS rate_limits_window_idx ON rate_limits (window_start);

-- Retention pruning needs "older than N days" over the received timestamp, which
-- the existing DESC index cannot serve as a DELETE scan.
CREATE INDEX IF NOT EXISTS incoming_requests_received_at_plain_idx ON incoming_requests (received_at);

-- The `q` filter is `ILIKE '%…%'` over raw_body. Without pg_trgm that is a
-- sequential scan of every stored payload on each keystroke.
CREATE EXTENSION IF NOT EXISTS pg_trgm;

CREATE INDEX IF NOT EXISTS incoming_requests_raw_body_trgm_idx
    ON incoming_requests USING gin (raw_body gin_trgm_ops);
