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
- Base access is scoped to a protected OpenCalendar view and must not require
  IPSView Professional. All authorized holders of that view share its attachment
  permissions; this is not private-per-user storage.
- Optional IPSViewUsers user/group restrictions are an additional intersection
  with view/calendar/destination permissions, never a way to widen them.
- If user/group restrictions are configured but verified identity or the adapter
  is unavailable, deny attachment access. Never fall back to shared-view access.
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

## IPSViewUsers integration investigation (2026-09-20)

Read-only inspection of the installed IPSViewConnect 6.5.15 package, including
`IPSViewUsers/module.php`, `IPSViewConnect/module.php` and their manifests:

- IPSViewUsers module ID: `{B695E7A3-0F24-4B8D-8B78-6E86F24C4D97}`; prefix `IVU`.
- `GetUserViewID` maps a supplied username to its assigned view. It does not
  authenticate the caller. `GetUserView` computes a view with group-dependent
  `UsedIDs` write flags; this is not a current-session identity API either.
- IPSViewConnect `API_AssignViewData` resolves user/view context. Its
  `ProcessHookAPIRequest` validates the password at its own endpoint using
  `PHP_AUTH_PW` before executing an API operation. That verification does not
  establish identity on OpenCalendar's separate hook.
- No public session-verification/ticket API for third-party hooks was found in
  these inspected module files. This is a version-scoped finding, not proof
  that no supported integration exists anywhere in the product.
- Do not call password-returning APIs or copy credential-bearing user/view
  properties to implement an identity shortcut. Only source code was inspected;
  no actual user/password configuration was read and no live changes were made.

The vendor documentation confirms user/view assignment and group restrictions,
but does not document third-party hook identity propagation:

- https://docu.brownson.at/viewdesigner/WebHelp/DesignerSettingsMaintainUsers.html
- https://docu.brownson.at/viewdesigner/WebHelp/DesignerSettingsMaintainGroups.html

Before implementing the optional identity adapter, obtain a supported contract:

1. How can an embedded HTML-Box request carry a server-verifiable principal to a
   third-party hook without exporting the user's password?
2. Is a signed, short-lived, audience-bound ticket or server-side validation API
   available? How are replay, expiry and revocation handled?
3. How are authoritative user and group identifiers obtained? How are changes
   and user deletion reflected immediately without trusting client claims?
4. Can the caller's assigned view and permitted objects be verified for a specific
   OpenCalendar view? Do not confuse an IPSView media ID with an OpenCalendar
   calendar-view instance ID.
5. Which versions and clients support this, and what happens after logout?

No automatic user/group integration is enabled by this plan. Do not present the
presence of IPSViewUsers, a username, client-supplied groups or HTTP Basic header
fields alone as authenticated identity. Do not change existing calendar access
or require a Professional license for the base attachment mode.

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
5. Transfers require authenticated, authorized access over verified TLS by default.
   The user approved an explicit, default-off local HTTP exception on 2026-09-20.
   This accepts interception/modification risk, not legal compliance or privacy.
   Do not
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

### On-demand Microsoft provider metadata (2026-09-22)

The protected `TransferAttachment` POST now accepts destination `provider` for
operation `list` with empty `data`. Calendar `ListProviderAttachments` intersects
calendar policy with the view's selection/permissions and transport checks. It
uses the configured calendar and, for To Do, the configured task list. Accepted
selectors are only `eventReference`, or `sourceType=microsoft-todo`, `taskListId`
and `taskId`. Extra source, owner, calendar or URL claims are rejected. The calendar
rechecks policy, calendar ID, parent connection and task-list selection after I/O;
the view rechecks its credential and permissions before sending the response.

Microsoft event and task providers confirm the exact parent in its selected
calendar/list using a fresh bounded Graph GET. They then list attachment metadata
with an explicit `$select` excluding contentBytes. Parent failures, cancelled
events and mismatched IDs stop the operation. No fallback to another calendar,
series master, task successor or local store is attempted. Occurrence/master
inheritance and stale event-ID recovery are not implemented by this reader.

