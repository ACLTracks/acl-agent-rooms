# Changelog

## 1.5.0 - 2026-08-20

- Added a narrow, documented, versioned extension API for separately distributed add-ons.
- Added lifecycle hooks for add-on initialization, admin submenu registration, and REST controller registration.
- Added WordPress privacy-policy guidance describing local storage and the exact ACL Switchboard/provider transmission boundary.
- Added one restrained Pro information section on the Agent Rooms settings page; no link is rendered until an official URL is configured.
- Omitted local `default` provider/model sentinel values from Switchboard requests so the companion plugin can apply its approved fallback routing.
- Preserved every existing Free feature, all 21 core tables, and the 1.4.1 database version with no schema migration.
- Prepared the WordPress.org readme with current license, compatibility, privacy, external-service, uninstall, and Free/Pro disclosures.

## 1.4.1 - 2026-07-18

- Added text-safe optimistic human-message rows with restrained sending/failed states, same-nonce retry, composer preservation on failure, and canonical reconciliation whether the POST response or polling arrives first.
- Added an atomic human message, canonical event, and sender read-boundary commit before any downstream orchestration begins.
- Moved Independent execution, Shared Brain execution, Natural Conversation provider work, prompt construction, project-file retrieval, and ACL Switchboard calls off the human-message request path while preserving the existing REST/event pipeline.
- Added idempotent WP-Cron/Action Scheduler dispatch, bounded manager health diagnostics for post-persistence scheduling failures, and safe ordinary-user orchestration status.
- Added controlled no-cost slow/failing provider coverage, full regression and browser validation targets, packaging checks, and an exact 1.4.0 upgrade path while leaving ACL Switchboard unchanged.

## 1.4.0 - 2026-07-17

- Added disabled-by-default Project Context settings and durable room-to-ACL-Storage asset associations with active-version lineage, room labels, priority, context eligibility, hashes, and extraction/index status.
- Added ACL Storage 0.6.0 compatibility detection, graceful no-storage behavior, manager upload/attach/replace/remove actions, explicit storage deletion, safe private viewers/downloads, health diagnostics, and maintenance reconciliation.
- Added bounded text/code extraction and deterministic lexical retrieval with manual, automatic, and hybrid modes, per-room budgets, line-aware citations, and an explicit untrusted-file prompt boundary for Independent agents and Shared Brains.
- Added next-request selection of existing project files in the live room without adding composer uploads; Clear Chat preserves project resources and room deletion preserves underlying ACL Storage assets.
- Added no-cost PHP, JavaScript, storage-contract, security, permalink, package, upgrade, browser, and graceful-degradation validation while leaving ACL Switchboard unchanged.

## 1.3.2 - 2026-07-16

- Extended the guarded installer-only schema normalizer to the four confirmed legacy Clear Chat and Shared Brain column chains in rooms, agents, messages, and usage.
- Preserved installed definitions, values, indexes, engines, collations, and unrelated column order while converging every supported fresh and upgrade path on the canonical schema.
- Kept the 1.3.1 Natural Conversation normalization and all runtime, REST, event-stream, UI, Shared Brain, and Clear Chat behavior unchanged.
- Added real temporary-table coverage for canonical, legacy, partial, missing-column, data-preservation, idempotency, nonstandard-prefix, and failed-version-advance cases.

## 1.3.1 - 2026-07-15

- Added one guarded installer-layer migration that inspects `information_schema.COLUMNS` and moves only out-of-order Natural Conversation columns into the canonical fresh-install order.
- Preserved installed column types, unsigned attributes, nullability, defaults, character sets, collations, comments, indexes, table engines, and stored values while normalizing order.
- Prevented the stored database version from advancing when schema creation or order normalization fails, with safe code-only diagnostics.
- Added focused no-cost schema-normalization regression coverage and packaged fresh/upgrade fingerprint gates without changing Natural Conversation runtime behavior.

## 1.3.0 - 2026-07-15

- Added opt-in room-level Immediate/Natural conversation styles while preserving 1.2.0 behavior for every migrated and newly created room by default.
- Added a local Natural Conversation Director with explicit-target overrides, optional silence, responder-count selection, participation chance, role weighting, cooldowns, rolling automatic-response limits, recent-speaker fatigue, bounded injectable randomness, and no paid director call.
- Added durable staggered conversation turns, atomic publication, per-agent typing projection, supersession, Clear Chat integration, stale-output discard, health metrics, and maintenance recovery.
- Added the Natural Shared Brain `turns` contract with strict full-response validation, distinct-contribution instructions, one request/usage/audit event per Brain group, and separate server-controlled publication times without per-agent jobs or lifecycle chains.
- Added Natural scheduling for Independent jobs, manager room and agent controls, role presets, paired-field validation, safe REST convergence, and pending-content privacy.
- Added deterministic no-cost PHP and JavaScript suites covering schema, director rules, scheduling, publication, cancellation, typing, privacy, UI compatibility, and existing regressions.

