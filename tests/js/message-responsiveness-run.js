'use strict';

const fs = require('fs');
const path = require('path');
const Store = require('../../assets/js/room/store.js');
const Transcript = require('../../assets/js/room/transcript.js');

let assertions = 0;
function ok(value, message) { assertions += 1; if (!value) { throw new Error(message); } }
function same(expected, actual, message) { assertions += 1; if (JSON.stringify(expected) !== JSON.stringify(actual)) { throw new Error(message + ' expected=' + JSON.stringify(expected) + ' actual=' + JSON.stringify(actual)); } }

class Classes {
	constructor(node) { this.node = node; }
	add(name) { if (!this.contains(name)) { this.node.className += (this.node.className ? ' ' : '') + name; } }
	remove(name) { this.node.className = this.node.className.split(/\s+/).filter(item => item && item !== name).join(' '); }
	contains(name) { return this.node.className.split(/\s+/).includes(name); }
}
class Node {
	constructor(tag) { this.tag = tag; this.children = []; this.parentNode = null; this.dataset = {}; this.attributes = {}; this.className = ''; this.classList = new Classes(this); this.style = {setProperty() {}}; this.textContent = ''; this.hidden = false; this.scrollTop = 0; this.tabIndex = 0; }
	get scrollHeight() { return this.children.length * 20; }
	get firstChild() { return this.children[0] || null; }
	appendChild(node) { if (node.tag === '#fragment') { node.children.slice().forEach(child => this.appendChild(child)); return node; } node.parentNode = this; this.children.push(node); return node; }
	insertBefore(node, before) { if (node.tag === '#fragment') { node.children.slice().forEach(child => this.insertBefore(child, before)); return node; } node.parentNode = this; const at = this.children.indexOf(before); if (at < 0) { this.children.push(node); } else { this.children.splice(at, 0, node); } return node; }
	replaceChild(node, old) { const at = this.children.indexOf(old); if (at >= 0) { this.children[at] = node; node.parentNode = this; old.parentNode = null; } return old; }
	remove() { if (this.parentNode) { this.parentNode.children = this.parentNode.children.filter(child => child !== this); this.parentNode = null; } }
	setAttribute(name, value) { this.attributes[name] = String(value); }
	addEventListener() {}
	querySelectorAll(selector) { return selector === '.acl-ar-message' ? this.children.filter(node => node.classList.contains('acl-ar-message')) : []; }
}
class Document {
	createElement(tag) { return new Node(tag); }
	createTextNode(text) { const node = new Node('#text'); node.textContent = String(text); return node; }
	createDocumentFragment() { return new Node('#fragment'); }
}
function canonical(id, content, createdAt) {
	return {id, room_id: 7, type: 'message', actor: {type: 'user', id: 9, name: 'Sender', avatar_url: null}, content, created_at: createdAt, reactions: [], moderation: {can_remove: true}};
}

