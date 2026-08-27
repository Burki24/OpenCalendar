# OpenCalendar live provider end-to-end tests

`tests/live-provider-e2e.php` is an **opt-in destructive integration test**. It creates temporary test events in one writable calendar, modifies them, verifies provider readback, and deletes them again. It is intentionally not part of `tests/run.php` and therefore never runs automatically in normal CI.

Use a dedicated test calendar whenever possible. The harness uses a unique `OpenCalendar E2E ...` tag and performs cleanup in a `finally` block, but a network or provider outage can still leave temporary test events behind.

## Covered scenarios

The live suite verifies the common provider contract with real provider APIs:

- connection and writable-calendar discovery
- timed event create, direct readback, update, status/transparency verification, and delete
- UTF-8 text round-trip
- all-day event creation and provider default status/transparency
- recurring series creation
- update and deletion of one occurrence
- complete-series lookup, update, and deletion
- verified "this and following" lookup and split/update
- cleanup of tagged temporary test events

Google Calendar, Microsoft 365, Apple iCloud, and generic CalDAV are supported. Apple iCloud uses the same CalDAV provider path as the module.

## Safety confirmation

Every live run requires:

```text
OPENCALENDAR_LIVE_CONFIRM_WRITE=YES
```

Without this exact value, the harness refuses to write to a real calendar.

## Google Calendar

Supply a short-lived OAuth access token with Calendar write permission:

```bash
OPENCALENDAR_LIVE_PROVIDER=google \
OPENCALENDAR_LIVE_ACCESS_TOKEN='...' \
OPENCALENDAR_LIVE_CONFIRM_WRITE=YES \
php tests/live-provider-e2e.php
```

## Microsoft 365

Supply a delegated Microsoft Graph access token with calendar write permission:

```bash
OPENCALENDAR_LIVE_PROVIDER=microsoft \
OPENCALENDAR_LIVE_ACCESS_TOKEN='...' \
OPENCALENDAR_LIVE_CONFIRM_WRITE=YES \
php tests/live-provider-e2e.php
```

## Generic CalDAV

```bash
OPENCALENDAR_LIVE_PROVIDER=caldav \
OPENCALENDAR_LIVE_SERVER_URL='https://calendar.example/' \
OPENCALENDAR_LIVE_USERNAME='user@example.com' \
OPENCALENDAR_LIVE_PASSWORD='...' \
OPENCALENDAR_LIVE_CONFIRM_WRITE=YES \
php tests/live-provider-e2e.php
```

## Apple iCloud

For Apple iCloud, `https://caldav.icloud.com` is used automatically when no server URL is supplied. Use the Apple Account email address and an app-specific password:

```bash
OPENCALENDAR_LIVE_PROVIDER=apple \
OPENCALENDAR_LIVE_USERNAME='user@icloud.com' \
OPENCALENDAR_LIVE_PASSWORD='app-specific-password' \
OPENCALENDAR_LIVE_CONFIRM_WRITE=YES \
php tests/live-provider-e2e.php
```

## Optional settings

`OPENCALENDAR_LIVE_CALENDAR` selects a calendar by ID, provider ID, reference, URL, or name substring. If omitted, the first calendar with complete write and recurrence capabilities is used.

`OPENCALENDAR_LIVE_TIMEZONE` defaults to `Europe/Berlin`.

`OPENCALENDAR_LIVE_TIMEOUT` defaults to 30 seconds and accepts values from 5 to 120.

`OPENCALENDAR_LIVE_VERIFY_TLS` defaults to `true` and applies to CalDAV only. Disabling TLS verification should only be used in an isolated test environment.

## Normal CI

The ordinary test suite runs only `tests/live-provider-e2e-contract.php`. That test verifies that the live harness remains opt-in, contains the expected provider/scenario coverage, requires explicit write confirmation, and documents safe usage. It performs no network access and creates no calendar data.