## 1.2.0 - 2026-07-14

- Added per-room, disabled-by-default **Enable Clear Chat** configuration and a manager-only accessible confirmation workflow.
- Added an additive room cutoff schema, transactional idempotent clear service, durable non-transcript `room_clear` synchronization events, nonce/capability enforcement, rate limiting, and private/no-store REST responses.
- Applied the cutoff to history, signed cursors, search, context, replies, edits, reactions, moderation, read state, Independent prompts, Shared Brain prompts, manual replies, mentions, `/ask`, commands, and recovery paths without deleting underlying records.
- Added pending-work cancellation and running-result discard policies for Independent jobs and Shared Brain runs, with provider-reported usage retained exactly once where applicable.
- Added no-cost PHP and JavaScript regression coverage for authorization, idempotency, races, live client convergence, plain/pretty REST URL composition, accessibility, retention preservation, health checks, migration, packaging, and upgrades.

## 1.1.0 - 2026-07-14

- Added durable Shared Brain entities, agent execution modes, Brain runs, leases, bounded retries, crash recovery, and idempotent transactional response fan-out.
- Grouped same-Brain agents into one Switchboard request while retaining distinct names, avatars, descriptions, persona prompts, ordering, and ordinary transcript behavior.
- Added strict JSON response validation, one-record Brain usage accounting, one moderator-only terminal audit event, and grouped agent-state projection without individual jobs or lifecycle chains.
- Added Brain wp-admin and REST management, manager diagnostics, multi-agent `/ask`, disabled/paused safety, and explicit no-fallback behavior.
- Added no-cost PHP and JavaScript regression coverage plus clean-install, upgrade, permalink, browser, package, and cleanup validation.

## 1.0.3 - 2026-07-14

- Wired manager participant actions for mute, unmute, ban, and unban through the existing moderation REST API.
- Wired confirmed moderator message removal into transcript actions and authoritative event refresh.
- Corrected participant identity-label precedence so only the authenticated user is labeled `You`.
- Added minimal manager-only moderation state projections without schema or route changes.
- Preserved private, non-searchable whispers and added focused 1.0.3 regression coverage.

## 1.0.2 - 2026-07-13

- Added one centralized, query-safe frontend REST URL composer for pretty and plain WordPress permalink forms.
- Routed room event, message, interaction, command, presence, participant, search, context, and moderation requests through the composer.
- Preserved existing REST base parameters and opaque cursors while preventing endpoint paths or query parameters from replacing the REST origin or route.
- Added deterministic permalink and request-matrix regressions; the database schema remains identical to 1.0.1 and 1.0.0.

## 1.0.1 - 2026-07-13

- Moved Agents, Rooms, and Settings mutations to page-specific pre-render load hooks so redirects are sent before wp-admin output.
- Corrected the 13-field room insert format map so response modes persist as strings.
- Deferred recurring-event scheduling and translated cron-label evaluation until `init`, while preserving safe activation and deactivation behavior.
- Added focused release-blocker regression coverage; the database schema remains identical to 1.0.0.

## 1.0.0 - 2026-07-12

- Added durable room mutes/bans, protected moderation targets, and idempotent moderator message removal.
- Added visibility-safe local room search, signed user/query/room-bound cursors, and context retrieval.
- Added conservative archived-room retention, bounded recorded maintenance, and private health diagnostics.
- Added accessibility, upgrade/rollback, packaging, and Phase 9 release verification coverage.
- Finalized search edit/backfill reconciliation, hidden-target context protection, transactional moderation, moderation-removal rate limiting, search-panel keyboard/focus behavior, and Phase 9 cron cleanup on deactivation.

## 0.9.0 - 2026-07-12

- Added per-tab, room-scoped human presence sessions with active, idle, away, offline, and bounded recently-active projections.
- Added private participants, heartbeat, session-delete, and manager-only agent participation REST contracts.
- Added durable pause/resume and automatic-reply mute policies, terminal canceled jobs, dispatch-time enforcement, and deterministic participation events.
- Added lifecycle-event-ordered agent activity projections, stale-state reconciliation, AOL participant controls, and no-dependency Phase 8 regression suites.

## 0.8.0 - 2026-07-12

- Added centralized command parsing and execution for help, agents, ask, roll, coin, me, whisper, and w.
- Added secure server-side dice and coin outcomes, action events, private whisper visibility, projection, and unread semantics.
- Added command autocomplete/history and compact AOL-style dice, coin, and whisper interfaces without schema changes.

## 0.7.0 - 2026-07-11

