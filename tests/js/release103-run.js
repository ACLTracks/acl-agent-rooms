'use strict';

const fs = require('fs');
const path = require('path');
const Api = require('../../assets/js/room/api.js');
const App = require('../../assets/js/room/app.js');
const Members = require('../../assets/js/room/member-list.js');
const Moderation = require('../../assets/js/room/moderation.js');
const Store = require('../../assets/js/room/store.js');
const Transcript = require('../../assets/js/room/transcript.js');

let assertions = 0;
function check(name, value) { assertions += 1; if (!value) { throw new Error('FAIL ' + name); } }
function tick() { return new Promise(resolve => setTimeout(resolve, 0)); }

class Node {
	constructor(tag) { this.tag = tag; this.children = []; this.dataset = {}; this.attributes = {}; this.listeners = {}; this.textContent = ''; this.hidden = false; this.disabled = false; this.value = ''; this.focused = false; }
	appendChild(child) { this.children.push(child); child.parentNode = this; return child; }
	setAttribute(name, value) { this.attributes[name] = String(value); }
	addEventListener(name, handler) { this.listeners[name] = handler; }
	click() { if (this.listeners.click) { return this.listeners.click({target: this}); } }
	focus() { this.focused = true; }
}
class Document {
	createElement(tag) { return new Node(tag); }
	createTextNode(text) { const node = new Node('#text'); node.textContent = text; return node; }
}
class Dialogs {
	constructor(doc) { this.doc = doc; this.node = null; this.title = ''; this.trigger = null; this.closed = 0; }
	open(title, builder, trigger) { this.title = title; this.trigger = trigger; this.node = this.doc.createElement('section'); builder(this.node); return true; }
	close() { this.closed += 1; if (this.trigger && this.trigger.focus) { this.trigger.focus(); } return true; }
}

function buttons(node) { return node.children.filter(child => child.tag === 'button'); }
function labels(node) { return buttons(node).map(button => button.textContent); }
function appActions(person, canModerate) {
	const doc = new Document(); global.document = doc; const dialogs = new Dialogs(doc); const app = Object.create(App.prototype);
	app.config = {permissions: {canManualReply: false, canModerate: !!canModerate}, whisperRecipients: []}; app.dialogs = dialogs; app.composer = {insert() {}}; app.onModeration = canModerate ? function () {} : null; app.onWhisper = null; app.onParticipation = null; app.memberInfo(person, new Node('button'));
	return dialogs.node;
}
function person(state, overrides) { return Object.assign({type: 'user', id: 2, name: 'User Beta', isCurrent: false, moderation: {state: state, can_target: true}}, overrides || {}); }
function response() { return {status: 200, ok: true, headers: {get: () => null}, text: () => Promise.resolve('{}')}; }