The shared collection reader restricts continuations to the same owner path and
trusted Graph origin. It reasserts metadata-only selection, rejects expansion/body
queries and loops, and limits page responses to 256 KiB, pagination to 32 pages,
the list to 100 files and encoded result metadata to 192 KiB. Unsupported response
shapes fail without a partial success. File, item, reference and unknown attachment
types are distinguished. Provider URLs, private body fields and download tokens
are never included in the result. Metadata remains untrusted display text, not a
filesystem path or HTML fragment. The existing guarded Graph HTTP client enforces
redirect origin restrictions. Attachment gateway requests/errors bypass event
debug logging and return generic errors without filenames or provider URLs.

This is a request-local backend listing, not an end-user attachment UI. Native
tile transfer isolation, provider downloads/uploads/deletions and live provider
tests remain open; Google stays deferred. No provider attachment
requests are added to normal synchronization. `transferAvailable` remains false
for the not-yet-released complete feature. Existing local transfers are retained.

Tests cover the real calendar/gateway/provider chain with an HTTP boundary double,
disabled policy without I/O, scoped parent lookup, continued pages, malformed and
oversized responses, private error handling, mid-request revocation/source changes,
and the real view hook's authentication, transport and unsupported-operation gates.
No live Microsoft data was read or changed by these tests.

API references checked for this implementation:

- https://learn.microsoft.com/en-us/graph/api/event-list-attachments?view=graph-rest-1.0
- https://learn.microsoft.com/en-us/graph/api/todotask-list-attachments?view=graph-rest-1.0

### On-demand CalDAV and ICS metadata (2026-09-22)

The same protected provider-list route now accepts an exact iCalendar event
identity: `uid` and an optional original `recurrenceId`. The calendar rejects
additional owner, URL and calendar claims. The account resolves the configured
calendar server-side, rejects Google and task selectors for non-Microsoft
accounts, and routes only CalDAV/Apple or configured read-only ICS sources.

CalDAV performs one bounded calendar query for the UID and requires exactly one
resource under the selected calendar. Read-only URL subscriptions use one fresh,
bounded request without writing attachment data into their shared parsed-event
cache. Configured ICS files are parsed from their existing source. Normal calendar
synchronization remains unchanged and never copies attachment bodies or metadata.

The parser handles RFC 5545 `ATTACH` values only at VEVENT level. Valid Base64
`VALUE=BINARY` attachments are classified as `embedded`; URI values are classified
as `reference`. URI values, credentials, query tokens and binary contents are not
returned. References are not opened, redirected, probed or downloaded. Nested
alarm attachments are excluded. A filename is used only when a supported filename
parameter exists; otherwise a neutral generated label is returned. All metadata
remains untrusted display text.

Recurring exceptions use their own ATTACH properties. A verified generated
occurrence inherits the series master's metadata. Missing, cancelled, duplicate
or ambiguous UID/recurrence identities fail closed. Resources are limited to
16 MiB, results to 100 entries and encoded metadata to 192 KiB. Malformed binary
content or attachment parameters abort the whole list without partial results.

Focused tests cover embedded data, opaque external references, nested alarms,
exception selection, master inheritance, missing occurrences, duplicate UIDs,
malformed Base64, configured ICS files, fresh URL feeds and bounded CalDAV lookup.
The calendar/account/provider integration is exercised for ICS and CalDAV.
No live CalDAV/Apple/ICS server was contacted. Downloads, uploads, provider-side
deletion and the shared end-user UI remain open; Google remains deferred.

Specification used for the parser:

- https://www.rfc-editor.org/rfc/rfc5545#section-3.8.1.1

### Administrative local recovery and cleanup (2026-09-22)

Trusted Symcon scripts can now inventory all local originals, start/read/finish
a bounded backup transfer, and deliberately delete selected local file IDs.
These maintenance methods intentionally work with attachment access disabled,
an inactive calendar, or an event that no longer exists. They are administrator
operations, not additional rights granted by a view token. The IPSView hook and
visualization actions do not dispatch these methods; hook regression tests check
rejection even with a valid view credential. No maintenance UI is enabled yet.

The inventory excludes file bodies, owner bindings and upload retry IDs. It
includes a revision of the complete current store. Cleanup must supply both an
explicit, nonempty list of unique existing file IDs and this revision. The store
is reread under the same per-instance lock used by uploads/deletions. Any changed
inventory, missing selection member or failed save aborts the operation. Cleanup
never guesses orphan status, migrates associations, or removes provider files.

