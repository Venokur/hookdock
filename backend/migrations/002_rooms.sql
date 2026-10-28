-- Rooms: isolated buckets for incoming requests and routing workflows.
--
-- Existing deployments start from a clean slate on purpose: rows created before
-- rooms existed have no room to belong to, so they are dropped rather than
-- guessed into a room. The single seeded room keeps the documented webhook URL
-- working right after the upgrade.

CREATE TABLE IF NOT EXISTS rooms (
    id         BIGSERIAL PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    slug       VARCHAR(64)  NOT NULL,
    created_at TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX IF NOT EXISTS rooms_slug_lower_uniq ON rooms (lower(slug));

TRUNCATE workflow_runs, incoming_requests, workflows RESTART IDENTITY;

INSERT INTO rooms (name, slug)
SELECT 'Общая', 'default'
WHERE NOT EXISTS (SELECT 1 FROM rooms WHERE lower(slug) = 'default');

ALTER TABLE incoming_requests ADD COLUMN IF NOT EXISTS room_id BIGINT NOT NULL REFERENCES rooms (id) ON DELETE CASCADE;
ALTER TABLE workflows         ADD COLUMN IF NOT EXISTS room_id BIGINT NOT NULL REFERENCES rooms (id) ON DELETE CASCADE;

CREATE INDEX IF NOT EXISTS incoming_requests_room_received_idx ON incoming_requests (room_id, received_at DESC);
CREATE INDEX IF NOT EXISTS workflows_room_idx ON workflows (room_id);
