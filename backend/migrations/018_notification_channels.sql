-- Notification channels: a place to say "something happened" that is not an HTTP
-- endpoint the operator has to build themselves.
--
-- A `forward` can already reach any URL, so why a channel? Because the two things
-- an operator actually wants — a Slack incoming webhook and a Telegram bot — differ
-- in *shape*, not only in address: Slack takes `{"text": …}` on a URL that is
-- itself the credential, Telegram takes `{chat_id, text}` and carries the
-- credential in the path as `bot<token>`. Encoding that in every rule would mean
-- the chat id and the bot token live inside `workflows.actions`, copied per rule,
-- and rotating the token would be a hunt through the action list.
--
-- So a channel is a room-scoped resource with a name, and rules point at it by id.
-- Rotating a token is one edit.
--
-- Both credentials are secrets and are never echoed back: the API exposes
-- `has_url`, `url_hint` and `has_token` instead, exactly like `rooms.secret` and
-- `actions[].secret`. The URL is a secret because a Slack webhook URL *is* the
-- authorization — anyone holding it can post to the channel.
--
-- `notify_on_failure` is the second half of the feature: the installation-wide
-- `ALERT_WEBHOOK_URL` answers "tell me about a broken install", this answers "tell
-- *this room's* chat when one of its rules fails", which is the scope an operator
-- of one integration actually cares about.
CREATE TABLE IF NOT EXISTS notification_channels (
    id         BIGSERIAL PRIMARY KEY,
    room_id    BIGINT       NOT NULL REFERENCES rooms (id) ON DELETE CASCADE,
    name       VARCHAR(120) NOT NULL,
    kind       VARCHAR(16)  NOT NULL,
    -- Slack: the incoming-webhook URL. Telegram: NULL (the address is derived).
    url        TEXT,
    -- Telegram: bot token and chat, both from @BotFather and the chat itself.
    bot_token  TEXT,
    chat_id    VARCHAR(64),
    notify_on_failure BOOLEAN NOT NULL DEFAULT false,
    enabled    BOOLEAN      NOT NULL DEFAULT true,
    -- Evidence that the channel works *now*. A channel that has never been tested
    -- and one that failed at 09:12 look identical in a list without this.
    last_sent_at TIMESTAMPTZ,
    last_error   TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- The list is per room and small; the index is what makes "channels of this room"
-- a lookup instead of a scan once an installation has many rooms.
CREATE INDEX IF NOT EXISTS notification_channels_room_idx
    ON notification_channels (room_id, id);

-- One name per room: the editor picks a channel from a dropdown, and two entries
-- called "Ops" make that dropdown unable to answer which one is meant.
CREATE UNIQUE INDEX IF NOT EXISTS notification_channels_name_uniq
    ON notification_channels (room_id, lower(name));

-- Delivery on failure scans channels by room and flag, and only enabled ones.
CREATE INDEX IF NOT EXISTS notification_channels_failure_idx
    ON notification_channels (room_id)
    WHERE enabled AND notify_on_failure;