Backups are immutable point-in-time snapshots of the validated local store,
including ownership and retry information needed to preserve identities. They
use the existing encrypted temporary transfer helper with a separate scope and
five-minute lifetime. Each script response is bounded below 200 KiB even for the
full 8 MiB original-data quota; only small transfer metadata enters instance
buffers. Explicit finish removes temporary transfer data. Expiry prevents reads;
abandoned expired files are cleaned when the helper next creates a transfer.
A Symcon restart discards the in-memory encryption keys. Cleanup of originals
does not revoke an already-started administrator backup or erase saved copies.

The backup protocol is documented in `docs/attachments-administration.md`.
The caller chooses a private backup destination; the module creates no public
media export, hook download or shared state update. Backup JSON is not encrypted
once assembled by the administrator. It contains documents and private bindings.
Treat it with the same access restrictions and retention as a Symcon backup.
It does not include calendar originals or remap ownership to another instance.
No automatic import/restore API or user-facing release is implied by this step.

Fresh local tests cover deleted-event recovery, disabled access, empty and
full-quota binary backups, exact snapshot restoration in the store, cross-instance
and cross-transfer-scope denial, expiry, explicit cleanup, concurrent changes,
failed writes and corrupt-store preservation. Actual Symcon script response
limits, restart durability and operator backup restoration remain live release
checks. Provider adapters, shared end-user UI and named-user identity remain open.

### Request-scoped local transfer route (2026-09-20)

The IPSView POST hook now accepts `TransferAttachment` with the existing view
token and bounded JSON `value`: calendarId, operation, explicit destination
`local`, selector and data. Operations are list/upload/download/delete. Selection
and view/calendar policy are checked before dispatch and before returning data;
TLS (or the explicitly configured private-peer HTTP exception) is mandatory.
The calendar's `TransferLocalAttachment` script API resolves the event from local
originals under lock. Unknown owners, remote calendars and provider destinations
are rejected. This script API is for trusted Symcon callers, not browser identity.

Responses are request-local: no state update, wildcard CORS, content logging or
URL credentials. Downloads use octet-stream, attachment disposition (currently
the neutral filename attachment.bin), nosniff and no-store. Failed transfers have
generic errors; mutations may have committed before a final access revocation,
so clients must refresh and reuse upload retry IDs, not assume rollback.
The hook limits encoded HTTP bodies to 4 MB and decoded JSON values to 3 MB;
file content remains capped at 2 MiB. The web server must also impose request
limits before PHP parses POST bodies. No streaming/chunked uploads are enabled.

Local tests exercise hook authorization, transport denial, selected-calendar
checks, post-read revocation, and the real calendar API's authoritative lookup.
Real HTTP response headers, Symcon multi-client behavior and restart/backup tests
remain release gates. No upload UI or native tile transfer action is enabled;
preflight still reports transferAvailable=false for the end-user feature.
Remote-provider ownership, protected export/orphan cleanup and the user/group
adapter remain pending. This route does not make the overall feature release-ready.

### Upload admission policy (2026-09-20)

`AttachmentUploadPolicy` now validates new local uploads inside the private
calendar persistence adapter before saving. Initial formats are UTF-8 TXT, PDF,
PNG and JPEG, limited to 2 MiB. Unsupported extensions (including HTML, SVG,
executables, archives and Office documents), path/header/control characters,
Unicode formatting controls, Windows reserved names, noncanonical base64 and
oversized content are rejected without changing originals. Existing stored files
are not deleted, migrated or revalidated during restore, listing or download.

The policy uses UTF-8/control checks for text, PDF header/end markers and image
header dimensions/type for PNG/JPEG. These are admission checks only: PDF scripts,
embedded documents, polyglots, malformed internals and malware are NOT reliably
detected or removed. Even accepted files are untrusted. Future delivery must use
download-only disposition, nosniff, no preview and a protected requester-bound
route. The helper does not execute files, fetch URLs or decompress image pixels.
Image dimensions are capped at 10,000 per axis and 40 million pixels.

Tests exercise the actual private adapter, rejection without persistence changes,
lock release, UTF-8 text, PDF/PNG admission and the exact size boundary. Provider
upload adapters must reuse this policy when implemented; no provider uploads or
public transfer endpoint are enabled by this step. File-type selection can be
expanded deliberately later; this is not antivirus or a privacy certification.

### Authoritative local event access (2026-09-20)

