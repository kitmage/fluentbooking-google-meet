# FluentBooking Google Meet Links

A standalone **WordPress** plugin that creates **one unique Google Meet space per confirmed FluentBooking booking** using the [Google Meet REST API](https://developers.google.com/workspace/meet/api/reference/rest/v2/spaces/create).

**Apple/iCloud Calendar remains the sole availability and appointment calendar.** This plugin does **not** connect to Google Calendar, create Google Calendar events, or change FluentBooking availability rules. It populates the standard FluentBooking booking `location_details` field so ordinary location smartcodes can include the Meet URL.

> Release: **1.0.4** — implementation complete; **site-specific end-to-end verification is still required** with your exact FluentBooking / FluentBooking Pro version, Apple integration, Google Cloud credentials, and email templates. Do not use on live bookings without a staging test.

## Features

- Generates a new Google Meet space (`POST /v2/spaces`) for each eligible **scheduled** booking.
- Uses **only** the `meetings.space.created` OAuth scope; no Calendar API permissions.
- One admin-configured Google account (for single-owner scheduling).
- Native WordPress Settings → **FluentBooking Google Meet** page for OAuth credentials, Google connection, selected FluentBooking event IDs, and retry by booking ID.
- Adds the URL to FluentBooking's standard `location_details` (`google_meet` / `online_platform_link`) **before** the normal scheduling lifecycle's outgoing notifications.
- Stores the meeting URL and Meet resource name on the booking itself; repeat hooks/retries reuse the same URL and do not generate another room.
- Skips pending, cancelled, rejected, unselected or group events, and protects non-empty custom/phone/address/other-conference locations. Overrides the Online Meeting default URL for selected events.
- Sends WordPress site-admin email if a confirmed booking's Meet link cannot be generated; the booking is **not** cancelled.
- Encrypts the OAuth refresh token and saved client secret using AES-256-GCM and WordPress salts; credentials can instead be set in `wp-config.php`.
- No external PHP dependencies and no cron required for normal bookings.

## Requirements

- WordPress 5.8+; PHP 7.4+ with **OpenSSL** and outbound HTTPS enabled.
- Current FluentBooking (Core). If you check iCloud availability with FluentBooking, configure FluentBooking Pro's Apple Calendar integration **separately**; this plugin doesn't manage it.
- Google Cloud project with **Google Meet API enabled** and an **OAuth 2.0 client for Web application**.
- A Google account permitted to create Google Meet spaces.
- A site using HTTPS for Google OAuth callbacks (except supported localhost development cases).

## Setup

1. Install as the folder `fluentbooking-google-meet` inside `wp-content/plugins/` (or upload the release ZIP through Plugins → Add New → Upload Plugin); activate.
2. In [Google Cloud Console](https://console.cloud.google.com/apis/library), select or create a project and **enable Google Meet API**.
3. Configure the [OAuth consent screen](https://console.cloud.google.com/auth/overview), request the `https://www.googleapis.com/auth/meetings.space.created` scope, and add the account you will use as a test user if the app is in Testing mode. **Important:** an external OAuth app in *Testing* mode normally issues refresh tokens that expire after **7 days**. Publish/verify the app as appropriate to your use and Google's requirements.
4. Create an **OAuth client ID → Web application** and add the precise **Authorized redirect URI** shown under WordPress → Settings → FluentBooking Google Meet. It has the form:

   `https://YOUR-SITE.example/wp-admin/admin-post.php?action=fbgm_oauth_callback`

5. Save the OAuth client ID and client secret in that settings page, or use the `wp-config.php` constants below. Save **before** connecting.
6. Click **Connect Google**, approve the `meetings.space.created` permission, and check the connection status.
7. In FluentBooking, determine the numeric **event type IDs** that need Meet links. Set those event types' location to **Online Meeting** or to an **empty Custom location**. For selected event IDs, the plugin replaces an existing Online Meeting default URL with the generated Meet URL. It refuses to replace nonempty custom locations, physical addresses, or other conferencing links. Do **not** enable native Google Meet as a second FluentBooking integration.
8. Enter those event IDs as comma-separated numbers, tick **Enable**, and save.
9. In FluentBooking notifications (booking confirmation **and** reminders), include `{{booking.location_details_text}}` where the Google Meet link should appear. `{{booking.location_details_html}}` is also available in HTML-compatible contexts.
10. Make a **staging/test booking**. Confirm it shows (a) Apple-based availability; (b) a unique Meet URL in FluentBooking; (c) a single booked appointment in iCloud; (d) the Meet link in host/guest messages and, ideally, in the synced Apple event location. Check a second booking produces a **different** URL.

### Credentials in wp-config.php (recommended for production)

Add before WordPress loads, **outside this public GitHub repository**:

```php
define('FBGM_GOOGLE_CLIENT_ID', 'YOUR_CLIENT_ID.apps.googleusercontent.com');
define('FBGM_GOOGLE_CLIENT_SECRET', 'YOUR_GOOGLE_CLIENT_SECRET');
```

The admin form will make these fields read-only. **Never commit** `wp-config.php`, Google OAuth JSON downloads, tokens, `.env`, or API credentials.

## Booking lifecycle

- FluentBooking fires `fluent_booking/pre_after_booking_scheduled` early. Our handler creates the space synchronously, updates booking metadata, and stores the URL in the booking's normal `location_details` field.
- A second `fluent_booking/after_booking_scheduled` handler is a safety net for alternate paths such as payments/approval. It is idempotent.
- Repeated execution checks saved `fbgm_meet_url`; once present, it does not request another space.
- Reschedules retain a URL when the same booking ID survives. A *new* booking ID (if FluentBooking uses one) receives its own unique URL. A handler on `after_booking_rescheduled` attempts to repair the new/updated record if passed a booking object.
- Cancelling/rejecting a booking **does not delete** the Google Meet space: the Google Meet Spaces API does not provide a general delete-space operation. No Google Calendar event exists to cancel. Follow-up booking notifications are managed by FluentBooking.
- **If the API fails**, the appointment remains booked and the site admin receives one notification per affected booking. Once Google is working again, use Settings → FluentBooking Google Meet → **Generate / Repair Link** for that booking ID. It does not automatically resend old confirmation emails; resend those from FluentBooking.
- A crash in the narrow window after Google creates a space but *before* WordPress stores the response can lead to an unused orphaned space on retry. The API does not expose an idempotency key for `spaces.create`. Normal repeated booking hooks are deduplicated.

## Security notes

- OAuth `state` is random, single-use, bound to the logged-in administrator and expires in 10 minutes.
- OAuth redirects/callbacks and settings changes require `manage_options`; modifying settings, retrying, connecting and disconnecting require WordPress nonces.
- Tokens and client secrets stored in the WordPress database are encrypted using an AES-GCM key derived from WordPress authentication salts. **Rotate WP salts only if prepared to reconnect Google**; protect database backups and site administrators.
- Google access tokens are short-lived and cached in WordPress transients.
- Do **not** make the repository public with real credentials or committed database dumps. The source can be public safely without secrets.
- Only selected **one-to-one** event IDs are processed. Group event types are deliberately skipped because multiple attendees on the same session must share a single Meet URL. Other location types are never overwritten. The plugin is designed for a single Meet owner, not multiple distinct host Google accounts.

## Diagnostics

| Symptom | Check |
| --- | --- |
| Google connection fails | OAuth client must be **Web application**; redirect URI must match exactly; consent test user; Meet API enabled; Google account access |
| Connection stops after a week | OAuth external Testing status may expire refresh tokens after seven days |
| Booking has no Meet URL | Booking is `scheduled`; plugin enabled; event ID selected; blank existing location; token connected; PHP OpenSSL and HTTP requests work |
| Location not in email | Add `{{booking.location_details_text}}` to the event's notification templates, then test sending order |
| Apple calendar shows no Meet URL | Confirm its sync occurs after FluentBooking's early scheduling hook and that Apple calendar integration propagates location updates; actual behavior depends on your installed FluentBooking Pro version |
| Manual repair succeeds but guest missed link | Resend the confirmation from FluentBooking; manual repair doesn't resend notices |
| Repair fails | Inspect `fcal_booking_meta` for `fbgm_last_error` for the booking, and check Google/WordPress logs without publishing tokens |

## Developer information

- `fluentbooking-google-meet.php` — plugin entry point.
- `includes/class-fbgm-storage.php` — settings, encryption, token persistence.
- `includes/class-fbgm-google.php` — Google OAuth and Meet API requests.
- `includes/class-fbgm-bookings.php` — booking lifecycle, idempotency, safe location handling.
- `includes/class-fbgm-admin.php` — admin UI and callbacks.
- `tests/run.php` — dependency-free PHP tests with fake WordPress/FluentBooking objects.

Run locally: `php tests/run.php`; syntax check: `find . -name '*.php' -print0 | xargs -0 -n1 php -l`.

### References

- [Google Meet spaces.create](https://developers.google.com/workspace/meet/api/reference/rest/v2/spaces/create)
- [Google OAuth 2.0 web application guide](https://developers.google.com/identity/protocols/oauth2/web-server)
- [FluentBooking booking lifecycle hooks](https://developers.fluentbooking.com/hooks/actions/bookings-and-scheduling)
- [FluentBooking Smartcodes](https://developers.fluentbooking.com/platform/smartcodes)
- [FluentBooking Booking model and location handling](https://github.com/WPManageNinja/fluent-booking/blob/main/app/Models/Booking.php)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
## 1.0.1 bug fix

- Fixed the **Connect Google** button being permanently disabled even when OAuth credentials are configured. WordPress emits a `disabled` HTML attribute when supplied a false value, so the plugin now omits the attribute entirely when credentials are present.
- Saved Client ID, Client Secret, selected event IDs, and existing Google authorization state remain intact when replacing the plugin.

## 1.0.2 bug fix

- Fixed selected events retaining FluentBooking's configured Online Meeting URL instead of the unique generated Google Meet link. This URL is intentionally replaced **only** for selected event IDs.
- Existing confirmed bookings can be updated using **Generate / Repair Link** with their booking ID in WordPress → Settings → FluentBooking Google Meet. Resend the booking confirmation afterward; the plugin does not automatically resend it.
- Google authorization and Apple/iCloud availability behavior are unchanged.

## 1.0.3 diagnostics

- **Generate / Repair Link** now shows a specific error code and safe explanation if it fails. The diagnostic includes the attempted booking's status, actual FluentBooking event ID, configured event IDs, and existing location type; no personal booking details, OAuth tokens, or credentials are displayed.
- An administrator-specific diagnostic is kept temporarily for 10 minutes after the retry. Existing `fbgm_last_error` metadata is still available for API failures, but not all validation failures write metadata.
- This change does not affect calendar availability, Google OAuth tokens, or booking URLs.

## 1.0.4 Google API error diagnostics

- When Google rejects a Meet-space creation request, the repair result now includes Google's **machine-readable reason code** (if supplied in the API response), such as `SERVICE_DISABLED` or `ACCESS_TOKEN_SCOPE_INSUFFICIENT`, plus a targeted explanation.
- The raw Google error payload and access token are **not** displayed or logged. Some 403 responses supply only `PERMISSION_DENIED` without a more specific reason; in that case, double-check Google Meet API enablement in the OAuth client project and the connected account's privileges.
- To inspect the result, install this version, go to **Settings → FluentBooking Google Meet**, and click **Generate / Repair Link** for the existing Booking ID. No new booking is needed; existing credentials and iCloud settings are unchanged.
