# ACL Agent Rooms

ACL Agent Rooms is the complete Free WordPress plugin for room-based conversations with provider-routed agents. WordPress owns rooms, members, messages, agents, runtime state, and access control. ACL Switchboard owns provider credentials, provider routing, adapters, and model execution.

## Version

- Plugin version: `1.5.0`
- Database schema version: `ACL_AR_DB_VERSION` is `1.4.1`
- Extension API version: `1`

Phase 9 (`phase9`) UI contracts include `aria-live`, `focus-visible`, and local room search. Operational responses use `private, no-store` caching and `Vary: Cookie, X-WP-Nonce`.

Durable event contracts: `moderation` and `message_delete`. Health and maintenance require `manage_settings` through the plugin capability bridge.

Version 1.4.1 makes accepted human messages visible immediately. The browser renders a restrained optimistic row keyed by the existing client request ID, while the server atomically commits the message, canonical event, and sender read boundary before scheduling any provider-bearing work. Independent jobs, Shared Brain runs, Natural Conversation turns, prompt construction, project-file retrieval, and ACL Switchboard calls run after that commit. The canonical event reconciles the optimistic row whether the POST response or polling arrives first; ambiguous failures retain a safe same-nonce retry without creating duplicate messages or events.

Version 1.5.0 introduces the documented Free/Pro extension boundary without moving or locking any existing feature. The optional ACL Agent Rooms Pro add-on uses the public extension API to provide advanced operational reporting. Free keeps the complete room experience, every core runtime, all 21 existing data tables, the only queue/maintenance workers, and all security, privacy, moderation, accessibility, and recovery behavior. The database schema remains 1.4.1 because this release changes no table or column.

## Free and Pro architecture

Free exposes three lifecycle actions and a versioned `ExtensionApi` contract. Pro physically contains its own admin page, reporting queries, styles, and REST controllers; no premium implementation ships in Free. Free is fully usable when Pro is absent. Pro remains dormant with one administrator notice when Free is missing, creates no partial runtime state, owns no core schema, and makes no Patreon request. See `docs/free-pro-boundary.md` and `docs/extension-api.md`.

## First Run

1. Activate **ACL Agent Rooms** in wp-admin.
2. Open **ACL Agent Rooms** for the dashboard.
3. Confirm Switchboard availability, provider discovery, and model discovery.
4. Open **Settings** and choose default provider/model values if desired.
5. Create an independent agent or a Shared Brain, then choose each agent's execution mode.
6. Create a room, add top chat text/context, and assign enabled agents.
7. Place the displayed shortcode on a page.

## Create an Agent

1. Open **ACL Agent Rooms > Agents**.
2. Add a name, slug, description, optional Media Library avatar, provider route, model, prompt, behavior settings, visibility, and enabled state.
3. Use the provider-aware model dropdown when Switchboard exposes provider ownership.
4. Use **Custom model...** for edge cases where a model is not in discovery.
5. Choose **Shared Brain** and select a Brain when multiple distinct agents should share one provider request. Their identity and persona fields remain independent.

Provider credentials remain in ACL Switchboard. Agent Rooms stores only provider route and model identifiers.

## Shared Brain Orchestration

Version 1.1.0 adds a durable Brain runtime. Agents assigned to the same Brain keep their own names, avatars, descriptions, prompts, room assignments, and participation settings while inheriting the Brain provider, model, temperature, and token limits. One trigger produces one idempotent Brain run and one Switchboard request per Brain group. Validated JSON responses fan out as normal agent messages with one usage record and one moderator-only terminal audit event. Brain execution never creates individual agent jobs and never falls back silently to stored independent models.

Use `/ask agent-a,agent-b,agent-c message` to explicitly target several assigned agents. Same-Brain targets are grouped into one run; different Brains and Independent agents retain their separate execution paths.

## Create a Room

1. Open **ACL Agent Rooms > Rooms**.
2. Add a title, slug, description, top chat text/context, type, visibility, status, response mode, context limit, and max agents per turn.
3. Assign one or more enabled agents.
4. Copy the displayed shortcode.

## Room Project Files

Version 1.4.0 treats the Agent Room as a bounded project container. The **Project Context** room settings include persistent project instructions, disabled-by-default file and agent-access switches, manual/automatic/hybrid retrieval, and per-request file and character budgets. The **Room Files** panel uses the optional ACL Storage 0.6.0 `AssetServiceV1` contract to upload private text/code files or attach an existing owned asset. Agent Rooms stores only ACL Storage asset IDs and room-specific metadata; it never stores physical paths or duplicates file bytes.

