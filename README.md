# FluentBooking Google Meet Links

A custom WordPress plugin for generating **one unique Google Meet meeting space per FluentBooking appointment** while keeping **Apple/iCloud Calendar as the only source of availability and booked appointments**.

> **Status: scaffold only.** The plugin header is installable, but this initial commit does **not** connect to Google, generate Meet links, modify bookings, or send notifications.

## Intended behavior

1. FluentBooking checks availability against the configured iCloud calendar.
2. An appointment is confirmed through FluentBooking.
3. This plugin creates exactly one Google Meet meeting space for that booking using the [Google Meet REST API `spaces.create`](https://developers.google.com/workspace/meet/api/reference/rest/v2/spaces/create).
4. The meeting URL is stored with the FluentBooking booking and delivered to the host and invitee through supported notifications.
5. Retries are idempotent; cancellation and rescheduling behavior are handled explicitly.

**No Google Calendar event is required.** This plugin will not use Google Calendar for availability or sync iCloud events into Google.

## Planned integration

- **WordPress / FluentBooking:** determine booking lifecycle hooks, the booking metadata API, and the timing of notification rendering against the installed FluentBooking version. See [FluentBooking developer hooks](https://developers.fluentbooking.com/hooks/).
- **Google authorization:** OAuth 2.0 authorization code flow with minimum scope `https://www.googleapis.com/auth/meetings.space.created`; credentials and refresh tokens must be managed securely server-side, never committed to the repository.
- **Meet creation:** `POST https://meet.googleapis.com/v2/spaces` using a valid OAuth access token. Store the returned `meetingUri` and Google `name` alongside the booking ID.
- **Notifications:** confirm an approach that ensures the Meet link exists before customer-facing emails, or send a clearly branded follow-up only after successful creation. Do not assume an asynchronous webhook can alter an already-sent confirmation.
- **Rescheduling and cancellation:** preserve the same Meet URL on reschedule by default; define whether cancellation should deactivate the meeting space, subject to Google API behavior.
- **Reliability:** avoid duplicate spaces using a booking-specific lock and persisted record; log errors without disclosing Google tokens or attendee details.

## Security requirements

- Restrict admin setup actions to authorized WordPress users; verify capabilities and nonces.
- Store Google OAuth client secrets and tokens outside source control, encrypt at rest where feasible, and protect access to admin settings.
- Sanitize input and escape output; avoid leaking tokens or private booking data to logs.
- Use unique OAuth `state` and verify it on callback; request only the required scope.

## Local setup

Place this directory in `wp-content/plugins/fluentbooking-google-meet/` and activate **FluentBooking Google Meet Links** after reviewing its code. At this stage activation does not add functionality.

## Implementation checklist

- [ ] Google OAuth connection & token refresh
- [ ] FluentBooking confirmed-booking hook and metadata handling
- [ ] Per-booking Meet creation and idempotency
- [ ] Booking notifications with the unique link
- [ ] Error handling / manual retry
- [ ] Reschedule and cancellation behavior
- [ ] WordPress and FluentBooking compatibility tests

## License

No license has been selected; all rights reserved until the repository owner chooses a license.