Private `verifiedLocalAttachmentOperation` now joins the local provider, owner
identity builder and persistence transaction. It accepts only UID, a bounded
seven-day lookup window and optional original recurrence slot selectors. Caller
owner hashes, source/provider identities and resource URLs are rejected. Source
identity is built from the actual local calendar instance/reference; matching
records are read fresh from local iCalendar originals, never the display cache.
Exactly one record must match; recurring records require an original slot.

Lock ordering is local calendar first, attachment storage second. The original
calendar lock remains held through the attachment operation, preventing normal
calendar writes/deletion from racing the ownership check. Both layers recheck
calendar policy after acquiring locks and release locks on failure. Remote
calendars are rejected by this local-only path, not treated as local originals.

Tests exercise real local event creation, series expansion, upload/read/list
through this private gate, independent series slots, cache clearing, unknown
UIDs, forged URLs/owner fields, oversize lookup windows, revoked rights and
deleted events with a simulated Symcon platform. Deleted-event attachments stay
stored but inaccessible via that event; deliberate orphan cleanup/export is still
needed. Calendar transfer/series splitting is not automatic attachment migration.

This closes the local event lookup gap, NOT the full access boundary: the method
is still private, with no public wrapper, browser upload/download route or UI.
View/session/transport validation, file-type restrictions, actual Symcon runtime
tests and provider-specific ownership lookup remain necessary before release.

### Attachment identity rules (2026-09-20)

`AttachmentOwnerIdentity` defines versioned storage keys from authoritative source
and normalized event records. The source includes the calendar instance, provider,
actual provider account identity and calendar identity. A reused account instance
number alone is not a provider-account identity. Local calendars need a stable
server-owned local identity in this field. Google remains unsupported.

- Local/CalDAV/ICS events use UID; Microsoft events use provider event reference.
- Occurrences/exceptions use their parent identity and immutable original slot:
  RECURRENCE-ID for iCalendar sources, series ID/originalStart for Microsoft.
  A moved exception and the original occurrence share a key, not the next slot.
- Microsoft To Do uses list ID plus task ID, tested with the real projection
  implementation. Completion/reopening preserve identity; newly created successor
  tasks have separate identities. No implicit attachment copying is implemented.
- Titles, descriptions, current dates and ETags never determine ownership.
  Missing/ambiguous recurrence or provider identity fails closed. Series masters,
  individual occurrences and single events are separate; no inheritance is implied.

Tests cover these rules and round trips through the local attachment store.
This helper is NOT yet the server-side ownership verifier and is not wired to
a public upload endpoint. It must never receive unverified browser records. The
adapter still needs authoritative lookup, source/account-change detection and
revocation checks; cached events alone do not establish current remote rights.
Provider normalization stability, series splits, moves between calendars and real
provider lifecycle tests remain release requirements. Current date changes cannot
be used to recover orphaned files by guessing a title or nearby occurrence.

### Internal Symcon persistence adapter (2026-09-20)

The calendar now registers `LocalAttachmentOriginals`, a dedicated persistent
string attribute, separate from event originals, event caches, transfer buffers
and media. The private `localAttachmentOperation` stages mutations through
`LocalAttachmentStore`, reads the latest snapshot under a per-instance semaphore,
checks calendar attachment policy before reading and again before returning or
saving, and only acknowledges a mutation after `WriteAttributeString` succeeds.
Exceptions always release the semaphore; no body/path is logged by this adapter.

This is intentionally private: no new script wrapper or browser action accepts
an owner hash. A future authenticated transfer layer must resolve the real
calendar/account/event/task identity and intersect view rights before calling it.
The generic public event-edit lookup is not automatically sufficient proof of
document ownership. Upload/download and `transferAvailable` remain unchanged.

Tests invoke the private seam with a platform double to demonstrate lifecycle
preservation, simulated restart, cache clearing, failed saves, lock rejection,
rights revocation and reading the latest committed snapshot after acquiring the
lock. Real Symcon crash durability, concurrent clients and actual backup/restore
are still unverified; the module relies on Symcon's attribute persistence contract.