Supported text/code extensions are `txt`, `md`, `markdown`, `json`, `yaml`, `yml`, `xml`, `csv`, `php`, `js`, `jsx`, `ts`, `tsx`, `css`, `scss`, `html`, `htm`, `py`, `java`, `c`, `h`, `cpp`, `hpp`, `cs`, `go`, `rs`, `sql`, `sh`, `ps1`, `ini`, and `env.example`. Source files are stored by ACL Storage under neutral `.blob` names and are read as untrusted text only. Archives, SVG, PDFs, office documents, images, audio, and video are not indexed in this release.

Extraction is bounded, binary-aware, UTF-8-safe, line-preserving, hash-checked, and never executes or includes source. Local lexical retrieval considers authorized active versions only, applies manual/automatic/hybrid selection and room budgets, and emits line-aware excerpts. Both Independent and Shared Brain prompts receive project instructions and retrieved file context once behind an explicit untrusted-material boundary. The live **Files** control selects existing project files for the next successful request; it is intentionally not an upload control.

File citations such as `[filename.php, lines 84-112]` become keyboard-accessible controls that open a server-authorized, escaped, read-only viewer. Downloads use short-lived, user-bound ACL Storage URLs. Removing a room association clears its extracted/indexed room context and preserves the storage asset. The separate explicit delete action uses ACL Storage authorization. Replacing a file creates Agent Rooms version lineage while retaining the earlier storage asset. Clear Chat never removes project files. Deleting a room removes Agent Rooms associations and extracted indexes but preserves ACL Storage assets.

ACL Storage is optional: when missing or incompatible, existing Agent Rooms behavior and routes continue, controls degrade safely, and health reports the integration state. A later composer-upload feature can create an ACL Storage asset and reuse the same association, extraction, indexing, viewer, permission, and retention services; version 1.4.0 deliberately adds no message-attachment schema, upload button, or event.

## Natural Conversation

Version 1.3.0 added an optional per-room **Natural** conversation style. Version 1.3.1 added Natural column-order normalization, and version 1.3.2 extends that installer-only repair to the documented legacy Clear Chat and Shared Brain column chains. Runtime behavior remains unchanged, and fresh installs plus supported upgrades converge on one canonical schema. Existing and new rooms remain **Immediate** unless a manager opts in. A local, server-side director applies eligibility, explicit-target overrides, silence, participation chance, cooldown, rolling reply limits, role weighting, responder limits, and recent-speaker fatigue before any provider work is created. Unselected agents create no jobs, Brain turns, or provider requests.

Selected replies are stored as durable scheduled conversation turns and published one at a time. Shared Brain agents still use one Brain run, one Switchboard request, one usage record, and one terminal audit event; validated responses wait for their separate server-calculated due times and never call Switchboard again at publication. Independent agents retain their existing provider/model jobs but are created only when selected. New human triggers, Clear Chat, pauses, disablement, and maintenance recovery safely cancel stale unpublished turns and clear typing state.

Agent settings provide Quiet, Balanced, Talkative, and Facilitator presets while keeping the stored numeric participation, question, delay, cooldown, and rolling-limit values authoritative. Direct mentions, `/ask`, and manager manual replies bypass automatic probability and fatigue limits but never bypass availability or access rules.

## Optional Clear Chat

Version 1.2.0 adds an opt-in **Enable Clear Chat** setting to each room. It is disabled for all existing and new rooms until a manager explicitly enables it. Authorized room managers then receive a keyboard-accessible **Clear Chat** action that hides the shared transcript for everyone and resets Independent and Shared Brain conversation context.

Clearing advances one room-level event cutoff and emits one durable `room_clear` synchronization event. Earlier events, messages, jobs, Brain runs, usage, search rows, restrictions, reads, presence, and maintenance records remain preserved for audit, retention, and recovery. Pending work from cleared triggers is canceled, running results are discarded after provider-reported usage is accounted, and the system never hard-deletes messages as part of Clear Chat.

## Shortcode

Use:

```text
[acl_agent_room id="123"]
```

The shortcode renders the room UI for logged-in users with access. Private and solo rooms require ownership, membership, or full room management capability.

