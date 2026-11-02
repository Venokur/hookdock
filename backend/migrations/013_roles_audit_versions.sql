-- Roles, an audit journal and workflow version history.
--
-- Until now one credential could do everything: the login token. A read token
-- existed, but it was all-or-nothing — there was no way to say "this colleague may
-- edit rules but must not create accounts", and no way to see who renamed a room
-- three days ago. The three additions below answer exactly those two questions.
--
-- Roles are stored on the user, not on the token: a token outlives a password
-- change and a role change should take effect at once. A *token scope* and a *role*
-- are different axes and both are enforced — `scope: read` refuses every write no
-- matter the role, and `viewer` refuses every write no matter the scope.
--
-- The default is `admin` on purpose. An existing installation has exactly one
-- seeded account, and defaulting to the weakest role would lock the operator out
-- of the very page that grants roles. New accounts are always created with an
-- explicit role, so the default only ever applies to a hand-written INSERT.
ALTER TABLE users ADD COLUMN IF NOT EXISTS role VARCHAR(16) NOT NULL DEFAULT 'admin';

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'users_role_check') THEN
        ALTER TABLE users ADD CONSTRAINT users_role_check
            CHECK (role IN ('admin', 'editor', 'viewer'));
    END IF;
END $$;

-- The journal of who changed what.
--
-- It is deliberately append-only and denormalized: `actor_email` and `room_slug`
-- are copied, because the point of an audit log is to stay readable after the
-- account was renamed and the room was deleted. The foreign keys are `SET NULL`
-- for the same reason — a deleted room must not erase the record that it was
-- deleted, only the pointer no longer resolves.
--
-- `changes` holds a small, already-redacted diff (field → [before, after]); the
-- secrets of `rooms` and `workflows.actions[].secret` never reach this table. A
-- diff bigger than the cap is replaced with a size marker instead of being
-- silently truncated into something that reads like a value.
CREATE TABLE IF NOT EXISTS audit_log (
    id           BIGSERIAL    PRIMARY KEY,
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    actor_id     BIGINT       REFERENCES users (id) ON DELETE SET NULL,
    actor_email  VARCHAR(255) NOT NULL DEFAULT '',
    actor_role   VARCHAR(16)  NOT NULL DEFAULT '',
    action       VARCHAR(64)  NOT NULL,
    object_type  VARCHAR(32)  NOT NULL DEFAULT '',
    object_id    BIGINT,
    object_label VARCHAR(200) NOT NULL DEFAULT '',
    room_id      BIGINT       REFERENCES rooms (id) ON DELETE SET NULL,
    room_slug    VARCHAR(64),
    summary      TEXT         NOT NULL DEFAULT '',
    changes      JSONB,
    ip           VARCHAR(64)
);

-- The page is "the latest events of a room", so the index leads with the room and
-- the time. A global feed is the same index scanned in time order; the partial
-- variant would be a second index for a rare query.
CREATE INDEX IF NOT EXISTS audit_log_room_created_idx
    ON audit_log (room_id, created_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS audit_log_created_idx
    ON audit_log (created_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS audit_log_action_idx
    ON audit_log (action, created_at DESC);

-- Version history of one scenario.
--
-- The editor already refuses a stale action list (`actions_version`), which
-- protects two open tabs. It does not help the operator who saved a wrong rule
-- yesterday and wants the one that worked. Each saved configuration is a row here,
-- so a rollback is a read plus an update, not a copy-paste from memory.
--
-- `actions` is stored exactly as `workflows.actions` stores it, secrets included:
-- the snapshot has to restore a signed forward correctly, and a version that
-- silently lost its signature would look valid while sending unsigned requests.
-- Reads of a version go through the same `redactActions()` as reads of the rule,
-- so the value still never leaves the API.
CREATE TABLE IF NOT EXISTS workflow_versions (
    id              BIGSERIAL    PRIMARY KEY,
    workflow_id     BIGINT       NOT NULL REFERENCES workflows (id) ON DELETE CASCADE,
    room_id         BIGINT       NOT NULL REFERENCES rooms (id) ON DELETE CASCADE,
    version         INT          NOT NULL,
    actions_version INT          NOT NULL DEFAULT 1,
    name            VARCHAR(160) NOT NULL,
    endpoint        VARCHAR(160) NOT NULL,
    enabled         BOOLEAN      NOT NULL DEFAULT TRUE,
    conditions      JSONB        NOT NULL DEFAULT '{}'::jsonb,
    actions         JSONB        NOT NULL DEFAULT '[]'::jsonb,
    priority        INT          NOT NULL DEFAULT 0,
    stop_processing BOOLEAN      NOT NULL DEFAULT FALSE,
    actor_id        BIGINT       REFERENCES users (id) ON DELETE SET NULL,
    actor_email     VARCHAR(255) NOT NULL DEFAULT '',
    change          VARCHAR(32)  NOT NULL DEFAULT 'update',
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    UNIQUE (workflow_id, version)
);

CREATE INDEX IF NOT EXISTS workflow_versions_workflow_idx
    ON workflow_versions (workflow_id, version DESC);

-- Rules that already exist get a first snapshot, so history is never "empty until
-- the next save" and a rollback has something to go back to. `change = 'import'`
-- marks it as a baseline rather than a recorded edit.
INSERT INTO workflow_versions (
    workflow_id, room_id, version, actions_version, name, endpoint, enabled,
    conditions, actions, priority, stop_processing, change
)
SELECT w.id, w.room_id, 1, w.actions_version, w.name, w.endpoint, w.enabled,
       w.conditions, w.actions, w.priority, w.stop_processing, 'baseline'
  FROM workflows w
 WHERE NOT EXISTS (
       SELECT 1 FROM workflow_versions v WHERE v.workflow_id = w.id
 );
