# Webinar Database Schema

## Goal

Schema for an on-demand webinar platform with per-attendee timeline playback, chat simulation, offers, and analytics.

## Tables

### `webinars`
- `id` bigint pk
- `uuid` uuid unique
- `user_id` bigint fk -> `users.id`
- `title` varchar
- `host_name` varchar
- `description` text nullable
- `video_source` enum(`youtube`,`vimeo`,`direct`)
- `video_url` varchar
- `video_duration_seconds` int unsigned nullable
- `thumbnail_path` varchar nullable
- `slug` varchar unique
- `min_viewers` int unsigned
- `max_viewers` int unsigned
- `is_published` boolean
- `published_at` timestamp nullable
- `email_settings` json nullable
- `playback_settings` json nullable
- timestamps

Indexes:
- `(user_id, is_published)`
- unique `(uuid)`
- unique `(slug)`

### `webinar_registrants`
- `id` bigint pk
- `webinar_id` bigint fk -> `webinars.id`
- `user_id` bigint nullable fk -> `users.id`
- `name` varchar
- `email` varchar
- `access_token` varchar unique
- `email_verified_at` timestamp nullable
- `registered_at` timestamp nullable
- `last_joined_at` timestamp nullable
- `is_subscribed` boolean
- timestamps

Indexes:
- unique `(webinar_id, email)`
- `(webinar_id, registered_at)`
- unique `(access_token)`

### `webinar_views`
- `id` bigint pk
- `webinar_id` bigint fk -> `webinars.id`
- `registrant_id` bigint nullable fk -> `webinar_registrants.id`
- `joined_at` timestamp
- `left_at` timestamp nullable
- `session_started_at` timestamp nullable
- `watch_duration_seconds` int unsigned
- `timeline_offset_seconds` int unsigned
- `ip_address` varchar(45) nullable
- `user_agent` text nullable
- timestamps

Indexes:
- `(webinar_id, joined_at)`

### `chat_messages`
- `id` bigint pk
- `webinar_id` bigint fk -> `webinars.id`
- `registrant_id` bigint nullable fk -> `webinar_registrants.id`
- `sender_type` enum(`host`,`attendee`,`system`)
- `sender_name` varchar nullable
- `message` text
- `timeline_second` int unsigned nullable
- `is_automated` boolean
- `meta` json nullable
- `sent_at` timestamp nullable
- timestamps

Indexes:
- `(webinar_id, timeline_second)`

### `webinar_offers`
- `id` bigint pk
- `webinar_id` bigint fk -> `webinars.id`
- `created_by` bigint nullable fk -> `users.id`
- `title` varchar
- `description` text nullable
- `trigger_second` int unsigned
- `button_text` varchar
- `button_url` varchar
- `display_mode` enum(`chat`,`popup`,`pinned`)
- `is_active` boolean
- timestamps

Indexes:
- `(webinar_id, trigger_second)`

### `scheduled_messages`
- `id` bigint pk
- `webinar_id` bigint fk -> `webinars.id`
- `trigger_second` int unsigned
- `sender_name` varchar nullable
- `message` text
- `is_active` boolean
- timestamps

Indexes:
- `(webinar_id, trigger_second)`

### `email_unsubscribes`
- `id` bigint pk
- `webinar_id` bigint nullable fk -> `webinars.id`
- `registrant_id` bigint nullable fk -> `webinar_registrants.id`
- `email` varchar
- `token` varchar unique
- `unsubscribed_at` timestamp
- `reason` varchar nullable
- timestamps

Indexes:
- `(webinar_id, email)`

### `analytics_events`
- `id` bigint pk
- `webinar_id` bigint fk -> `webinars.id`
- `registrant_id` bigint nullable fk -> `webinar_registrants.id`
- `view_id` bigint nullable fk -> `webinar_views.id`
- `event_type` varchar
- `event_data` json nullable
- `occurred_at` timestamp
- timestamps

Indexes:
- `(webinar_id, event_type)`
- `(occurred_at)`

## Timeline Architecture Rule

All timeline-driven behavior must be computed from attendee session offset.

Formula:
- `offset_seconds = now() - webinar_views.session_started_at`

Never drive timed offers/messages from global webinar publish time.
