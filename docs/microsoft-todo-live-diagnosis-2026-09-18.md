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

## Follow-up write experiments

The follow-up used fresh, uniquely named disposable tasks in calendar 15490,
with a daily, unbounded recurrence. Each task started on 22 September and the
requested new date was 24 September. Every experiment restored the original
provider hash above and synchronized/deleted only its exact test titles in a
`finally` block. Each run ended with `TEST_TASKS_REMAINING 0`.

| Update sequence | Observed result after a full synchronization |
| --- | --- |
| PATCH due date only | Two open task IDs; the original remained at the old date. |
| PATCH due date plus unchanged recurrence | Two open task IDs; the original moved to 23 September. |
| PATCH due date plus recurrence rebased to 24 September | Two open task IDs. |
| Due-only PATCH of a task created with a Windows timezone | Two open task IDs. |
| Due-only PATCH of a task created in UTC | Two open task IDs. |
| Remove recurrence, then PATCH due date and original recurrence together | One ID, but the resulting date was 23 September, not 24 September. |
| Remove recurrence, PATCH due date separately, restore recurrence rebased to 24 September | One ID at 24 September. This changes the recurrence range and is not evidence of an occurrence-only edit preserving series semantics. |
| Remove recurrence, PATCH due date separately, restore the original recurrence unchanged | One ID, but Microsoft reset its date to 22 September. Completing it then produced the completed 22 September item and the next open item on 23 September. |

The last two experiments explicitly wrote local midnight in `Europe/Berlin`,
so their results do not depend on the calendar layer's reuse of the old UTC
clock component. These observations rule out a smaller PATCH or a timezone
change alone as a demonstrated fix for this account.

Do not ship the recurrence-removal sequence as a transparent fix: it is a
multi-write operation without an atomic rollback and either resets the due
date or changes the recurrence range. Weekly/monthly rules, bounded series,
concurrent edits, network failures and completion after rebasing have not
been validated. A temporary restriction on rescheduling recurring native
tasks is a separate product decision, not a completed implementation of
rescheduling.

### Single-write recurrence updates

Additional fresh tasks ruled out setting `startDateTime` together with
`dueDateTime`, with or without rebasing recurrence: both produced two open
IDs. However, a PATCH containing **only recurrence**, with the new range
start, retained one ID:

- Daily: moved from 22 to 24 September; completing it created the completed
  24 September item and the next open item on 25 September.
- Weekly (Tuesday), unchanged pattern: requesting a range start of Thursday
  24 September selected Tuesday 29 September. This is not the requested move.
- Monthly (day 22), unchanged pattern: selected 22 October, not 24 September.
- Weekly with the pattern changed to Thursday: one ID on 24 September,
  followed by one completed item on that date and one open item on 1 October.
- Monthly with the pattern changed to day 24: one ID on 24 September,
  followed by one completed item on that date and one open item on 24 October.

Thus a single-write **series realignment** has been demonstrated for fresh
daily, weekly and absolute-monthly unbounded tasks on this account. It is
not equivalent to moving only the current due date while preserving future
weekdays/month days. Production implementation requires the user's decision
about that distinction, and tests for bounded/relative rules, existing
history, repeated edits and concurrent changes. No production code has been
changed based on these experiments. The user explicitly rejected disabling
rescheduling as a substitute for fixing it.

Follow-up cleanup: the original provider hash and absence of all `OC FIX
20260918` test tasks were checked again after synchronization. Seven original
provider backups (the unsuffixed probe backup and suffixes `b` through `g`)
were moved without overwriting to `/var/lib/symcon/scripts/deleted/`.
Temporary script 26701 (`OC TEMP Fixtest 20260918`) was then removed with
`IPS_DeleteScript(26701, false)`; its disappearance was confirmed in the
console. The local disposable probe was removed. Only this diagnostic report
was changed in the repository; no production fix, commit or push was made.

## Native Microsoft To Do reference and cross-tests

After the user signed into Outlook's hosted Microsoft To Do web UI, four
disposable tasks named `OC NATIVE REF 20260918 A`, `B`, `C`, and `W` were
tested. The installed module was not modified. Observations were checked
against a successful OpenCalendar synchronization, not only To Do's local
browser display. A temporary script (36778) read only the four exact titles
through `IPSKAL_GetMicrosoftTasks`; output excluded tokens, descriptions and
unrelated tasks, and hashed task IDs.

