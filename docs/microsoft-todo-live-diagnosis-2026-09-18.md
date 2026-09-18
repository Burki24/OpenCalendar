# Microsoft To Do recurring-task live diagnosis — 2026-09-18

## Scope and environment

- User-authorized live diagnosis only; no product fix or release deployment.
- Installed OpenCalendar 2.1, build 1040, branch `dev`; Symcon 9.1.
- Calendar instance 15490, calendar view 23043, native To Do list `Aufgaben`.
- Two new daily recurring tasks, `OC TRACE 20260918 A` and `OC TRACE 20260918 B`.
- Existing tasks and the separate `dev_9.1` worktree were not modified.
- Browser workflow through the real calendar UI and Microsoft Graph transport.
- Times below are UTC. ID labels below are prefixes of SHA-256 hashes, not provider IDs.

## Evidence: first edit of a fresh recurring task

| Time | Boundary | Observation |
| --- | --- | --- |
| 07:14:19 | POST, HTTP 201 | A created for 22 September, daily, no end. Response ID hash `2079b4f0`; due `2026-09-22T00:00:00`, `Europe/Berlin`. |
| 07:14:56 | PATCH, HTTP 200 | Requested same ID `2079b4f0`, due `2026-09-24T00:00:00.0000000`, `Europe/Berlin`. Request keys were `title`, `body`, `dueDateTime`; **no status or recurrence update**. |
| 07:14:56 | PATCH response | Same ID `2079b4f0`, still due `2026-09-22T00:00:00.0000000`, now `UTC`; still `notStarted`, daily recurrence. |
| 07:15:05 | GET, HTTP 200 | Both original ID `2079b4f0` at 22 September and **new ID `ab982670`** at `2026-09-23T22:00:00`, `UTC` (24 September in Berlin). New task's creation timestamp was `07:14:55.9446761Z`, during the PATCH. Both have daily recurrence and `notStarted`. |
| UI | After PATCH | Reports `Termin geändert.` but displays A only on 22 September. |
| UI | After synchronization | Displays A on both 22 and 24 September. |

Only one POST created A. Its edit sent one PATCH. The extra item is present in the actual Microsoft GET response, not merely in the calendar renderer.

## Evidence: completion and reopening change which object is edited

| Time | Boundary | Observation |
| --- | --- | --- |
| 07:15:39 | POST, HTTP 201 | B created for 25 September, daily recurrence, ID hash `96cd9ed8`. |
| 07:15:51 | PATCH, HTTP 200 | Sent **only** `status: completed` to `96cd9ed8`. |
| 07:15:51 | PATCH response | Returns original ID `96cd9ed8`, **notStarted**, due 26 September in `W. Europe Standard Time`. The original ID now represents the next open task. |
| 07:15:52 | GET, HTTP 200 | Original ID `96cd9ed8` is open on 26 September. **New ID `7f449422`** is completed on 25 September (due `2026-09-24T22:00:00`, UTC). Both carry recurrence data. |
| 07:16:00 | PATCH, HTTP 200 | Reopens completed copy `7f449422` with `status: notStarted`. Same ID returned. |
| 07:16:13 | PATCH, HTTP 200 | Edits **copy `7f449422`**, not original ID. Requested due `2026-09-27T22:00:00`, UTC. Returned due `2026-09-27T00:00:00`, UTC, same copy ID. |
| 07:16:14 | GET, HTTP 200 | Copy `7f449422` is open on 27 September; original ID `96cd9ed8` remains open on 26 September. No third B task appeared. |

Thus the tester's completion/reopening workaround is not evidence that editing the original recurring task succeeds. It edits a separately created completed-history copy. Recurrence metadata alone does not distinguish these observed roles.

## Confirmed conclusions

1. The defect also reproduces on build 1040, after the calendar-occurrence cache fix.
2. The first bad visible boundary is the native To Do PATCH response: success status does not mean the requested due date is returned for the addressed ID.
3. The subsequent GET proves that Microsoft retains the original item and exposes an additional item with the requested date.
4. OpenCalendar accepts that PATCH response as a successful edit and immediately caches it. It does not reconcile the full task list or verify that the requested change affected the intended task.
5. Completion also returns a different logical occurrence under the original ID. A second ID represents the completed item.
6. Previous calendar-occurrence fixes and canned successful PATCH fixtures do not exercise this native-task lifecycle.

## Still unverified — do not present as a fix

- Why Microsoft duplicates this freshly created recurring task for this specific PATCH, and which supported update sequence preserves the intended series behavior.
- Whether a narrower due-only PATCH, different creation fields/timezone representation, or updating recurrence together with the due date avoids this behavior. No such changes were trialled in this diagnostic.
- Whether the same result occurs for tasks created in Microsoft's own UI, other recurrence patterns, or other Microsoft accounts.
- The UTC/local date round trip is a separate concern: B's outgoing 27 September at 22:00 UTC and the returned 27 September at 00:00 UTC are not equivalent instants. It must be covered in the fix rather than inferred correct from the day shown in this test.

Do not repair by blindly deleting similarly named tasks, completing/reopening user tasks automatically, or hiding an item only in the renderer. None of these is justified by this evidence.

## Instrumentation, tests and rollback

A temporary trace was inserted immediately after the native To Do HTTP request in `libs/MicrosoftTodoProvider.php`. It did not change requests, response parsing or task logic. Output was restricted to the two test-title prefixes, selected date/status/recurrence fields, HTTP method/status, request field names and SHA-256 task identifiers. It excluded headers, OAuth credentials, complete URLs, descriptions and other tasks. A deadline and output size limit bounded collection.

- `php -l state/oc-todo-trace.php`: passed before deployment.
- `php -d zend.assertions=1 -d assert.exception=1 state/test-oc-todo-trace.php`: passed filtering, redaction, identity, expiry, syntax and exact restoration checks in isolated temporary files.
- `php tests/microsoft-todo-provider.php`: passed. This alone does not validate the live behavior.
- Original and restored provider SHA-256: `9dc15c25b33cc0c4145a16e1496d924dc05442ee4b20c6edd1845d837d7e0a75`.
- Instrumented provider SHA-256: `01c32ef0f8c807de534eced3a3e202ec734d0329954f047a92c354bf6425cd12`.
- Restoration to the original hash was confirmed before test-data cleanup.
- All four generated test items (A original/copy, B next-open/reopened copy) were deleted individually. A final successful synchronization restored the pre-test calendar contents.
- The temporary Symcon script (42486, `OC TEMP Diagnose 20260918`) was removed with `IPS_DeleteScript(42486, false)`. This uses Symcon's [recoverable script-file removal](https://www.symcon.de/en/service/documentation/command-reference/management-scripts/ips-deletescript/).
- Original backup and filtered trace were moved, without overwriting existing files, to `/var/lib/symcon/scripts/deleted/oc-todo-trace-20260918.original.php` and `/var/lib/symcon/scripts/deleted/oc-todo-trace-20260918.jsonl`. They are inactive recovery/evidence artifacts on the Symcon host, not repository files.
- Archived trace SHA-256: `df2a263e2143df140b7aa4d13879542abfec8af4372dfb216b47f9736e6d22df`.
- Diagnostic scripts are temporary artifacts, not production or regression-test additions. A permanent regression test still needs to be added with the actual fix.

This document records observed behavior and the remaining investigation boundary; it does not claim a completed fix.