- Added canonical message replies through `parent_event_id` with safe batched parent previews.
- Added immutable `message_edit` events with transactional legacy message reconciliation.
- Added allowlisted current-state reactions plus normalized reaction mutation events.
- Added persistent monotonic read state, visible-only unread counts, and unread UI.
- Added flat transcript actions for reply, quote, edit, reaction, and plain-text copying.
- Preserved cursor transport, legacy routes, schema, AOL styling, and Switchboard isolation.

## 0.6.0 - 2026-07-11

- Replaced the shortcode presentation with a production AOL 2000-inspired room shell.
- Added flat transcript lines, deterministic accessible screen-name colors, participant projection, and lifecycle-driven agent states.
- Added accessible member and room dialogs, emoji/symbol insertion, multiline keyboard behavior, and scoped room shortcuts.
- Added versioned local interface preferences, high-contrast/large-text/compact/focused modes, and opt-in local Web Audio tones.
- Added responsive desktop member list and tablet/mobile participant drawer without changing event transport or database tables.

## 0.5.0 - 2026-07-11

- Added `GET /acl-agent-rooms/v1/rooms/{id}/events` with signed opaque before/after cursors.
- Centralized room-event audience visibility and sanitized public projection with batched actor loading.
- Added private ETag revalidation without public caching.
- Added a browser-native event store, non-overlapping adaptive polling, history prepending, and legacy send catch-up.
- Kept lifecycle events in client state while hiding them from the compatibility transcript.
- Added Phase 4 PHP and dependency-free JavaScript regression harnesses.

## 0.4.0 - 2026-07-11

- Added normalized room-event, reaction, room-read, and room-presence tables without replacing legacy messages or jobs.
- Added strict `RoomEvent`, `EventRepository`, `RoomEventService`, and `LegacyMessageEventAdapter` contracts.
- Added bounded, resumable, idempotent legacy-message and job-lifecycle backfill with stored progress/status.
- Added message-event dual-write and reconciliation for user messages, system notices, and agent responses.
- Added deterministic queued, thinking, responding, completed, and failed agent lifecycle events.
- Extended transactional room deletion and destructive uninstall cleanup to all new room-owned tables and backfill options.
- Added Phase 3 integration coverage while retaining the complete Phase 2 regression suite.

## 0.3.0 - 2026-07-11

- Added durable user-message idempotency scoped by room, user, and client request ID.
- Added unique agent-response linkage to originating jobs and crash-after-response reconciliation.
- Replaced permanent running locks with expiring leases and bounded retry/stale-job recovery.
- Added centralized job retry, public-error, public-job, message, and agent-execution policies.
- Added 12,000-character and 48 KiB hard message limits plus byte-bounded agent context.
- Added separate authorization and rate limiting for provider-costing actions.
- Made room-agent replacement and room deletion checked, transactional, and rollback-safe on InnoDB.
- Added composite polling, latest-user-message, worker, lease, and idempotency indexes.
- Added a no-Composer Local integration harness with deterministic fake provider execution and cleanup.
- Updated plugin/database versions and the WordPress stable tag to 0.3.0.

## 0.2.2 - 2026-07-05

- Added optional Media Library avatar attachment IDs for agents.
- Added avatar picker, preview, and removal controls to the agent editor.
- Added safe avatar display fields to agent, room, and message REST responses.
- Render agent avatars or initials fallbacks in room assignment UI and frontend agent messages.
- Confirmed uninstall does not delete Media Library attachments.

## 0.2.1 - 2026-07-05

- Added job request keys to prevent duplicate manual or auto agent jobs for the same trigger message and agent.
- Hardened Switchboard availability/error handling so inactive or unhealthy Switchboard states do not fatal.
- Redacted common secret patterns from provider failure messages returned through Agent Rooms.
- Updated dashboard setup guidance for shared brain configs and manual/auto room modes.

## 0.2.0 - 2026-07-05

- Added shared brain configs for shared provider/model/master-prompt settings across agents.
- Added independent/shared AI config mode to agents while preserving per-agent identity metadata.
- Added room description, top chat text/context, status, and manual/auto response mode handling.
- Added prompt assembly that includes room context, room instructions, agent identity, recent history, and the effective master prompt before calling Switchboard.
- Added a secure manual agent reply REST action and front-end Generate Reply buttons.
- Kept provider calls server-side through ACL Switchboard and avoided storing provider API keys in Agent Rooms.

## 0.1.0 - 2026-06-30

- Added the first working ACL Agent Rooms MVP for WordPress.
- Added agent, room, message, job, usage, and membership database tables.
- Added admin screens for Settings, Agents, and Rooms.
- Added a first-run dashboard with Switchboard discovery status and setup steps.
- Added provider-aware model filtering and a custom model override.
- Added shortcode rendering for logged-in room users.
- Added front-end REST message posting and polling.
- Added inline agent response execution through ACL Switchboard.
- Added clearer admin empty states, shortcode copy controls, and front-end loading/error states.
