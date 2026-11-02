-- Access control for the catcher and for scripts.
--
-- 1. `rooms.ip_allowlist` narrows who may deliver to a room. The catcher is
--    public by design, and an HMAC secret proves *who sent* a payload, not where
--    it came from: an integration that runs behind a fixed NAT keeps a stable
--    address, and an operator who wants "only Stripe" can now say so. Empty
--    means "anywhere", which is the behaviour every existing room has today.
--
--    `TEXT[]` rather than jsonb: the list is a flat set of CIDR strings and the
--    only operation is "is this address inside any of them", done in PHP against
--    packed bytes (see `Support\IpAllowlist`).
--
-- 2. `api_tokens` are long-lived credentials for CI and integrations. The 12h
--    login JWT expires before a nightly job runs, and `POST /api/auth/read-token`
--    is a read-only convenience rather than something an integration can hold.
--
--    Only the SHA-256 of the token is stored. `prefix` is the lookup key: it is
--    not a secret (it appears in the list endpoint) and lets the request find its
--    row without scanning every hash. The plaintext is returned exactly once, at
--    issue time, like a room's HMAC secret.
CREATE TABLE IF NOT EXISTS api_tokens (
    id           BIGSERIAL PRIMARY KEY,
    user_id      BIGINT       NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    name         VARCHAR(120) NOT NULL,
    prefix       VARCHAR(16)  NOT NULL,
    token_hash   CHAR(64)     NOT NULL,
    scope        VARCHAR(16)  NOT NULL DEFAULT 'read',
    -- NULL means "does not expire": an integration that a nightly job calls must
    -- not stop working because nobody rotated it. The operator can still revoke.
    expires_at   TIMESTAMPTZ,
    last_used_at TIMESTAMPTZ,
    revoked_at   TIMESTAMPTZ,
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- The prefix is what a request looks up, so it is indexed and unique: a collision
-- would make two tokens share one lookup row and the hash check would decide which
-- one authenticated, which is exactly the ambiguity to avoid.
CREATE UNIQUE INDEX IF NOT EXISTS api_tokens_prefix_uniq ON api_tokens (prefix);
CREATE INDEX IF NOT EXISTS api_tokens_user_idx ON api_tokens (user_id, id DESC);

ALTER TABLE rooms
    ADD COLUMN IF NOT EXISTS ip_allowlist TEXT[] NOT NULL DEFAULT '{}';
