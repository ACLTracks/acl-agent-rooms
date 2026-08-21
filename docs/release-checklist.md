# Release checklist

- Versions and stable tag are 1.3.1; fresh, 1.3.0, 1.2.0, 1.1.0, 1.0.3, and 0.9.0 packaged installer and migration-twice schema fingerprints match.
- Existing rooms remain Immediate; Natural rooms select locally before dispatch, create no silent-agent jobs/requests, and use durable ordered turns with bounded server timing.
- Direct mentions, `/ask`, and manual replies override probability/fatigue but preserve access, pause, disablement, assignment, and policy enforcement.
- Natural Shared Brain groups produce one run/request/usage/audit event, separate transcript due times, zero individual jobs/lifecycle chains, and no second publication-time provider call.
- Supersession, Clear Chat, partial publication, typing reconstruction/cleanup, cooldown, rolling limits, health, and maintenance recovery are verified.
- Clear Chat is disabled by default, manager-only when enabled, and advances one logical cutoff with one durable `room_clear` event and no record deletion.
- History, search, context, interactions, unread state, Independent prompts, Brain prompts, commands, pending work, running-result races, live synchronization, plain/pretty routes, accessibility, and cleanup are verified.
- Brain/Brain-run schemas, indexes, agent fields, message attribution, and usage attribution match the 1.1.0 contract.
- Existing agents migrate to Independent without losing provider/model configuration.
- One same-Brain group produces one run, one fake Switchboard request, one usage row, one terminal audit event, normal per-agent messages, zero individual jobs, and zero lifecycle chains.
- Invalid output, disablement, pauses, retries, stale leases, saved-response recovery, multi-agent `/ask`, mixed grouping, and no fallback are verified without a paid request.
- All retained PHP and JavaScript suites, PHP lint, and JavaScript syntax checks pass.
- Pretty and plain permalink browser paths, wp-admin Brain/agent UI, transcript behavior, accessibility modes, console, and PHP log pass.
- Clean portable ZIP install and upgrades from approved 1.2.0, 1.1.0, 1.0.3, and 0.9.0 pass; installer rerun does not change the schema fingerprint.
- Release ZIP excludes tests, backups, logs, caches, fixtures, and development metadata.
- ZIP checksum, file manifest, version manifest, verification report, duplicate/orphan checks, cleanup proof, and Switchboard inventory comparison are present.