Storage consequences: this is bounded, base64-encoded attribute storage, not
encrypted file storage. Symcon configuration backups containing attributes also
contain original attachments. Administrators with instance/backup access can read
them. Do not export full attributes as routine diagnostics. Disabling attachments
does not erase originals. Deleting the calendar instance can remove its attribute
store; protected export/cleanup and operator backup instructions are required
before enabling the end-user feature. ICS calendar export does not include these
local documents. No public media file or remote-provider upload is created.

### Local original-data working store (2026-09-20)

`LocalAttachmentStore` is the bounded working-copy engine, not yet a runtime
storage adapter or an upload API. Like the local calendar resource adapter it
returns a private snapshot that a future adapter must persist atomically under
a calendar lock. Nothing currently registers, writes or exposes that snapshot
in Symcon. This avoids choosing a public media directory or placing original
files in the event cache before the storage/access boundary is verified.

- Limits: 2 MiB per file, 8 MiB original bytes and 100 files per store. These
  conservative initial limits are constants, not advertised provider limits.
- Canonical base64 is bounded before decoding; filenames cannot contain paths,
  control characters or header injection. The display name is never a disk path.
- Opaque random file IDs, owner-bound listing/read/delete, content revisions,
  and matching retries prevent duplicate uploads while the record is retained.
  Retry IDs are not credentials. Deletes require the current revision.
- Metadata excludes file content, ownership keys and retry IDs. Originals are
  separate and restored with schema, hash, size and quota validation. Corrupt
  snapshots throw instead of silently resetting data.
- Content is opaque, with no preview, MIME/type safety or malware guarantee.
  The future upload boundary still needs a conservative file-type policy.

Tests prove binary snapshot round trips, working-copy isolation, quota boundaries,
cross-owner denial, repeated upload handling and corruption rejection. They do
not prove durable Symcon writes, concurrency, backup/restore, encryption, cache
clearing or real restart behavior. Owner keys must be resolved by the server from
verified account/calendar/event/task identity, never accepted from browser input.
The runtime adapter must recheck policy and identity before all I/O, and handle
deletion/retry races, retention, orphan cleanup and series identity changes.
No uploads/downloads are enabled by this core; `transferAvailable` stays false.

### Transport preparation and approved HTTP exception (2026-09-20)

View property `AttachmentAllowLocalHttp` defaults to false and has a visible
warning. The transport policy accepts runtime HTTPS or explicitly enabled HTTP
from a private/loopback peer. Forwarded headers cannot prove HTTPS and reject
the HTTP exception. A private peer does not prove original client location:
operators must not expose plaintext forwarding or hidden proxy paths.
Connect/ipmagic TLS recognition remains unverified and is not inferred from
hostnames. No provider-side TLS validation was weakened.

Authenticated POST `CheckAttachmentAccess` on the existing IPSView hook accepts
a JSON `value` with integer `calendarId`, string `operation` and `destination`.
It checks transport and the existing calendar/view policy intersection, returns
only to that HTTP request, and never updates shared visualization state.
Success explicitly returns `transferAvailable: false`: this is configuration
preflight, not document authorization, upload/download or an access ticket.
The native tile transport, Connect validation, document ownership and actual
storage/transfer implementation still need completion before step 2 is complete.

### Implemented configuration policy slice (2026-09-20)

`CalendarAttachmentPolicy` centralizes disabled/read/manage modes and explicit
local/provider permissions. Both calendar and view default to disabled with no
destination allowed. Calendar permissions are the ceiling; the view checks its
selection and intersects both configurations on every query. Inactive calendars
deny access; provider mutations also require calendar write capability. Local
annotations do not require remote write access. Settings do not migrate/delete files.

`IPSKAL_CanAccessAttachments(calendarID, operation, destination)` and
`IPSKALVIEW_CanAccessAttachments(viewID, calendarID, operation, destination)` are
configuration queries only, not credentials or transfer endpoints. Operations are
`list`, `download`, `upload`, `delete`; destinations are `local`, `provider`.
Disabling a destination also denies reads there. Unknown parameters deny access.
The reserved view `AttachmentIdentityMode` defaults to shared mode (0); all other
values fail closed until the optional identity adapter is implemented.

Tests cover the policy matrix and real module methods with a stubbed Symcon
runtime, including selection, revocation, unavailable calendar APIs and local vs
provider write rights. No live identity/session or file transfer is proven by
these tests. Upload, download, storage, provider capabilities and event ownership
checks remain future work; the preparation settings do not enable file delivery.

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
