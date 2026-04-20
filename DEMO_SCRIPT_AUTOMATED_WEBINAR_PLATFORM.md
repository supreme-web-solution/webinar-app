# Automated Webinar Platform — Long-Form Demo Script (≈300 minutes)

This script is written to **walk page-by-page through the app**, showing an “automated webinar funnel” end-to-end: create → publish → register → watch → chat/AI → offers → analytics → admin operations.  
It intentionally **does not focus on “live webinar” positioning**.

> Recording tip: Keep the app in one browser profile where you’re logged in as an admin/host, and a second incognito window as a public attendee.

---

## 0) Pre-flight setup (5–10 min)

### Goal
Make sure everything you’ll demonstrate works smoothly during recording.

### Checklist
- **Host account**: logged in and verified.
- **Admin access** (optional): set `ADMIN_EMAILS` in `.env` to include your email so you can show the Users page.
- **Email sending** (optional): configure SMTP so you can show email testing and background queue behavior.
- **AI features** (optional): set OpenAI + HeyGen keys in your environment if you will demo AI script/video generation.
- **Lead import** (optional): set Apollo/D7 keys if you will demo attendee import.

### What to say (verbatim)
“This platform is built for **automated webinars**: evergreen funnels where the webinar experience is consistent, trackable, and conversion-focused. I’m going to go page-by-page and show how you can build the webinar, automate attendance, automate follow-ups, and measure everything.”

---

## 1) Login (10–15 min)
**Page:** `/login`

### Show
- Clean login UI
- Password reset link (if enabled)

### What to do
1. Enter credentials and sign in.
2. Mention security basics: verified email, session, access control.

### What to say
“I’m going to log in as a host/admin. Everything you’ll see is behind authentication so each account’s webinars and data are isolated.”

---

## 2) Dashboard (10–15 min)
**Page:** `/dashboard`

### Show
- The dashboard landing experience for a host user.
- Explain that the platform is structured around webinars as the primary “asset”.

### What to say
“The platform is host-first. The main workflow is: create a webinar, configure the automation (registration, chat/AI, offers, email notifications), publish, and then continuously optimize based on analytics.”

---

## 3) Webinars index (Admin) (20–30 min)
**Page:** `/admin/webinars`

### Show
- Webinar list
- Filtering and bulk selection features (if present in your build)
- Status indicators: draft/published/ended

### What to do
1. Point to a few example webinars.
2. Show filtering/search if available.
3. Show bulk delete flow (but don’t actually delete a valuable one).

### What to say
“This is the control center for automated webinars. You can manage many funnels at once: draft webinars, active evergreen webinars, archived ones, and you can do bulk operations when you’re cleaning up or iterating.”

---

## 4) Create webinar (60–90 min)
**Page:** `/admin/webinars/create`

### Narrative
You’re building an automated funnel webinar from scratch.

### Recommended demo webinar
- **Title:** “How to Generate Leads on Autopilot”
- **Host name:** use your real name (or brand persona)
- **Description:** short promise + what viewers will learn

### 4.1 Basics tab (10–15 min)
**Show**
- Title, host, description, schedule mode
- “Auto” mode positioning for evergreen automation

**What to say**
“For automated webinars we typically choose an evergreen/auto approach. The experience is always available and the automation is consistent.”

### 4.2 Video tab (10–20 min)
**Show**
- Video source selection
- Video URL format guidance
- Duration inputs

**What to do**
1. Paste a YouTube/Vimeo link (or your hosted video URL).
2. Set duration if needed.

**What to say**
“Video is the centerpiece. The platform supports different sources, but the key is: once the video is set, we can drive a consistent automated experience around it.”

### 4.3 Registration tab (15–20 min)
**Show**
- Public registration link and room link patterns (UUID, etc.)
- Join button psychology/CTA options

**What to say**
“Registration is built like a funnel entry point. We generate consistent public links, and we can tune CTA language and urgency presentation.”

### 4.4 Attendees tab (20–30 min)
**Show**
- CSV import flow (if configured)
- Lead provider import flow (Apollo/D7)
- Subscribed vs unsubscribed management panels

**What to do**
1. Explain two ways to get attendees:
   - **Organic**: public registration
   - **Import**: bring your list in
