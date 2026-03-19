# On-Demand Webinar Platform

Technical Specification (Laravel + Inertia + Vue)

## 1. Overview

We are building an on-demand webinar platform similar to Demio.

The system allows creators to host pre-recorded webinars that behave like live webinars.

When a user joins the webinar:

- The video starts playing immediately for that user.
- The user sees chat messages and engagement elements.
- The user sees fake live viewer counts.
- Timed offers and messages appear during the webinar.

Even though the webinar is pre-recorded, the attendee should feel like they are attending a live webinar.

The video is streamed from an external provider (Vimeo, YouTube, etc.). The platform only stores and embeds the video URL and does not host video files.

System stack:

- Backend: Laravel
- Frontend: Inertia.js + Vue
- Email service: Resend
- Video source: Vimeo / YouTube / external video URL

## 2. Core Features

### 2.1 Admin Dashboard

The platform has a creator/admin dashboard where webinar hosts manage their webinars.

Main dashboard capabilities:

- Create webinar
- Edit webinar
- View webinar analytics
- Manage registrants
- View chat conversations
- Manage offers
- Track webinar engagement

Dashboard sections:

1. Webinars
2. Registrants
3. Chat messages
4. Offers
5. Analytics

## 3. Webinar Create/Edit UX (Step Tabs)

Webinar create and edit pages should use a step-based tabs flow to keep configuration clear and scalable.

Recommended tab order:

1. Basics
2. Video
3. Registration
4. Chat and Automation
5. Offers
6. Email
7. Publish and Tracking

### 3.1 Step Details

1. Basics
- Webinar title
- Host/creator name
- Description
- Thumbnail upload
- UUID (auto-generated)

2. Video
- Video source (`youtube`, `vimeo`, `direct`)
- Video URL
- Video duration (manual or auto-detected if available)

3. Registration
- Registration page settings
- Required fields (`name`, `email`)
- Access token behavior

4. Chat and Automation
- Timed automated chat messages
- Default host message templates
- Live chat enable/disable

5. Offers
- Timed offers list
- Offer display mode (chat/popup/pinned)
- CTA URL validation

6. Email
- Registration confirmation
- Reminder schedule
- Follow-up settings
- Unsubscribe behavior

7. Publish and Tracking
- Min/max fake viewers
- Analytics/tracking toggles
- Final preview and publish

Implementation notes:

- Each tab should autosave draft data.
- Validation should run per-step and on final publish.
- Editing an existing webinar should preload all tab states.

## 4. Webinar Creation

Creators can create a new webinar.

Required fields:

- Webinar title
- Creator/host name
- Description
- Webinar video source
- Video URL
- Webinar thumbnail
- Webinar UUID (for public registration/join links)

Example public webinar URL:

```text
https://app.com/webinar/learn-marketing
```

## 5. Webinar Video Sources

The webinar video is embedded from external providers.

Supported sources:

- Vimeo
- YouTube
- Direct video URL (MP4/streaming URL)

Important requirement:

The platform does not host videos; it only stores and embeds the video URL.

Example fields:

```text
video_source
video_url
video_duration
```

## 6. Webinar Registration

Each webinar has a registration page.

User inputs:

- Name
- Email

After registering:

- User is added to webinar registrants.
- A confirmation email is sent.

Email delivery provider:

- Resend.com

The email includes webinar join link, for example:

```text
https://app.com/w/abc123
```

## 7. Bulk Registrant Import

Host can import users through:

1. CSV upload
2. Paste email list
3. Manual entry

Example CSV:

```csv
name,email
John Doe,john@email.com
Jane Smith,jane@email.com
```

Imported users automatically receive webinar invitation emails.

## 8. Webinar Playback Logic

This is the core feature.

When user opens webinar link:

1. Webinar page loads
2. Video starts immediately
3. Session timer starts
4. Chat messages appear according to timeline
5. Offers appear according to timeline

Important:

Each user has an independent timeline.

Example:

- User A joins 2:00 PM -> starts at 0:00
- User B joins 3:00 PM -> starts at 0:00

They are not synchronized to each other.

## 9. Simulated Live Attendee Count

System shows fake viewer count.

Admin-configurable:

```text
min_viewers
max_viewers
```

Example:

```text
min: 123
max: 240
```

Behavior:

- Randomly increases
- Randomly decreases
- Updates every few seconds

## 10. Chat System

Attendees can send messages during webinar.

UI style:

- WhatsApp-style messaging interface

### 10.1 Attendee Chat

Attendee can:

- Send messages
- See host replies
- See automated messages

### 10.2 Admin Chat Interface

Host can see:

- All webinar attendees
- Messages sent by each attendee

Layout:

- Left: attendee list
- Right: conversation thread

Host actions:

- Reply to individual users
- Send links
- Send offer links

Messages should appear instantly on attendee side.

Use:

- WebSockets, or
- Laravel broadcasting

## 11. Timed Offers

Host can configure offers to appear at specific video timestamps.

Example:

```text
Title: Buy My Course
Time: 18 minutes
Button URL: https://checkout.com
```

At trigger time, offer appears as:

- Chat message
- Popup
- Pinned message

## 12. Automated Chat Messages

Host can schedule timeline-based automated messages.

Examples:

```text
Time: 5 minutes
Message: Where are you joining from?
```

```text
Time: 15 minutes
Message: Let me know in the chat if this makes sense.
```

## 13. Webinar Analytics

Track at minimum:

- Registrations
- Attendees
- Watch time
- Chat messages
- Offer clicks
- Conversion rate

Example metrics:

```text
Total Registrations
Total Attendees
Average Watch Time
Offer Click Rate
```

## 14. Email System

All emails are sent via Resend API.

Required email flows:

1. Registration confirmation
2. Webinar reminder
3. Webinar follow-up

All emails must include unsubscribe link:

```text
/unsubscribe/{token}
```

When clicked, user is removed from webinar email list.

## 15. User Activity Tracking

Track:

- Webinar join time
- Watch duration
- Chat participation
- Offer clicks

Example stored fields:

```text
user_id
webinar_id
joined_at
watch_duration
offer_clicked
```

## 16. Performance Requirements

Platform should provide smooth webinar experience.

Requirements:

- Fast video startup
- No app-side playback lag
- Minimal UI latency for chat and offers

Note: Video delivery performance depends on external video providers.

## 17. Recommended Tech Stack

Backend:

```text
Laravel 11
MySQL
Redis
Queues
```

Frontend:

```text
Inertia.js
Vue 3
TailwindCSS
```

Realtime:

```text
Laravel Echo
Pusher / Soketi
```

Email:

```text
Resend API
```

Video embed APIs:

```text
Vimeo Player API
YouTube iframe API
```

## 18. Database Tables

Main tables:

```text
users
webinars
webinar_registrants
webinar_views
chat_messages
webinar_offers
scheduled_messages
email_unsubscribes
analytics_events
```

## 19. Public Pages

Required routes/pages:

```text
/register/{webinar}
/webinar/{token}
/unsubscribe/{token}
```

## 20. Security

- Unique tokens for webinar access
- Email verification for registrants
- Rate limiting
- CSRF protection

## 21. Future Features (Optional)

- Stripe integration for paid webinars
- Webinar replay pages
- Automated follow-up email sequences
- AI chat moderation

## 22. Architecture Note (Timeline Sync)

Important implementation principle:

- Timelines must be per-attendee session, not global webinar time.
- Store a per-view `started_at` reference and derive current timeline offset from user session time.
- Trigger scheduled messages/offers against attendee offset to avoid cross-user synchronization bugs.
