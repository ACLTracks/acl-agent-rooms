=== ACL Agent Rooms ===
Contributors: acl
Tags: artificial intelligence, chat, collaboration, agents, automation
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 1.5.0
Requires PHP: 8.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build private, authenticated WordPress rooms where people and provider-routed AI agents collaborate.

== Description ==

ACL Agent Rooms is a complete room-based collaboration plugin for WordPress. Create rooms and agents, assign agents to rooms, and let authorized members exchange messages with provider-routed AI participants.

Free includes:

* Unlimited core rooms, agents, assignments, and messages with no trial or expiration.
* Authenticated room access, membership controls, and private-room behavior.
* Agent replies through ACL Switchboard, including manual, mention/slash, automatic, Shared Brain, and optional Natural Conversation modes.
* Durable normalized events, signed cursors, adaptive polling, history, replies, edits, reactions, read/unread state, and presence.
* Private whispers with audience-aware event visibility.
* Essential moderation, restrictions, redaction, search, context, retention safety, and recovery.
* Optional project-file context through a compatible ACL Storage installation.
* Keyboard access, semantic controls, focus management, responsive layouts, high contrast, large text, and reduced-motion support.

Provider credentials stay in ACL Switchboard. ACL Agent Rooms stores provider and model identifiers, but it does not store provider API keys.

An optional, separately installed ACL Agent Rooms Pro add-on provides advanced local operational reporting. Pro is not required for rooms, agents, messaging, AI replies, privacy, moderation, accessibility, or recovery. No premium implementation is bundled or locked inside this Free plugin.

== Installation ==

1. Install and activate ACL Agent Rooms.
2. Install, activate, and configure [ACL Switchboard](https://github.com/ACLTracks/acl-switchboard) for AI agent responses.
3. Open **ACL Agent Rooms > Settings** and confirm Switchboard availability.
4. Create an agent and choose its provider route and model.
5. Create a room, assign one or more agents, and copy its shortcode.
6. Add `[acl_agent_room id="123"]` to a page, replacing `123` with the room ID.

Logged-in users need the Agent Rooms use capability and room access. Private and solo rooms require ownership, membership, or room-management authority.

== External services and privacy ==

ACL Agent Rooms does not directly contact Patreon, an ACL license server, or an ACL tracking service. It does not download or execute remote PHP or JavaScript.

Agent responses use the separately installed ACL Switchboard plugin. When an agent reply is requested, Agent Rooms passes the following data to ACL Switchboard:

* the configured agent or Shared Brain instructions;
* agent names, slugs, descriptions, and relevant configuration;
* the room name, description, and persistent room context;
* the triggering room message and a bounded portion of visible room message history;
* selected project instructions and project-file excerpts when that feature is enabled;
* provider and model identifiers, generation settings, and local operational identifiers.

ACL Switchboard may then send that material to the AI provider chosen and configured by the site administrator. Shared Brain history labels participants with local numeric WordPress user IDs. Ordinary Independent-agent context sends message roles and content without display names. Private whispers are audience-restricted events and are not part of the legacy message history used for agent context.

ACL Switchboard is a separately installed GPL-compatible WordPress plugin available from its [official source repository](https://github.com/ACLTracks/acl-switchboard). Agent Rooms never downloads, installs, or updates Switchboard or Pro.

The terms, privacy policy, data location, and retention behavior depend on the AI providers enabled by the site administrator. Review and disclose the policies for every configured provider before allowing users to send sensitive content. Agent Rooms never reads or stores Switchboard provider credentials.

Agent Rooms stores rooms, memberships, messages, normalized events, jobs, usage, presence, reads, reactions, restrictions, search indexes, shared configurations, maintenance history, and optional project-file metadata in the WordPress database. A Privacy Policy Guide section is registered in WordPress admin.

== Frequently Asked Questions ==

= Does Free limit rooms, agents, messages, or time? =

No. Free has no artificial room, agent, message, usage, or time quota and no trial expiration.

= Is Pro required for AI replies? =

No. Free owns the complete room, agent, messaging, Switchboard, queue, recovery, and moderation runtime. Pro adds separate advanced operational reports.

= Where are provider API keys stored? =

Only in ACL Switchboard. Agent Rooms stores provider route and model identifiers, not provider credentials.

= Are whispers sent to AI providers? =

Whispers are stored as audience-restricted normalized events and are not included in the legacy room-message context used by Independent or Shared Brain prompts.

= What happens if ACL Switchboard is unavailable? =

Rooms and human messaging remain available. Agent execution fails safely until Switchboard is active and configured; credentials are never copied into Agent Rooms.

= What happens when the plugin is deleted? =

User-generated data is preserved by default. Destructive uninstall requires an explicit setting, the `ACL_AR_DELETE_DATA_ON_UNINSTALL` constant, or the `acl_ar_delete_data_on_uninstall` filter. Back up the database before enabling deletion.

== Changelog ==

= 1.5.0 =

* Added a documented extension API for separately distributed add-ons.
* Added WordPress privacy-policy guidance and complete external-service disclosure.
* Added one restrained, settings-only Pro information section with no link until an official URL is configured.
* Preserved every Free feature and the 1.4.1 database schema.

= 1.4.1 =

* Made accepted human messages visible immediately with text-safe optimistic rows and canonical event reconciliation.
* Committed the human message, normalized event, and sender read boundary before downstream AI orchestration.
* Preserved same-request idempotency for ambiguous retry paths.

== Upgrade Notice ==

= 1.5.0 =

No database migration is required. All existing rooms, agents, messages, events, searches, restrictions, usage, shared configurations, project files, and maintenance history remain owned by Free and are preserved.
