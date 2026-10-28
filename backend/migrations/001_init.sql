-- Schema for the webhook router. Applied once by backend/migrate.php.

CREATE TABLE IF NOT EXISTS users (
    id            BIGSERIAL PRIMARY KEY,
    email         VARCHAR(255) NOT NULL,
    password_hash TEXT         NOT NULL,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    last_login_at TIMESTAMPTZ
);

CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_uniq ON users (lower(email));

-- Default admin: admin@l.loc / 123456 (bcrypt, cost 12). Change the password after first login.
INSERT INTO users (email, password_hash)
VALUES ('admin@l.loc', '$2y$12$17qq2VdkGLwTG9uVDZXe6eIqU94aYpqq9eYeobLh2Xli//vVtqp2y')
ON CONFLICT (lower(email)) DO NOTHING;

CREATE TABLE IF NOT EXISTS workflows (
    id         BIGSERIAL PRIMARY KEY,
    name       VARCHAR(160) NOT NULL,
    endpoint   VARCHAR(160) NOT NULL DEFAULT '*',
    enabled    BOOLEAN      NOT NULL DEFAULT TRUE,
    conditions JSONB        NOT NULL DEFAULT '[]'::jsonb,
    actions    JSONB        NOT NULL DEFAULT '[]'::jsonb,
    created_at TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS workflows_endpoint_idx ON workflows (endpoint);
CREATE INDEX IF NOT EXISTS workflows_enabled_idx ON workflows (enabled);

CREATE TABLE IF NOT EXISTS incoming_requests (
    id           BIGSERIAL PRIMARY KEY,
    endpoint     VARCHAR(160) NOT NULL,
    method       VARCHAR(16)  NOT NULL,
    path         TEXT         NOT NULL,
    query        JSONB        NOT NULL DEFAULT '{}'::jsonb,
    headers      JSONB        NOT NULL DEFAULT '{}'::jsonb,
    body         JSONB,
    raw_body     TEXT,
    content_type VARCHAR(255),
    body_bytes   INTEGER      NOT NULL DEFAULT 0,
    ip           VARCHAR(64),
    received_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS incoming_requests_received_at_idx ON incoming_requests (received_at DESC);
CREATE INDEX IF NOT EXISTS incoming_requests_method_idx ON incoming_requests (method);
CREATE INDEX IF NOT EXISTS incoming_requests_endpoint_idx ON incoming_requests (endpoint);

CREATE TABLE IF NOT EXISTS workflow_runs (
    id          BIGSERIAL PRIMARY KEY,
    workflow_id BIGINT      NOT NULL REFERENCES workflows (id) ON DELETE CASCADE,
    request_id  BIGINT      NOT NULL REFERENCES incoming_requests (id) ON DELETE CASCADE,
    status      VARCHAR(16) NOT NULL,
    message     TEXT,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS workflow_runs_request_idx ON workflow_runs (request_id);
CREATE INDEX IF NOT EXISTS workflow_runs_workflow_idx ON workflow_runs (workflow_id);
CREATE INDEX IF NOT EXISTS workflow_runs_created_at_idx ON workflow_runs (created_at DESC);
