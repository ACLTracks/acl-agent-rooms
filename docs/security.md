# Security

Brain management requires the manage-agents capability; REST mutations also require a valid WordPress REST nonce and formal argument validation. Provider/model identifiers are validated against Switchboard discovery at save time and runtime. Agent Rooms never reads or stores provider credentials.

Brain prompts are built server-side with strong instruction/data delimiters. Administrator-authored Brain and persona prompts are trusted; room-member content is treated as untrusted. The model must return one JSON object containing exactly one plain-text response for every requested agent. Duplicate, missing, unknown, empty, HTML, oversized, or prose-wrapped results fail the whole run with no partial transcript write and no repair call.

Brain tables do not store combined prompts, raw request payloads, raw provider responses, authorization headers, hidden room data, credentials, or chain-of-thought. Ordinary users see normal agent messages only. Brain run diagnostics and the terminal audit event are manager/moderator scoped and expose only allowlisted operational fields.

Clear Chat requires authentication, a valid REST nonce, existing room-management authority, current room read access, an enabled per-room setting, formal confirmation/idempotency arguments, and a room-scoped rate limit. The server locks and revalidates the room; UI visibility is never treated as authorization. Public clear responses and events expose only room/cutoff/event identifiers, never removed content, moderation notes, credentials, prompts, or the last clearing actor.
