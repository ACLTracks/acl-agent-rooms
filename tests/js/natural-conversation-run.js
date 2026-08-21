'use strict';

const fs = require('fs');
const path = require('path');
const Admin = require('../../assets/js/admin.js');
const Store = require('../../assets/js/room/store.js');
const Sync = require('../../assets/js/room/sync.js');
const Members = require('../../assets/js/room/member-list.js');

let assertions = 0;
function assert(value, message) { assertions += 1; if (!value) { throw new Error(message); } }
function same(expected, actual, message) { assertions += 1; if (JSON.stringify(expected) !== JSON.stringify(actual)) { throw new Error(message + ' expected=' + JSON.stringify(expected) + ' actual=' + JSON.stringify(actual)); } }
function message(id, content, agentId) { return {id, room_id: 13, type: 'message', actor: {type: 'agent', id: agentId || 1, name: 'Agent'}, content, created_at: '2026-07-15T12:00:00Z', interactions: {can_reply: true, can_react: true}, moderation: {can_remove: true}}; }

class Field {
	constructor(name, value) { this.name = name; this.value = String(value === undefined ? '' : value); this.disabled = false; this.listeners = {}; this.validationMessage = ''; this.dataset = {}; }
	addEventListener(name, callback) { this.listeners[name] = callback; }
	dispatchEvent(event) { if (this.listeners[event.type]) { this.listeners[event.type](event); } }
	setCustomValidity(value) { this.validationMessage = String(value); }
}
class Row {
	constructor(fields) { this.fields = fields || []; this.hidden = false; }
	querySelectorAll() { return this.fields; }
}
class Button extends Field { constructor(preset) { super('', ''); this.dataset.aclArNaturalPreset = preset; } }
class Form {
	constructor(fields, rows, buttons) { this.elements = Object.values(fields); this.fields = fields; this.rows = rows || []; this.buttons = buttons || []; }
	querySelector(selector) {
		if (selector === '[data-acl-ar-conversation-mode]') { return this.fields.conversation_mode || null; }
		if (selector === '[data-acl-ar-natural-role]') { return this.fields.natural_conversation_role || null; }
		let match = selector.match(/^\[data-acl-ar-natural-field="([^"]+)"\]$/);
		if (match) { return this.fields['preset_' + match[1]] || null; }
		return null;
	}
	querySelectorAll(selector) { if (selector === '[data-acl-ar-natural-room-row]') { return this.rows; } if (selector === '[data-acl-ar-natural-preset]') { return this.buttons; } return []; }
}

