# Rollback

For a 1.1.0 release blocker, deactivate 1.1.0, restore the approved 1.0.3 plugin ZIP, and reactivate. Do not drop Brain tables or columns during an emergency code rollback. Version 1.0.3 ignores them, preserving data for investigation and a later re-upgrade.

Before rollback, disable Brains to prevent new Brain runs and allow active work to reach a terminal state. Existing Brain-generated transcript messages remain ordinary messages and continue to render under 1.0.3. A code rollback does not convert Brain agents to Independent mode; restoring normal independent execution requires an intentional administrator reassignment after re-upgrade or a database restore approved for that purpose.

Rollback is code-only unless the release incident also requires restoring the pre-upgrade database backup. Never perform a destructive downgrade migration in place.

For a 1.2.0 rollback, enter maintenance mode or explicitly accept a transcript-visibility risk before activating older code. Releases before 1.2.0 do not understand `cleared_through_event_id` and may display or reuse history that users cleared under 1.2.0. Preserve the additive columns and all records; do not reset cutoffs or perform a destructive downgrade. The safest rollback restores both the approved older plugin and its matching pre-upgrade database backup.

For a 1.3.0 rollback, first pause message generation and allow or cancel active Natural turns. Restoring 1.2.0 code ignores the additive Natural fields and table, but it does not understand delayed work: remove scheduled 1.3.0 turn hooks and ensure no unpublished Natural turns remain before activation. Preserve the conversation-turn table for investigation; do not drop columns or rewrite room settings. Existing rooms that were switched to Natural should be intentionally set to Immediate before rollback. The safest rollback restores the approved 1.2.0 plugin and matching pre-upgrade database backup.

For a 1.3.1 rollback, restore the preserved 1.3.0 plugin and its matching pre-upgrade database backup. Version 1.3.1 changes only Natural column order, so a code-only rollback can read the normalized schema, but restoring the matching database is the definitive rollback. Do not manually reorder columns, reset Natural settings, drop the conversation-turn table, or copy rows into replacement tables.

For a 1.3.2 rollback, restore the preserved 1.3.1 plugin and its matching pre-upgrade database backup. Version 1.3.2 changes only the canonical order of the documented legacy Clear Chat and Shared Brain column chains. Older code can read that normalized schema, but the matching database restore is the definitive rollback. Do not manually reverse column order, reset settings, drop tables or columns, or copy rows into replacement tables.