async function run() {
	const root = path.resolve(__dirname, '../..');
	const apiSource = fs.readFileSync(path.join(root, 'assets/js/room/api.js'), 'utf8');
	const appSource = fs.readFileSync(path.join(root, 'assets/js/room/app.js'), 'utf8');
	const dialogSource = fs.readFileSync(path.join(root, 'assets/js/room/dialogs.js'), 'utf8');
	const moderationSource = fs.readFileSync(path.join(root, 'assets/js/room/moderation.js'), 'utf8');
	const roomSource = fs.readFileSync(path.join(root, 'assets/js/room.js'), 'utf8');
	const searchIndexer = fs.readFileSync(path.join(root, 'includes/Services/EventSearchIndexer.php'), 'utf8');

	check('non_moderator_no_participant_actions', !labels(appActions(person('none'), false)).includes('Mute'));
	let actionLabels = labels(appActions(person('none'), true));
	check('unrestricted_has_mute', actionLabels.includes('Mute'));
	check('unrestricted_has_ban', actionLabels.includes('Ban'));
	actionLabels = labels(appActions(person('muted'), true));
	check('muted_has_unmute', actionLabels.includes('Unmute'));
	check('muted_has_ban', actionLabels.includes('Ban'));
	check('banned_has_unban', labels(appActions(person('banned'), true)).includes('Unban'));
	check('current_user_no_self_actions', !labels(appActions(person('none', {id: 1, isCurrent: true}), true)).includes('Mute'));
	check('owner_protection_no_actions', !labels(appActions(person('none', {moderation: {state: 'none', can_target: false}}), true)).includes('Ban'));
	check('equal_manager_no_actions', !labels(appActions(person('none', {id: 4, moderation: {state: 'none', can_target: false}}), true)).includes('Mute'));
	check('moderation_central_composer', apiSource.includes('Api.prototype.moderate') && apiSource.includes('return this.request('));

	const pretty = 'https://example.test/wp-json/acl-agent-rooms/v1';
	const plain = 'https://example.test/index.php?rest_route=/acl-agent-rooms/v1';
	check('plain_moderation_url', new URL(Api.buildRestUrl(plain, '/rooms/7/moderation')).searchParams.get('rest_route') === '/acl-agent-rooms/v1/rooms/7/moderation');
	check('pretty_moderation_url', new URL(Api.buildRestUrl(pretty, '/rooms/7/moderation')).pathname === '/wp-json/acl-agent-rooms/v1/rooms/7/moderation');
	let captured; const transport = new Api({restBase: plain, roomId: 7, nonce: 'nonce-103'}, (url, options) => { captured = {url, options}; return Promise.resolve(response()); }); await transport.moderate(2, 'mute', 'reason');
	check('rest_nonce_included', captured.options.headers['X-WP-Nonce'] === 'nonce-103');

	for (const pair of [['mute', 'muted'], ['unmute', 'none'], ['ban', 'banned'], ['unban', 'none']]) {
		const doc = new Document(), dialogs = new Dialogs(doc), target = person(pair[0] === 'unban' ? 'banned' : pair[0] === 'unmute' ? 'muted' : 'none'), trigger = new Node('button'); let snapshotState = '';
		const api = {moderate: () => Promise.resolve({}), participants: () => Promise.resolve({participants: [{actor: {type: 'user', id: 2}, moderation: {state: pair[1], can_target: true}}], summary: {}, sync: {}})};
		const controller = new Moderation(api, {permissions: {canModerate: true}}, dialogs, {container: null, snapshot(rows) { snapshotState = rows[0].moderation.state; }}, function () {}, doc);
		controller.restrict(target, pair[0], trigger); buttons(dialogs.node).find(button => button.dataset.confirmModeration).click(); await tick(); await tick();
		check('successful_' + pair[0] + '_refreshes_state', snapshotState === pair[1]);
	}

	const failureDoc = new Document(), failureDialogs = new Dialogs(failureDoc); let failureCalls = 0;
	const failure = new Moderation({moderate: () => { failureCalls += 1; return Promise.reject(new Error('Safe failure')); }, participants: () => Promise.resolve({})}, {permissions: {canModerate: true}}, failureDialogs, {snapshot() {}}, function () {}, failureDoc);
	failure.restrict(person('none'), 'mute', new Node('button')); buttons(failureDialogs.node).find(button => button.dataset.confirmModeration).click(); await tick();
	check('failed_moderation_safe_error', failureDialogs.node.children.some(node => node.attributes.role === 'alert' && node.textContent === 'Safe failure'));

	let resolveModeration, overlapCalls = 0; const overlapDoc = new Document(), overlapDialogs = new Dialogs(overlapDoc);
	const overlap = new Moderation({moderate: () => { overlapCalls += 1; return new Promise(resolve => { resolveModeration = resolve; }); }, participants: () => Promise.resolve({participants: [], summary: {}, sync: {}})}, {permissions: {canModerate: true}}, overlapDialogs, {container: null, snapshot() {}}, function () {}, overlapDoc);
	overlap.restrict(person('none'), 'mute', new Node('button')); const overlapConfirm = buttons(overlapDialogs.node).find(button => button.dataset.confirmModeration); overlapConfirm.click(); overlapConfirm.click();
	check('repeated_moderation_click_no_overlap', overlapCalls === 1); resolveModeration({}); await tick(); await tick();
	const accessibleAction = buttons(appActions(person('none'), true)).find(button => button.textContent === 'Mute');
	check('participant_action_keyboard_accessible', accessibleAction.type === 'button' && accessibleAction.attributes['aria-label'] === 'Mute User Beta');
	check('escape_closes_action_surface', dialogSource.includes("event.key==='Escape'") && dialogSource.includes('this.close()'));
	check('participant_focus_restored', moderationSource.includes('this.dialogs.trigger = refreshed') && dialogSource.includes('this.trigger.focus()'));

	const doc = new Document(), transcript = new Transcript(null, 1, doc), eligible = {id: 9, type: 'message', actor: {type: 'user', id: 2}, moderation: {can_remove: true}, reactions: []};
	check('moderator_message_removal_action', labels(transcript.actions(eligible, true)).includes('Remove message'));
	check('non_moderator_no_removal_action', !labels(transcript.actions(Object.assign({}, eligible, {moderation: null}), true)).includes('Remove message'));
	check('removed_message_no_removal_action', !labels(transcript.actions(Object.assign({}, eligible, {deleted_at: '2026-07-14T00:00:00Z'}), true)).includes('Remove message'));
	check('non_removable_event_no_removal_action', !labels(transcript.actions(Object.assign({}, eligible, {moderation: {can_remove: false}}), true)).includes('Remove message'));
	check('removal_requires_confirmation', moderationSource.includes("open('Remove message?'") && moderationSource.includes('data-confirm-message-removal') === false && moderationSource.includes('confirmMessageRemoval'));

	let removals = 0; const removeDoc = new Document(), removeDialogs = new Dialogs(removeDoc), removeTrigger = new Node('button');
	const removeController = new Moderation({removeMessage: () => { removals += 1; return Promise.resolve({}); }}, {permissions: {canModerate: true}}, removeDialogs, {}, function () {}, removeDoc);
	removeController.onMessageRemoved = () => Promise.resolve(new Node('p'));
	removeController.remove(9, removeTrigger); buttons(removeDialogs.node).find(button => button.textContent === 'Cancel').click();
	check('cancel_removal_sends_no_request', removals === 0);
	removeController.remove(9, removeTrigger); const removeConfirm = buttons(removeDialogs.node).find(button => button.textContent === 'Remove message'); removeConfirm.click(); removeConfirm.click(); await tick(); await tick();
	check('confirm_removal_exactly_once', removals === 1);
	check('removal_central_composer', apiSource.includes('Api.prototype.removeMessage') && apiSource.includes("method: 'DELETE'"));
	check('plain_removal_url', new URL(Api.buildRestUrl(plain, '/rooms/7/events/9')).searchParams.get('rest_route') === '/acl-agent-rooms/v1/rooms/7/events/9');
	check('pretty_removal_url', new URL(Api.buildRestUrl(pretty, '/rooms/7/events/9')).pathname === '/wp-json/acl-agent-rooms/v1/rooms/7/events/9');
	check('successful_removal_syncs_transcript', roomSource.includes('moderationController.onMessageRemoved') && roomSource.includes('sync.immediate()'));

	const store = new Store(7, 1); store.merge([{id: 9, room_id: 7, type: 'message', content: 'original secret', actor: {type: 'user', id: 2}, reactions: [{reaction: 'x', count: 1}], moderation: {can_remove: true}}]); store.merge([{id: 10, room_id: 7, type: 'message_delete', parent_event_id: 9, content: 'Message removed by a moderator.', created_at: '2026-07-14T00:00:00Z'}]);
	check('successful_removal_canonical_text', store.events[9].content === 'Message removed by a moderator.');
	check('successful_removal_removes_action', store.events[9].moderation.can_remove === false);
	const removeFailureDoc = new Document(), removeFailureDialogs = new Dialogs(removeFailureDoc), removeFailure = new Moderation({removeMessage: () => Promise.reject(new Error('Removal failed safely'))}, {permissions: {canModerate: true}}, removeFailureDialogs, {}, function () {}, removeFailureDoc); removeFailure.remove(9, new Node('button')); buttons(removeFailureDialogs.node).find(button => button.textContent === 'Remove message').click(); await tick();
	check('failed_removal_safe_error', removeFailureDialogs.node.children.some(node => node.attributes.role === 'alert' && node.textContent === 'Removal failed safely'));
	store.merge([{id: 9, room_id: 7, type: 'message', content: 'original secret', actor: {type: 'user', id: 2}, reactions: [{reaction: 'x', count: 1}], moderation: {can_remove: true}}]);
	check('polling_does_not_restore_removed_content', store.events[9].content === 'Message removed by a moderator.' && store.events[9].deleted_at);
	check('moderator_audit_event_not_transcript_line', transcript.visible({type: 'moderation'}) === false);

	const labelApp = Object.create(App.prototype), memberHelper = Object.create(Members.prototype);
	check('current_user_person_you', labelApp.memberLabels({type: 'user', isCurrent: true}).join(' \u00b7 ') === 'Person \u00b7 You');
	check('other_user_not_you', labelApp.memberLabels({type: 'user', isCurrent: false}).join(' \u00b7 ') === 'Person');
	check('other_moderator_not_you', labelApp.memberLabels({type: 'user', isCurrent: false, roleLabel: 'Moderator'}).join(' \u00b7 ') === 'Person \u00b7 Moderator');
	check('agent_not_you', labelApp.memberLabels({type: 'agent', isCurrent: true}).join(' \u00b7 ') === 'Agent');
	check('empty_secondary_no_separator', memberHelper.subtitle({type: 'user', isCurrent: false, moderation: null}) === 'Person');
	check('participant_drawer_regression_retained', appSource.includes('syncMembersAccessibility') && fs.existsSync(path.join(root, 'tests/js/phase8-run.js')));
	check('moderation_regression_retained', fs.existsSync(path.join(root, 'tests/js/phase9-run.js')) && moderationSource.includes('this.api.moderate'));
	check('plain_permalink_regression_retained', fs.existsSync(path.join(root, 'tests/js/release102-run.js')) && Api.buildRestUrl(plain, '/rooms/7/events').includes('rest_route='));
	check('pretty_permalink_regression_retained', Api.buildRestUrl(pretty, '/rooms/7/events').includes('/wp-json/'));
	check('whisper_privacy_regression_retained', searchIndexer.includes("array( 'message', 'system_notice', 'action' )") && !searchIndexer.includes("'whisper'"));
	check('phase4_through_phase9_suites_retained', [4, 5, 6, 7, 8, 9].every(n => fs.existsSync(path.join(root, 'tests/js/phase' + n + '-run.js'))) || fs.existsSync(path.join(root, 'tests/js/run.js')));
	check('release101_and_102_suites_retained', fs.existsSync(path.join(root, 'tests/js/release102-run.js')) && fs.existsSync(path.join(root, 'tests/Release101RegressionTest.php')));

	if (assertions !== 50) { throw new Error('Expected 50 assertions, got ' + assertions); }
	console.log('PASS release_103_moderation assertions=' + assertions);
}

run().catch(error => { console.error(error.message); process.exit(1); });
