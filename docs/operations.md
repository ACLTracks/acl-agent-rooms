# Operations

Natural Conversation adds a one-minute recovery worker plus exact due-time scheduling through Action Scheduler where available or WP-Cron fallback. Safe maintenance scans overdue pending turns, stale typing/publishing locks, superseded or cleared triggers, unavailable rooms, and disabled or paused agents. Publication is idempotent; a duplicate worker cannot republish a terminal turn.

Health includes pending, overdue, stale typing, failed, and canceled turn counts; rooms over their pending limits; and missing room, agent, Brain-run, or job references. These diagnostics never include scheduled response content. Typing state is reconstructed from active durable turns and is cleared on publication, cancellation, or failure.

The Health endpoint reports plugin/database versions, bounded table counts including Brains, Brain runs, and usage, a Brain-run status distribution, Brain usage counted once per run, restriction count, cron presence, retention configuration, and the latest maintenance record. It never reports message content, prompts, validated response text, raw provider data, or secrets.

The existing five-minute pending worker now processes eligible Brain runs as well as individual jobs. Brain leases expire after three minutes, attempts are bounded at three, stale runs are recoverable, and `response_saved` runs resume fan-out without a new provider request. Disabling a Brain cancels pending runs; runtime validation prevents unavailable Brains from dispatching or falling back.

Search backfill, event backfill, presence cleanup, and retention remain bounded. Brain answers are ordinary message events and therefore retain existing search, reply, reaction, moderation, unread, and retention behavior. The moderator-only `brain_run` audit event is non-transcript and non-unread.

Clear Chat is disabled per room by default. When enabled, only a room manager with current read access can call the nonce-protected, rate-limited clear route. Health reports invalid or impossible cutoffs, informational missing clear actors, failed operations, and duplicate retries without exposing content. A valid cutoff must not be reset. Existing retention remains authoritative and may later expire preserved content under its normal archived-room policy.

Pending Independent jobs and Brain runs whose triggers are at or below a new cutoff are canceled. A provider request already in flight may finish; its transcript output is discarded and only provider-reported usage is recorded. New post-clear messages and runs proceed normally.

Operational checks for 1.3.1 should confirm the database version advanced only after canonical Natural column-order verification, the conversation-turn hook is scheduled, no active room exceeds its configured pending limit, overdue/stale counts are zero after recovery, and every published turn has a published event ID. Natural test and browser fixtures must use the controlled no-cost Switchboard seam.