2. If demoing provider import:
   - Do a small preview fetch (like 10–25)
   - Queue a fetch job (ex: 100) and explain background processing

**What to say**
“This is where automation gets serious. You can scale attendance by importing targeted contacts. The platform queues and processes in the background, registers attendees, and schedules emails without freezing the UI.”

### 4.5 Chat & automation (10–15 min)
**Show**
- Any toggles related to viewer experience (e.g., simulated viewers)

**What to say**
“Automated webinars still need engagement. We can simulate social proof, guide the attendee through prompts, and use AI to answer questions in real time.”

### 4.6 Offers (20–30 min)
**Show**
- Offer creation
- Trigger times (seconds)
- Display modes (chat/popup/pinned if present)
- Redirect after video ends
- Exit intent popup

**What to do**
1. Add 2–3 offers:
   - Offer 1 at 10 minutes (600s): “Book a Strategy Call”
   - Offer 2 at 25 minutes: “Limited-time discount”
   - Offer 3 near end: “Last chance”
2. Configure exit-intent popup:
   - Heading, message, CTA text, CTA URL
3. Configure redirect after end (optional)

**What to say**
“This is the conversion engine. Offers are timed and delivered automatically at the exact moment you choose—via chat messages, pinned offers, or popups. The exit-intent popup catches leavers, and redirect can push directly into checkout or booking.”

### 4.7 AI assistant (40–60 min)
**Show**
- Enable AI assistant
- Knowledge base capacity
- Add sources (URL, transcript, file)
- Source list + deletion

**What to do**
1. Enable AI assistant.
2. Add 2–3 sources:
   - A website URL (your sales page)
   - A transcript snippet (FAQ)
   - A PDF or file (product doc)
3. Explain how fewer, higher-quality sources improves answers.

**What to say**
“This turns an automated webinar into an interactive experience. Attendees can ask questions and the AI answers based on the private knowledge base. This reduces drop-off and increases conversions because objections get handled instantly.”

### 4.8 Reminder & notification (10–15 min)
**Show**
- Confirmation email toggle
- Reminder toggle
- Follow-up toggle

**What to say**
“Automation doesn’t end at registration. Confirmation builds attendance, follow-up builds conversions. In an evergreen webinar you can tune what gets sent and when.”

### 4.9 Publish & tracking (10–15 min)
**Show**
- Viewer count settings (min/max)
- Publish toggle

**What to say**
“You can publish immediately and control social proof with view ranges. This creates a consistent ‘active webinar’ vibe for evergreen funnels.”

---

## 5) Webinar edit (Admin) (20–30 min)
**Page:** `/admin/webinars/{id}/edit`

### Show
- Everything you configured persists
- Attendee panels show preview lists and totals
- AI sources load and can be managed

### What to say
“After publishing, this becomes the optimization workspace. You tweak offers, adjust AI sources, update automation settings, and track performance.”

---

## 6) Public registration page (20–30 min)
**Page:** `/register/{uuid}`

### Show
- Registration UX
- What data is collected
- After registration: confirmation / access

### What to do
1. Open incognito.
2. Register as a test attendee.
3. Mention how this integrates with queued emails.

### What to say
“This is the funnel entry point. It’s simple and fast—less friction means more signups. Once they register, the platform tracks them and can trigger automation immediately.”

---

## 7) Webinar room (public attendee experience) (60–90 min)
**Pages:**
- `/webinar/live/{uuid}` or `/webinar/{token}` (depending on flow)

### Narrative
You are now the attendee. You will experience the webinar as a conversion-focused automated session.

### 7.1 Landing in the room (10–15 min)
**Show**
- Video player presence
- Any UI elements: chat, CTA, offers

**What to say**
“This is where the automated webinar feels real. Attendees watch the content, see timed engagement, and get offers at the right moments.”

### 7.2 Chat interaction (15–25 min)
**Show**
- Chat panel and message flow
- Ask a question that the AI assistant can answer (if enabled)

**What to do**
1. Ask: “What’s included in the offer?”
2. Ask: “Do you have a refund policy?”
3. Ask: “What’s the best next step for me?”

**What to say**
“The AI assistant is trained only on the sources we added—so it stays on-brand, accurate, and doesn’t hallucinate random product details.”

### 7.3 Offers triggering (20–30 min)
**Show**
- Timed offer appears (chat/popup/pinned)
- Click tracking for offers