function run() {
	const store = new Store(7, 9);
	const doc = new Document();
	const list = new Node('section');
	const transcript = new Transcript(list, 9, doc);
	const nonce = 'client-nonce-0001';
	const pending = store.addOptimistic(nonce, '<script>safe text</script>', 'Sender', null);
	transcript.append([pending], true);

	same(1, store.messages().length, 'Optimistic message did not enter the local transcript immediately.');
	ok(String(store.messages()[0].id).startsWith('pending:'), 'Optimistic message did not use a local-only ID.');
	same('sending', store.messages()[0].pending_state, 'Optimistic message did not start in sending state.');
	ok(list.children[0].classList.contains('acl-ar-chat-line--pending-sending'), 'Pending message is not visually identified.');
	ok(list.children[0].children.some(node => node.textContent === '<script>safe text</script>'), 'Optimistic content was not rendered as text.');

	const failed = store.markOptimisticFailed(nonce);
	transcript.refresh(failed);
	same('failed', store.messages()[0].pending_state, 'Failed send did not retain the local message.');
	ok(list.children[0].classList.contains('acl-ar-chat-line--pending-failed'), 'Failed message lacks a visible failure state.');
	ok(list.children[0].children.some(node => node.dataset.pendingRetry === nonce), 'Failed message lacks a retry action.');
	same(nonce, store.markOptimisticSending(nonce).client_request_id, 'Retry changed the client nonce.');

	const event = canonical(44, 'canonical text', '2026-07-18T12:34:56Z');
	const reconciled = store.reconcileOptimistic(nonce, event);
	transcript.reconcileOptimistic(nonce, reconciled);
	same([44], store.ids, 'Canonical reconciliation did not replace the local ID.');
	same(1, store.messages().length, 'Canonical reconciliation duplicated the human message.');
	same('canonical text', store.messages()[0].content, 'Canonical reconciliation did not use server content.');
	same('2026-07-18T12:34:56Z', store.messages()[0].created_at, 'Canonical reconciliation did not use the server timestamp.');
	ok(!store.optimistic[nonce], 'Canonical reconciliation retained the optimistic record.');
	same(1, list.children.length, 'Canonical reconciliation left two rendered rows.');
	same('44', list.children[0].dataset.eventId, 'Rendered row did not adopt the canonical event ID.');

	store.incremental([event], {after_cursor: 'after-44'});
	transcript.append([store.events[44]], false);
	same(1, store.messages().length, 'Polling duplicated the reconciled canonical message in the store.');
	same(1, list.children.length, 'Polling duplicated the reconciled canonical message in the transcript.');

	const pollFirstStore = new Store(7, 9);
	const pollFirstList = new Node('section');
	const pollFirstTranscript = new Transcript(pollFirstList, 9, doc);
	pollFirstTranscript.append([pollFirstStore.addOptimistic(nonce, 'poll first', 'Sender', null)], true);
	const pollFirstEvent = Object.assign(canonical(45, 'poll first canonical', '2026-07-18T12:35:00Z'), {client_request_id: nonce});
	pollFirstStore.incremental([pollFirstEvent], {after_cursor: 'after-45'});
	const pollCanonical = pollFirstStore.reconcileOptimistic(nonce, pollFirstEvent);
	pollFirstTranscript.reconcileOptimistic(nonce, pollCanonical);
	pollFirstTranscript.append([pollFirstStore.events[45]], false);
	same(1, pollFirstStore.messages().length, 'Poll-first reconciliation left both optimistic and canonical messages.');
	same(1, pollFirstList.children.length, 'Poll-first reconciliation rendered duplicate rows.');
	same('45', pollFirstList.children[0].dataset.eventId, 'Poll-first reconciliation did not adopt the canonical event ID.');

	const reload = new Store(7, 9);
	reload.initial([event], {after_cursor: 'after-44'});
	same(1, reload.messages().length, 'Reload did not show exactly one canonical message.');
	ok(!reload.messages()[0].pending_state, 'Reload exposed an optimistic-only state.');

	const root = path.resolve(__dirname, '../..');
	const roomSource = fs.readFileSync(path.join(root, 'assets/js/room.js'), 'utf8');
	const statusSource = fs.readFileSync(path.join(root, 'assets/js/room/status-bar.js'), 'utf8');
	const apiSource = fs.readFileSync(path.join(root, 'assets/js/room/api.js'), 'utf8');
	const controllerSource = fs.readFileSync(path.join(root, 'includes/Rest/MessagesController.php'), 'utf8');
	const roomWorkSource = fs.readFileSync(path.join(root, 'includes/Rest/RoomWorkController.php'), 'utf8');
	const commandsSource = fs.readFileSync(path.join(root, 'includes/Rest/CommandsController.php'), 'utf8');
	const createPath = controllerSource.slice(controllerSource.indexOf('public function create'), controllerSource.indexOf('public function manual_reply('));
	const compactCreatePath = createPath.replace(/\s+/g, '');
	ok(roomSource.indexOf('store.addOptimistic') < roomSource.indexOf('api.send(content'), 'Frontend sends before optimistic rendering.');
	ok(roomSource.includes('markOptimisticFailed') && roomSource.includes('transcript.onRetry'), 'Frontend failure/retry wiring is missing.');
	ok(roomSource.includes('reconcileOptimistic') && roomSource.includes('body.event'), 'Frontend canonical reconciliation wiring is missing.');
	ok(roomSource.includes("e.type==='message'&&nonce&&store.optimistic[nonce]"), 'Polling cannot reconcile an optimistic row before the POST response arrives.');
	ok(!createPath.includes('run_job(') && !createPath.includes('->run('), 'Human-message endpoint still executes a provider-bearing runtime inline.');
	ok(createPath.includes('HumanMessageService') && createPath.indexOf('persist(') < createPath.indexOf('dispatch_after_persistence'), 'Dispatch does not follow committed human persistence.');
	ok(compactCreatePath.includes("'event'=>") && compactCreatePath.includes("'orchestration'=>"), 'Human-message response omits its canonical event or safe queue status.');
	ok(roomSource.includes("e.type==='agent_failed'") && roomSource.includes("e.type==='brain_run'"), 'Asynchronous agent or Shared Brain failures are not surfaced in the room UI.');
	ok(roomSource.includes("body.orchestration.status==='degraded'") && roomSource.includes('agent reply could not be queued'), 'A post-persistence queue failure still appears as silent success.');
	ok(roomSource.includes('delete stateNode.dataset.statusSource') && statusSource.includes("statusSource='connection'"), 'Connection recovery can erase an actionable asynchronous failure.');
	ok(apiSource.includes('Api.prototype.work') && apiSource.includes("'/rooms/' + encodeURIComponent(this.config.roomId) + '/work'"), 'The live room API cannot start its room-scoped worker.');
	ok(roomSource.includes('kickRoomWork(body)') && roomSource.includes("config.room.conversationMode==='natural'"), 'Accepted messages do not start bounded Immediate and Natural foreground work.');
	ok(roomSource.includes('if(body&&body.job)') && roomSource.includes('api.reply(agentId).then(function(body){kickRoomWork(body)'), 'Manual Independent and Natural replies do not enter foreground work.');
	ok(commandsSource.includes("'brain_runs'") && commandsSource.includes("'scheduled_turn_count'"), '/ask responses do not expose their Shared Brain or scheduled-turn work.');
	ok(roomSource.includes("if(!isInitial&&e.type==='agent_failed'") && roomSource.includes("if(!isInitial&&e.type==='brain_run'"), 'Historical failures are re-announced as current room errors on reload.');
	ok(roomWorkSource.includes("'/rooms/(?P<id>[\\d]+)/work'") && roomWorkSource.includes('verify_nonce') && roomWorkSource.includes('can_access_room'), 'The room work endpoint is missing its route, nonce, or room-access gate.');
	ok(roomWorkSource.includes("count( $brain_run_ids ) > 5") && roomWorkSource.includes("count( $job_ids ) > 5"), 'The foreground worker does not enforce bounded work lists.');

	console.log('PASS message_responsiveness_js assertions=' + assertions);
}

try { run(); } catch (error) { console.error('FAIL message_responsiveness_js: ' + error.message); process.exit(1); }