| Case | Creation | Due-date edit | Server-observed result |
| --- | --- | --- | --- |
| A, daily | To Do UI, 22 September | To Do UI, 24 September | One open task at 24 September. Completion produced a completed 24 September item and one next-open 25 September item. |
| B, daily | To Do UI, 22 September | OpenCalendar UI, 24 September | Two open tasks: original 22 September and additional 24 September item. Both were also visible in To Do. |
| C, daily | OpenCalendar UI, 22 September | To Do UI, 24 September | One task at 24 September; original Graph ID retained. |
| W, weekly | To Do UI, server-established Friday 25 September | To Do UI, Thursday 24 September | Same Graph ID at 24 September; recurrence changed from Friday to Thursday. Completion produced the next open item on Thursday 1 October. |

W's creation UI initially showed the selected 22 September due date, but
after the weekly preset was applied the saved task settled on Friday
25 September, with a Friday pattern. The pre-edit Graph read confirmed
that state; it must not be reported as an initially Tuesday-based series.

### What Microsoft itself changed

- C after native editing: ID hash `0aa6b1411e23`, due
  `2026-09-23T22:00:00.0000000` / `UTC` (24 September in Berlin), daily
  interval 1, recurrence range start `2026-09-24`.
- A after native completion: next-open ID hash `286530b45a6c`, due
  `2026-09-24T22:00:00.0000000` / `UTC`; completed-history ID hash
  `ad7dfa27f6b1`, due `2026-09-23T22:00:00.0000000` / `UTC`. Both range
  starts were `2026-09-24`.
- B after OpenCalendar editing: original ID hash `47251ae9cd15` remained
  due `2026-09-22T00:00:00.0000000` / `UTC`; new ID hash `a2a6e912e2ea`
  was due `2026-09-24T00:00:00.0000000` / `UTC`. Both range starts remained
  `2026-09-22`.
- W before native editing: ID hash `7dcda61c559d`, due
  `2026-09-24T22:00:00.0000000` / `UTC`, weekly interval 1,
  `daysOfWeek: [friday]`, range start `2026-09-25`.
- W after native editing: same ID, due
  `2026-09-23T22:00:00.0000000` / `UTC`, `daysOfWeek: [thursday]`, range
  start `2026-09-24`. `firstDayOfWeek: sunday` remained unchanged.
- W after native completion: same next-open ID hash `7dcda61c559d`, due
  `2026-09-30T22:00:00.0000000` / `UTC` (1 October in Berlin); a new
  completed-history ID hash `4f14b9f6cd71` was due on 24 September. Both
  retained the Thursday pattern and range start `2026-09-24`.

### Revised conclusion

The native UI does **not** preserve the old recurrence anchor/week day in
these daily and weekly preset cases when the user edits only the due date.
It realigns the recurrence itself, while preserving the open task's ID.
The earlier proposal to ask the user to choose between two alternative
semantics was therefore premature: the native reference supplies the
expected behavior for these cases.

The cross-test rules out OpenCalendar creation as a necessary cause of
the duplicate: even a Microsoft-created task duplicates through the
OpenCalendar edit path, whereas a task created by OpenCalendar can be
edited correctly through Microsoft. It does not yet isolate the precise
request difference or prove a general Graph service defect. Microsoft UI
network payloads were not captured. The supported Graph write sequence
still needs to reproduce these states, including timezone behavior, before
a production fix can be claimed. Native monthly, relative, multi-weekday
and bounded patterns were not tested in this reference run.

Cleanup was guarded by all four exact titles and the seven previously
observed task-ID hashes. The seven items (including generated history and
duplicate items) were deleted through `IPSKAL_DeleteEvent`; a subsequent
successful synchronization returned `TEST_ITEMS_REMAINING 0`. The Microsoft
UI returned to the original one open task and eight completed tasks.
The provider SHA-256 remained
`9dc15c25b33cc0c4145a16e1496d924dc05442ee4b20c6edd1845d837d7e0a75`.
Script 36778 was removed recoverably with `IPS_DeleteScript(36778, false)`
and its tab disappeared. No production code, installed module, existing
task, branch or GitHub state was changed in this reference run.
