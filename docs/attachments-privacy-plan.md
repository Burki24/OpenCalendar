# Optional attachments: implementation and security plan

Status: foundation in progress, not a released attachment feature.
Scope: `dev_9.1` only. No changes to `dev`, deployments or OAuth registrations.
Google is deferred until its OAuth scopes are agreed with Symcon. Microsoft
calendar events and native To Do tasks, CalDAV/Apple, read-only iCalendar feeds
and local calendars remain in scope; this is not a Microsoft-only first release.

## Approved product requirements

- Disabled by default. Separate read-only access from attachment management.
- The administrator may allow local storage, provider storage, or both.
- The uploader explicitly selects an allowed destination before transmission.
- Local storage never silently uploads files to the calendar provider.
- Existing provider attachments are distinct from locally added documents.
- Disabling the feature or changing destinations neither migrates nor deletes files.
- Provider failures never silently fall back to another storage destination.
- The UI must explain destination and access audience before uploading. Local
  means stored on the Symcon host, not private to an individual or excluded from backups.

## Initial code inspection (2026-09-20)

| Boundary | Evidence | Consequence for attachments |
| --- | --- | --- |
| IPSView hook | `Kalender Ansicht/module.php`, `ProcessHookData`: enabled check, POST-only, shared view token and `hash_equals` | This authenticates possession of a view credential, not a named user. Do not claim per-user document permissions. |
| Token initialization | `ensureIPSViewToken` / `ipsViewToken`: four persisted integers, zero sentinel before initialization | The hook must reject the sentinel even when a caller supplies the same value. Added executable regression test; no live exploit claim. |
| IPSView HTML | `render` path includes the view token in the client configuration | Anyone with access to that document can possess the credential. Rotation, revocation and stale HTML need explicit tests. |
| IPSView response | `outputIPSViewResponse`: no-store, nosniff, wildcard CORS | Existing compatibility settings are not proof of safe binary delivery. Establish the dedicated transfer boundary before enabling attachments. |
| Calendar writes | `requireWritableCalendar`: selected calendar plus write capability | Reuse selection checks, but add separate attachment policies for reads and writes. Local annotation rights must not imply provider write rights. |
| Native visualization | `RequestAction` uses `UpdateVisualizationValue` and state updates | Do not put file bytes, private download credentials or local storage paths into shared state/HTML. Use a requester-bound transfer route. Runtime delivery isolation is not yet verified. |

The inspection and the PHP stub-based hook test do not establish TLS termination,
real Symcon user/session authorization, multi-client isolation, filesystem access,
backup encryption or provider permissions on an installed system.

## Architecture and required invariants

1. Central attachment policy, enforced server-side in both view and calendar
   entry points. Client-supplied calendar IDs, permissions, paths and provider URLs
   are untrusted. Check view selection, configured attachment mode, ownership of
   the event/task, destination and provider capability on every operation.
2. Define the access audience explicitly: at minimum all holders of an authorized
   calendar-view credential. Until stronger identity is verified, do not advertise
   private-per-user attachments. Local administrators and backup operators are
   also part of the relevant trust model.
3. Keep metadata and file content separate. Fetch provider lists only on demand;
   do not fetch document bodies during normal synchronization or in disabled mode.
   No file bodies in calendar caches, debug logs, HTML exports or shared updates.
4. Local originals use a private durable store, outside publicly served directories.
   Opaque IDs, safe generated paths, owner binding, quotas and atomic writes are
   required. Do not use the event cache as the only original. Define backup/restore,
   encryption and key recovery against the actual Symcon 9.1 storage facilities.
5. Transfers require authenticated, authorized access over verified TLS. Do not
   trust arbitrary forwarded-protocol headers. Download tickets, if used, must be
   short-lived, resource/destination scoped and revocable; never appear in URLs,
   logs or shared state. They are not a substitute for user/view authorization.
6. Bound sizes before decoding and while streaming, validate filenames and MIME
   types, reject path traversal/header injection and enforce a conservative type
   policy. Serve downloads with attachment disposition and nosniff; no automatic
   active-content preview. Upload validation is not an antivirus guarantee.
7. External ATTACH links never cause unrestricted server-side fetches. Protect
   against SSRF, redirects, credential forwarding and unsupported schemes. Opening
   a provider link in the browser is a separate, explicitly labelled action.