async function run() {
	global.Event = class Event { constructor(type) { this.type = type; } };
	const mode = new Field('conversation_mode', 'immediate'); mode.dataset.aclArConversationMode = '1';
	const naturalInput = new Field('natural_min_responders', '1'); const row = new Row([naturalInput]); const roomForm = new Form({conversation_mode: mode, natural_min_responders: naturalInput}, [row]);
	Admin.setupConversationMode(roomForm);
	assert(mode.listeners.change, 'Conversation selector was not initialized.'); // 1
	assert(row.hidden && naturalInput.disabled, 'Natural settings were not hidden in Immediate mode.'); // 2
	mode.value = 'natural'; mode.dispatchEvent(new Event('change'));
	assert(!row.hidden && !naturalInput.disabled, 'Natural settings were not shown in Natural mode.'); // 3

	const min = new Field('natural_min_responders', '3'); const max = new Field('natural_max_responders', '2');
	const firstMin = new Field('natural_initial_delay_min_seconds', '1.5'); const firstMax = new Field('natural_initial_delay_max_seconds', '4.5');
	const interMin = new Field('natural_inter_turn_delay_min_seconds', '2.5'); const interMax = new Field('natural_inter_turn_delay_max_seconds', '8');
	const overrideMin = new Field('natural_delay_min_seconds', ''); const overrideMax = new Field('natural_delay_max_seconds', '');
	const validationForm = new Form({natural_min_responders:min,natural_max_responders:max,natural_initial_delay_min_seconds:firstMin,natural_initial_delay_max_seconds:firstMax,natural_inter_turn_delay_min_seconds:interMin,natural_inter_turn_delay_max_seconds:interMax,natural_delay_min_seconds:overrideMin,natural_delay_max_seconds:overrideMax});
	Admin.setupNaturalPairValidation(validationForm);
	assert(max.validationMessage.includes('Maximum'), 'Reversed numeric pair did not render a safe validation error.'); // 4
	max.value = '4'; max.dispatchEvent(new Event('input')); assert(max.validationMessage === '', 'Valid numeric pair retained an error.'); // 5
	overrideMin.value = '2'; overrideMin.dispatchEvent(new Event('input')); assert(overrideMin.validationMessage.includes('both'), 'One-sided agent override was accepted.'); // 6
	overrideMax.value = '5'; overrideMax.dispatchEvent(new Event('input')); assert(!overrideMin.validationMessage && !overrideMax.validationMessage, 'Complete agent override was rejected.'); // 7

	const role = new Field('natural_conversation_role', 'balanced'); const participation = new Field('', '61'); const question = new Field('', '21'); const cooldown = new Field('', '22'); const limit = new Field('', '5');
	const quiet = new Button('quiet'), talkative = new Button('talkative'), facilitator = new Button('facilitator');
	const agentForm = new Form({natural_conversation_role:role,preset_participation:participation,preset_question:question,preset_cooldown:cooldown,preset_limit:limit}, [], [quiet,talkative,facilitator]);
	Admin.setupNaturalPresets(agentForm); talkative.dispatchEvent(new Event('click'));
	same(['talkative','85','25','12','6'], [role.value,participation.value,question.value,cooldown.value,limit.value], 'Talkative preset did not update numeric fields.'); // 8
	participation.value = '73'; assert(participation.value === '73', 'Custom preset value could not persist.'); // 9
	facilitator.dispatchEvent(new Event('click')); assert(role.value === 'facilitator' && question.value === '65', 'Facilitator preset did not strengthen question tendency.'); // 10

	const store = new Store(13, 99); let syncStates = [];
	const responses = [
		{events: [], paging: {after_cursor: 'a0'}, sync: {agent_states: [{agent_id:1,state:'typing'},{agent_id:2,state:'ready'}]}},
		{events: [message(10, 'First distinct reply', 1)], paging: {after_cursor:'a10'}, sync: {agent_states: [{agent_id:1,state:'ready'},{agent_id:2,state:'typing'}]}},
		{events: [message(11, 'Later distinct reply?', 2)], paging: {after_cursor:'a11'}, sync: {agent_states: [{agent_id:2,state:'ready'}]}}
	];
	const api = {events: () => Promise.resolve(responses.shift())};
	const sync = new Sync(api, store, {document:{hidden:false},navigator:{onLine:true},onSync: state => syncStates.push(state)});
	await sync.initial(); assert(store.messages().length === 0, 'Pending response rendered during initial load.'); // 11
	assert(syncStates[0].agent_states[0].state === 'typing', 'Due agent typing state was not delivered safely.'); // 12
	assert(syncStates[0].agent_states[1].state === 'ready', 'Later agent typed before its window.'); // 13
	await sync.catchUp(); same([10], store.ids, 'First scheduled message did not appear alone.'); // 14
	assert(store.events[10].content === 'First distinct reply', 'Published first content was lost.'); // 15
	assert(syncStates[1].agent_states[0].state === 'ready', 'Typing did not clear after publication.'); // 16
	assert(syncStates[1].agent_states[1].state === 'typing', 'Next due agent did not enter typing.'); // 17
	await sync.catchUp(); same([10,11], store.ids, 'Later scheduled message did not converge.'); // 18
	assert(store.ids[0] < store.ids[1], 'Transcript order was not preserved.'); // 19
	assert(store.all().every(event => event.content && !event.pending_content), 'Pending content leaked into store events.'); // 20

	const members = new Members(null, {currentUserId:99,agents:[{id:1,name:'Quiet',activityState:'ready'},{id:2,name:'Facilitator',activityState:'ready'}]});
	members.applyAgentStates([{agent_id:1,state:'typing'},{agent_id:2,state:'ready'}]); assert(members.people['agent:1'].presenceState === 'typing', 'Participant state missed due typing agent.'); // 21
	assert(members.people['agent:2'].presenceState === 'ready', 'Silent/later agent received false UI activity.'); // 22
	members.applyAgentStates([{agent_id:1,state:'ready'}]); assert(members.people['agent:1'].presenceState === 'ready', 'Canceled typing state did not clear.'); // 23
	assert(members.people['user:99'].presenceState === 'offline', 'Human presence was disturbed by agent state sync.'); // 24
	assert(Object.keys(members.people).filter(key => key.startsWith('agent:')).length === 2, 'Participant drawer lost agents.'); // 25

	store.activeReply = {event_id:10}; store.applyClear(10); assert(store.activeReply === null, 'Clear Chat retained reply targeting cleared content.'); // 26
	same([11], store.ids, 'Clear Chat removed the partial published sequence incorrectly.'); // 27
	assert(!store.events[10], 'Cleared/superseded message remained visible.'); // 28
	assert(store.events[11].interactions.can_reply, 'Published message stopped being replyable.'); // 29
	assert(store.events[11].interactions.can_react, 'Published message stopped being reactable.'); // 30
	assert(store.events[11].moderation.can_remove, 'Published message stopped being moderatable.'); // 31
	store.setReadState({last_read_event_id:10,first_unread_event_id:11,unread_count:1}); assert(store.unread === 1 && store.firstUnread === 11, 'Unread state did not update on publication.'); // 32

	const root = path.join(__dirname, '../..');
	const roomSource = fs.readFileSync(path.join(root, 'assets/js/room.js'), 'utf8');
	const css = fs.readFileSync(path.join(root, 'assets/css/room-aol.css'), 'utf8');
	const apiSource = fs.readFileSync(path.join(root, 'assets/js/room/api.js'), 'utf8');
	const searchSource = fs.readFileSync(path.join(root, 'assets/js/room/search.js'), 'utf8');
	const interactionsSource = fs.readFileSync(path.join(root, 'assets/js/room/interactions.js'), 'utf8');
	assert(roomSource.includes('members.applyAgentStates'), 'Reload did not restore durable safe typing state.'); // 33
	assert(!roomSource.includes('pending_content'), 'Room UI contains a pending-content renderer.'); // 34
	assert(roomSource.includes('composer.clear()') && roomSource.includes("catch(function(error){setState(error.message"), 'Composer send/error preservation contract changed.'); // 35
	assert(apiSource.includes('restBase') && apiSource.includes("searchParams.has('rest_route')") && apiSource.includes('X-WP-Nonce'), 'Plain REST polling contract disappeared.'); // 36
	assert(apiSource.includes('after_cursor') && apiSource.includes('before_cursor'), 'Pretty/plain cursor polling contract disappeared.'); // 37
	assert(searchSource.includes('this.api.search') && searchSource.includes('dataset.eventId'), 'Published search UI contract disappeared.'); // 38
	assert(interactionsSource.includes('reply') && interactionsSource.includes('reaction'), 'Reply/reaction UI contract disappeared.'); // 39
	assert(roomSource.includes('ACLARRoomModeration'), 'Moderation UI integration disappeared.'); // 40
	assert(roomSource.includes('ACLARRoomClearChat'), 'Clear Chat UI integration disappeared.'); // 41
	assert(roomSource.includes('ACLARRoomAgentParticipation'), 'Existing Shared Brain participant UI integration disappeared.'); // 42
	assert(css.includes('.acl-ar-room-app--high-contrast'), 'High-contrast mode disappeared.'); // 43
	assert(css.includes('.acl-ar-room-app--large-text'), 'Large-text mode disappeared.'); // 44
	assert(css.includes('@media(prefers-reduced-motion:reduce)'), 'Reduced-motion support disappeared.'); // 45
	assert(css.includes('@media(max-width:520px)') && css.includes('max-width:100%') && css.includes('min-width:0'), '390-pixel overflow protections disappeared.'); // 46
	assert(css.includes(':focus-visible'), 'Keyboard focus visibility disappeared.'); // 47
	assert(!store.all().some(event => event.id === 12), 'Superseded unpublished turn appeared in the transcript.'); // 48
	assert(syncStates.length === 3, 'Staggered direct mention or /ask polling states did not converge.'); // 49
	assert(store.messages().length === 1, 'Reload/store exposed something other than published messages after clear.'); // 50

	console.log('PASS natural_conversation_js assertions=' + assertions);
}

run().catch(error => { console.error('FAIL natural_conversation_js: ' + error.message); process.exit(1); });
