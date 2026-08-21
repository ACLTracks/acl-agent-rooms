# ACL Agent Rooms 1.4.1 send-responsiveness root cause

The approved 1.4.0 request path persisted the human message and normalized event in `MessagesController::create()`, then continued into agent orchestration before constructing the REST response.

- `includes/Rest/MessagesController.php` 1.4.0 lines 264-273 created the message and canonical event.
- Lines 291-302 planned Natural Conversation turns and created Shared Brain runs and Independent jobs.
- Line 300 selected inline execution by default, and forced it on for Natural Conversation.
- Lines 303-318 called `AgentRuntime::run_job()` inside the human-message request.
- `BrainRunService::create_for_targets()` called `BrainRuntime::run()` when its inline flag was true.
- Both runtimes build prompts, including bounded Room Project File retrieval, and call ACL Switchboard before returning.
- The REST response was not created until lines 343-353, after those calls completed.

The database was not held open during the provider call. Message/event writes autocommitted before dispatch in 1.4.0, so the delay was synchronous backend execution rather than a transaction lock.

The 1.4.0 frontend added a second visibility delay. `assets/js/room.js` waited for the full `api.send()` promise and only then called `sync.immediate()`. No optimistic event was added to the store or transcript. Polling already deduplicated canonical numeric event IDs, and stale-cursor recovery was present, but neither could reconcile a client-local message because no local representation existed.

Room Project File selection validation read only association/availability metadata before persistence. Actual extraction/retrieval occurred later in `PromptBuilder` or `BrainPromptBuilder`, but because those builders ran inside the inline runtimes, file-context preparation also extended the response path.

The 1.4.1 repair therefore makes the message/event/read-boundary write atomic, commits it before downstream dispatch, schedules every provider-bearing runtime asynchronously, returns the safely projected canonical event, and reconciles it with an optimistic client event keyed by the existing client request ID.