8. Mutations need concurrency checks and duplicate/retry protection. Preserve
   existing attachments during ordinary event edits. Series, exceptions, moved
   events and regenerated To Do history must not reassign local files by title.
9. Never widen sharing, create public links or invite additional readers implicitly.
   Reusing calendar access is not proof that all attachments share identical rights.

## Deletion and retention

- Distinguish removing an association, deleting a local original, deleting a cached
  copy and deleting a provider file. Do not delete a shared original as a side effect.
- Define and expose temporary-data TTL, storage quotas and abandoned-upload cleanup.
- Deleting a calendar or uninstalling must not silently leave inaccessible originals
  or irreversibly destroy them; specify export and explicit cleanup before release.
- Backup retention and restoring previously deleted records require operator-facing
  instructions. Do not promise erasure from backups or provider history from an API result.
- Disabling attachments blocks access immediately but preserves originals. Explain
  this explicitly and provide a separate deliberate cleanup operation.

## Provider paths

| Provider | Provider-side path | Required validation |
| --- | --- | --- |
| Microsoft events | Graph event attachments | Selected-calendar ownership, file vs item/reference attachments, size limits, series/exception behavior, access revocation |
| Microsoft To Do | Graph task file attachments | List/task ownership, history vs next task, reopening, recurrence and upload limits |
| CalDAV / Apple | iCalendar ATTACH, conditional resource updates where supported | Embedded vs URI values, server limits, ETags, preservation of unrelated properties, recurrence exceptions and clients |
| ICS subscription | Read-only ATTACH metadata / supported content | No write-back; safe URI handling, content limits and no silent mirroring |
| Local calendar | Local durable attachment store | Restart/restore, ownership identity, transactions and original-file retention |
| Google | Deferred | No Drive scope request or upload until approved integration is available |

Local attachments may annotate remote read-only records if explicitly allowed by
the administrator; this does not grant any write access to the remote calendar.
Unsupported server capabilities must be reported, not simulated with undisclosed
local-only storage. Provider-side files remain governed by provider permissions.

## Ordered implementation milestones

1. **Access baseline (current):** inspect bridges, reject uninitialized credentials,
   establish this plan and execute hook regression tests. No uploads enabled.
2. **Policy and transfer boundary:** disabled/read/manage modes, permitted destinations,
   explicit choice and server checks; determine native/IPSView requester isolation,
   TLS deployment and revocation before adding any document delivery.
3. **Local durable storage:** bounded upload/download, metadata ownership, cleanup,
   backups and restart tests; usable across supported calendar sources.
4. **Provider integration:** Microsoft event/task APIs and CalDAV/ICS ATTACH parsing,
   capability handling and round trips; no Google scope change.
5. **Shared UI:** tile and IPSView details/editors; explicit destination, lazy lists,
   protected downloads, deliberate delete, understandable partial-failure reporting.
6. **Release gate:** full tests, real Symcon 9.1 multi-client/permission/restart tests,
   authorized disposable provider tests, operator privacy/retention documentation.

## Acceptance evidence still required

- Disabled means no extra provider requests, file transfers or attachment disclosure.
- Forged calendar/event/task/attachment IDs, wrong view, read-only policy, revoked
  credentials, uninitialized credentials and expired transfers fail before file I/O.
- A download requested in client A is not disclosed to client B through state updates.
- Local-only files never reach a provider; provider-only files are not archived locally.
- Destination changes cannot migrate existing files; authorization failures cannot
  fall back to another destination.
- Oversized, malformed, traversal and active-content uploads fail safely without
  logging their contents. Interrupted uploads have bounded cleanup.
- Restart and backup restore preserve originals and ownership; cache clearing does
  not destroy originals. Unlink/deletion semantics are tested independently.
- Provider tests cover inherited series attachments, exceptions, task completion,
  reopening, rescheduling, ETag conflicts and uncertain upload responses.
- TLS/session/authorization assumptions must be demonstrated on the deployment,
  not inferred from local unit tests.

This is an engineering plan, not a legal compliance certification. The operator
must assess lawful purpose/basis, affected persons, recipients, contracts and
retention for the actual deployment. No checkbox grants permission on behalf of
other people whose data a document contains.
