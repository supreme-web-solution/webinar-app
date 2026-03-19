# Webinar Implementation Roadmap

## Phase 1 - Foundations (completed scaffold)
- Add webinar domain tables and relations.
- Add admin webinar CRUD endpoints.
- Add step-tab create/edit UI.
- Add public registration, room entry, and unsubscribe pages.

## Phase 2 - Registration + Email (Resend)
- Add `WebinarInvitationMail` and queued jobs.
- Trigger confirmation email after registration.
- Add reminder scheduler command and queue worker.
- Add follow-up email jobs based on attendee behavior.

## Phase 3 - Registrant Import
- Add CSV upload parsing and validation.
- Add paste-list parser with duplicate handling.
- Add manual row entry UI in admin.
- Batch queue invitation emails.

## Phase 4 - Real-time Chat
- Add message broadcasting (`ShouldBroadcast`).
- Add admin conversation console (left attendees, right thread).
- Add attendee chat submit endpoint.
- Add host reply endpoint and live push via Echo/Soketi/Pusher.

## Phase 5 - Timed Automation
- Admin CRUD for scheduled messages and offers.
- Runtime timeline engine in webinar room to trigger events.
- Persist offer click analytics.

## Phase 6 - Analytics
- Event ingestion service for joins, watch intervals, chat sends, offer clicks.
- Aggregation queries for dashboard cards/charts.
- Add conversion and watch-time funnels.

## Phase 7 - Hardening
- Rate limits on registration/chat endpoints.
- Signed URLs for sensitive links if needed.
- Idempotency guards for webhook/event processing.
- Browser and mobile QA + performance tuning.

## Suggested Build Order in Code
1. Mail + queue integration with Resend.
2. Registrant import module.
3. Offer/scheduled-message CRUD pages.
4. Real-time chat broadcasting.
5. Analytics aggregation endpoints.