**What to do**
1. Wait until an offer triggers, or scrub video time if you can for demo.
2. Click the CTA and show that it opens the configured URL.

**What to say**
“This is conversion timing. Instead of dumping links early, we deliver offers based on viewer progress, which matches intent and increases click-through.”

### 7.4 Exit intent popup (10–15 min)
**Show**
- Attempt to leave / close tab behavior
- Exit popup appears with configured CTA

**What to say**
“Exit-intent is the safety net. People leave when they get distracted—this catches them and brings them back into the funnel.”

### 7.5 End-of-webinar redirect (optional) (5–10 min)
**Show**
- Redirect after end (if enabled)

**What to say**
“When the session ends, you can automatically send them to checkout, booking, or a replay page—whatever the next step is.”

---

## 8) Admin chat monitoring (20–30 min)
**Page:** `/admin/chats` and `/admin/webinars/{webinar}/chat`

### Show
- List of chats
- Drill down to a webinar’s chat
- Reply as host (even though it’s automated, you can still intervene)

### What to say
“Even though this is an automated webinar platform, you still get visibility. You can review conversations, moderate, and optionally respond.”

---

## 9) Analytics & tracking (30–45 min)
**Where:** Webinar edit stats area (and any analytics pages you have)

### Show
- Views count
- Registrants
- Offer clicks
- Engagement segments (if available)

### What to say
“Automation without measurement is guessing. Here you can see what’s working: where drop-off happens, which offers get clicks, and which segments convert.”

---

## 10) Email / SMTP settings (20–30 min)
**Page:** `/settings/smtp`

### Show
- SMTP settings screen
- Test email feature
- Explain queued email behavior

### What to say
“Email is still the backbone of automated funnels. The platform supports SMTP configuration and can queue emails for scale so you don’t hit provider limits.”

---

## 11) Profile + security (15–25 min)
**Pages:** `/settings/profile`, `/settings/security`, `/settings/appearance`

### Show
- Update profile
- Two-factor auth flow (if enabled)
- Appearance toggle (light/dark)

### What to say
“This is multi-tenant and security aware. Two-factor is supported, and we’ve got consistent UI/UX settings like theme preferences.”

---

## 12) User Management (Admin-only) (30–45 min)
**Page:** `/admin/users`

### Required
Your email must be in `ADMIN_EMAILS`.

### Show
- Filtering users
- Edit user in modal
- Delete user (careful; use a test account)

### What to say
“For teams and managed hosting, this is your admin view: see all users, update accounts, reset passwords, and manage access.”

---

## 13) Lead import providers (Apollo/D7) (30–45 min)
**Where:** Webinar edit → Attendees tab

### Show
- Provider selection behavior (via `LEAD_PROVIDER`)
- Preview fetch vs full fetch job
- Explain “one email per company” behavior for cleaner lists

### What to say
“Imports are optimized for quality. For example, when targeting companies, you often want **one contact per company**—the platform avoids pulling multiple leads from the same organization when configured.”

---

## 14) AI Studio / AI video generation (optional, long segment) (60–120 min)
**Page:** Typically inside the webinars index modal flow (AI video creation)

### Show
- Generate script from brief
- Edit script
- Select avatar/voice
- Advanced slide settings
- Generate video and monitor progress

### What to say
“This is the content creation acceleration layer. You can go from idea → script → video draft quickly, then publish as an automated webinar funnel.”

---

## 15) Wrap-up summary (10–15 min)

### What to say (verbatim)
“To recap: this is an automated webinar platform that covers the entire funnel—build the webinar, publish it, register attendees, run timed offers, answer questions with AI, and track conversions. It’s designed to scale without losing control.”

### Final CTA for your video
“If you want this installed and customized for your brand—landing pages, email sequences, AI knowledge base, and offer optimization—reach out and we’ll set it up.”

---

## Appendix A — “If something goes wrong” talking points

- **Queues running slow**
  - “All the heavy lifting is queued to keep the UI fast. If the worker is paused, jobs will resume when the worker is back online.”
- **AI takes time**
  - “Long scripts can take longer; generation is asynchronous and the UI resumes after refresh.”
- **Lead provider limits**
  - “Providers can rate limit or require billing. We handle errors gracefully and support fallbacks.”

