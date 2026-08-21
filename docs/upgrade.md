# Upgrade to 1.3.2

1. Back up the plugin directory and database.
2. Replace plugin files with the 1.3.2 distribution.
3. Load WordPress once; `Installer::maybe_upgrade()` adds missing additive columns, runs the unchanged Natural Conversation order normalizer, then normalizes only the documented Clear Chat and Shared Brain column chains in rooms, agents, messages, and usage. It stores database version 1.3.2 only after every guarded move succeeds.
4. Confirm every pre-existing room has `conversation_mode=immediate`; no room is opted into Natural automatically.
5. Confirm existing agent prompts, provider/model values, execution modes, Brain assignments, rooms, events, Clear Chat cutoffs, and usage remain unchanged.
6. Configure Natural only on selected rooms, then review responder, silence, delay, pending, steering, per-agent role, cooldown, and rolling-limit settings.
7. Confirm Health reports zero invalid references and overdue/stale turns after recovery, and both pending-job and conversation-turn workers are scheduled.

The migration is additive and idempotent, preserves exact installed definitions and values, and is safe to run repeatedly from 1.3.1, 1.3.0, 1.2.0, 1.1.0, 1.0.3, or 0.9.0. Existing agents receive safe numeric defaults without erasing runtime configuration. Upgrade enables no room automatically and makes no provider request.
