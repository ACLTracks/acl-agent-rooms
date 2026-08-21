# ACL Agent Rooms Free and Pro boundary

## Ownership decision

No 1.4.1 feature was removed from Free. Existing installations retain all functionality and all data. Pro 1.0.0 adds a separate operations-intelligence console over Free-owned reporting primitives.

| Feature | Current implementation | Free | Pro | Extension point | Migration impact | Reason |
| --- | --- | --- | --- | --- | --- | --- |
| Rooms, agents, assignments, membership | Core repositories, admin, REST | Owns | Uses summaries | Reporting tables | None | Complete product foundation |
| Messages, events, replies, edits, reactions, unread | Core event runtime | Owns | Aggregate reporting only | Reporting tables | None | Core room experience |
| Presence and whispers | Core privacy/runtime services | Owns | No runtime change | None | None | Privacy and usability cannot be premium |
| Switchboard execution | Core client, jobs, Brain runs | Owns | Reliability reports | Job/Brain reporting tables | None | One authoritative provider/queue boundary |
| Shared Brains and shared configs | Core admin/runtime | Owns | Usage slices | Usage reporting table | None | Existing users keep approved functionality |
| Natural Conversation | Core director/turn worker | Owns | Reliability reporting | Job/Brain reporting tables | None | Existing orchestration remains complete |
| Project files | Core ACL Storage bridge | Owns | No runtime change | None | None | Existing project workflow remains complete |
| Search and context | Core indexed event services | Owns | No message-content report | None | None | Room history remains useful in Free |
| Essential and advanced moderation actions | Core permission/services | Owns | Read-only moderation history | Admin/REST lifecycle plus restrictions reporting table | None | Pro adds audit convenience without weakening safety |
| Usage bookkeeping | Core runtime table | Owns | Advanced room/agent/model/cost breakdowns | Usage reporting table | None | Runtime accounting remains core; intelligence is premium |
| Retention and maintenance | One core worker and settings | Owns | Read-only policy and run history | Maintenance reporting table | None | No competing cron or destructive Pro action |

## Data policy

Free continues to own all 21 existing tables and every `acl_ar_*` option. Pro 1.0.0 creates no table, option, cron event, queue worker, or user metadata. Deactivating or deleting Pro therefore cannot remove rooms, agents, messages, events, presence, restrictions, search rows, shared configurations, usage, project files, or maintenance history. Reinstalling Pro immediately reports the preserved Free data.

## Dependency states

- Free only: all existing product behavior remains active.
- Pro only: no runtime service or route is registered; one administrator notice explains the dependency.
- Free plus Pro: Pro attaches once through the public lifecycle hooks.
- Pro deactivated: Free behavior is unchanged.
- Free deactivated while Pro remains active: Pro is dormant on the next request and creates no partial state.

Free and Pro have independent plugin versions. The Free database version remains 1.4.1 because the split introduces no schema change. Pro has no database version because it owns no schema.