Version 0.9.0 adds session-aware room presence, honest active/idle/away states, private participant projections, durable agent activity state, per-room pause/resume and auto-reply mute controls, pending-job cancellation, and dispatch-time participation enforcement. Presence uses per-tab identifiers in `sessionStorage`; only room/user-scoped SHA-256 hashes are persisted.

Version 0.5.0 loads the transcript from the normalized event endpoint using signed cursor pagination. The current visual design remains in place while a browser-native event store handles incremental polling, deduplication, and older-history loading. New messages and manual replies continue through the legacy write routes, followed by immediate event catch-up.

Version 0.8.0 adds a centralized command registry, server-authoritative dice and coin events, room actions, durable private human whispers, command suggestions/history, and compact room-play controls while preserving Phase 3–6 transport, interaction, read-state, and AOL UI guarantees.

Manual rooms show a **Generate Reply** action for each assigned agent after a user message is sent. Auto-answer rooms trigger assigned agents after user messages only.

Agent avatars use WordPress Media Library image attachments. Agent Rooms stores the attachment ID and resolves a safe thumbnail URL for admin and frontend display. Media attachments are not deleted when Agent Rooms is uninstalled.

## Switchboard Integration

Agent response jobs call ACL Switchboard through the public `acl_switchboard_chat()` helper when Switchboard is active and ready. Provider API keys remain in Switchboard. Agent Rooms sends provider/model identifiers, prompt messages, and generation settings server-side only.

## Phase 2 Reliability Guarantees

- Browser message submissions include a durable client request ID. Repeating the same ID for the same room and user reuses the original message and jobs.
- Every new agent response has a unique originating job ID. A retry recovers an already-saved response instead of inserting another reply.
- Running jobs use expiring leases, failed jobs follow a bounded three-attempt retry policy, and scheduled workers can recover expired leases and retryable failures.
- Human messages are limited to 12,000 Unicode characters and 48 KiB encoded payload size. The character limit is filterable with `acl_ar_message_character_limit`.
- Agent execution has separate authorization and throttling through `AgentExecutionPolicy` and the `acl_ar_can_execute_agent` filter.
- Public REST job objects exclude raw errors, request payloads, provider diagnostics, credentials, and lock data.
- Room-agent replacement and room deletion use checked InnoDB transactions with rollback on failure.

## Regression Tests

See `tests/README.md`. The lightweight Local harness uses uniquely named fixtures, always cleans them up, and injects a deterministic fake Switchboard client so it never makes a paid provider call.

## Phase 3 Normalized Room Events

Version 0.4.0 adds an internal normalized event foundation while preserving legacy messages as the current REST and shortcode compatibility source.

- `acl_ar_events` stores canonical room ordering by event ID, normalized actor/audience fields, legacy-message links, job links, metadata, and deterministic idempotency keys.
- `acl_ar_event_reactions`, `acl_ar_room_reads`, and `acl_ar_room_presence` are structural tables reserved for later phases; no reaction, unread, or presence behavior is exposed yet.
- `RoomEventService` is the canonical event validator/creator. Legacy messages are adapted through `LegacyMessageEventAdapter`.
- `EventBackfillService` processes missing legacy-message events in bounded batches, records progress/status options, and uses unique database indexes as the concurrency boundary.
- Current user/system messages and agent responses dual-write normalized message events. Agent jobs also emit queued, thinking, responding, completed, and failed lifecycle events.
- Existing `/messages` routes still read legacy messages. No public event REST route exists in this version.

The integration can also be replaced through:

- `acl_ar_switchboard_client`
- `acl_ar_switchboard_request`
- `acl_ar_switchboard_response`
- `acl_ar_agent_context_messages`
- `acl_ar_before_agent_job`
- `acl_ar_after_agent_job`

If Switchboard is not configured, agent response jobs fail safely without storing provider credentials in Agent Rooms.

## Current Limits

- REST polling is used; streaming is not implemented.
- Agent jobs can run inline for the MVP and also have Action Scheduler/WP-Cron-compatible queue hooks.
- Agent-to-agent autonomous triggering and streaming Brain output are not implemented.
- Public anonymous rooms are not implemented.
- Image, audio, file upload, memory, and paid access features are not implemented.
- Data is not deleted on uninstall unless explicitly opted in through settings, `ACL_AR_DELETE_DATA_ON_UNINSTALL`, or the `acl_ar_delete_data_on_uninstall` filter.
