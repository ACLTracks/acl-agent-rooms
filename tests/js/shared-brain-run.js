'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const Api = require('../../assets/js/room/api.js');
const Members = require('../../assets/js/room/member-list.js');
const Store = require('../../assets/js/room/store.js');
const Transcript = require('../../assets/js/room/transcript.js');

let assertions = 0;
function check(name, value) { assertions += 1; if (!value) { throw new Error('FAIL ' + name); } }

class ClassList {
	constructor() { this.values = []; }
	toggle(value, enabled) { this.values = this.values.filter(item => item !== value); if (enabled) { this.values.push(value); } }
	contains(value) { return this.values.includes(value); }
}
class Field {
	constructor(name, value) { this.name = name; this.value = value || ''; this.disabled = false; this.required = false; this.hidden = false; this.listeners = {}; this.classList = new ClassList(); this.row = {classList: new ClassList()}; }
	addEventListener(name, callback) { this.listeners[name] = callback; }
	closest(selector) { return selector === 'tr' ? this.row : null; }
	dispatch(name) { if (this.listeners[name]) { this.listeners[name]({target: this}); } }
}
class Form {
	constructor(fields, mode, brain, brainRow, runtime) { this.elements = fields; this.fields = fields; this.mode = mode; this.brain = brain; this.brainRow = brainRow; this.runtime = runtime; }
	querySelector(selector) {
		if (selector === '[data-acl-ar-execution-mode]') { return this.mode; }
		if (selector === '[data-acl-ar-brain-select]') { return this.brain; }
		if (selector === '[data-acl-ar-brain-row]') { return this.brainRow; }
		if (selector === '[data-acl-ar-brain-runtime]') { return this.runtime; }
		const match = selector.match(/^input\[name="([^"]+)"\]$/); return match ? this.fields.find(field => field.name === match[1]) || null : null;
	}
	querySelectorAll() { return []; }
}
class Node {
	constructor(tag) { this.tag = tag; this.children = []; this.dataset = {}; this.attributes = {}; this.className = ''; this.classList = {add() {}}; this.style = {setProperty() {}}; this.textContent = ''; this.hidden = false; }
	appendChild(child) { this.children.push(child); child.parentNode = this; return child; }
	setAttribute(name, value) { this.attributes[name] = String(value); }
	addEventListener() {}
}
class Document {
	createElement(tag) { return new Node(tag); }
	createTextNode(text) { const node = new Node('#text'); node.textContent = text; return node; }
}

function event(id, agentId, name, content) {
	return {id, room_id: 7, type: 'message', actor: {type: 'agent', id: agentId, name, avatar_url: 'avatar-' + agentId + '.png'}, content, created_at: '2026-07-14T22:00:00Z', reactions: []};
}

function run() {
	const root = path.resolve(__dirname, '../..');
	const adminSource = fs.readFileSync(path.join(root, 'assets/js/admin.js'), 'utf8');
	const roomSource = fs.readFileSync(path.join(root, 'assets/js/room.js'), 'utf8');
	const css = fs.readFileSync(path.join(root, 'assets/css/room-aol.css'), 'utf8');
	const agentsPage = fs.readFileSync(path.join(root, 'includes/Admin/AgentsPage.php'), 'utf8');
	const brainsController = fs.readFileSync(path.join(root, 'includes/Rest/BrainsController.php'), 'utf8');

	const mode = new Field('execution_mode', 'independent');
	const brain = new Field('brain_id', '12');
	brain.options = [{value: '0', dataset: {}}, {value: '12', dataset: {provider: 'controlled-fake', model: 'brain-model-v1'}}]; brain.selectedIndex = 1;
	const brainRow = {hidden: false}; const runtime = {textContent: ''};
	const persona = new Field('system_prompt', 'PERSONA-KEPT');
	const independent = ['config_mode', 'shared_config_id', 'provider_route', 'model', 'model_custom', 'temperature', 'max_tokens'].map((name, index) => new Field(name, 'saved-' + index));
	const fields = [mode, brain, persona].concat(independent);
	const form = new Form(fields, mode, brain, brainRow, runtime);
	let ready;
	const sandbox = {document: {addEventListener(name, callback) { if (name === 'DOMContentLoaded') { ready = callback; } }, querySelectorAll(selector) { return selector === 'form' ? [form] : []; }}, navigator: {}, window: {}, Promise, Array, String};
	vm.runInNewContext(adminSource, sandbox); ready();
	check('independent_mode_hides_brain_selector', brainRow.hidden && brain.disabled && !brain.required);
	check('independent_mode_enables_runtime_controls', independent.every(field => !field.disabled));
	mode.value = 'brain'; mode.dispatch('change');
	check('brain_mode_shows_brain_selector', !brainRow.hidden && !brain.disabled && brain.required);
	check('brain_mode_disables_independent_controls', independent.every(field => field.disabled));
	check('brain_mode_marks_inherited_rows', independent.every(field => field.row.classList.contains('acl-ar-runtime-inherited')));
	check('brain_mode_keeps_persona_editable', !persona.disabled && persona.value === 'PERSONA-KEPT');
	check('brain_mode_displays_inherited_runtime', runtime.textContent === 'Inherited runtime: controlled-fake / brain-model-v1');
	mode.value = 'independent'; mode.dispatch('change');
	check('mode_round_trip_preserves_values', independent.every((field, index) => field.value === 'saved-' + index));

	const store = new Store(7, 9);
	const responses = [event(101, 21, 'Alpha', 'Alpha answer'), event(102, 22, 'Beta', 'Beta answer'), event(103, 23, 'Gamma', 'Gamma answer')];
	store.initial([{id: 100, room_id: 7, type: 'brain_run', actor: {type: 'system', id: 0}, content: 'terminal audit'}].concat(responses), {after_cursor: 'after-103'});
	check('three_fanout_messages_are_visible', store.messages().length === 3);
	check('terminal_brain_audit_is_not_transcript_message', store.messages().every(item => item.type === 'message'));
	check('fanout_identity_is_distinct', store.messages().map(item => item.actor.name).join(',') === 'Alpha,Beta,Gamma');
	check('fanout_order_is_stable', store.messages().map(item => item.actor.id).join(',') === '21,22,23');
	check('incremental_cursor_tracks_terminal_batch', store.afterCursor === 'after-103');
	store.incremental(responses, {after_cursor: 'after-103'});
	check('incremental_poll_deduplicates_fanout', store.messages().length === 3);

	const doc = new Document(); const transcript = new Transcript(null, 9, doc);
	check('brain_audit_is_not_renderable', transcript.visible({type: 'brain_run'}) === false);
	check('brain_response_is_renderable', transcript.visible(responses[0]) === true);
	const actions = transcript.actions(responses[0], true).children.map(node => node.textContent);
	check('brain_response_is_replyable', actions.includes('Reply'));
	check('brain_response_is_reactable', actions.includes('React'));
	const rendered = transcript.node(responses[0]);
	check('agent_identity_has_accessible_label', rendered.children.some(node => node.attributes['aria-label'] === 'Alpha, agent'));
	check('agent_content_is_rendered_as_text', rendered.children.some(node => node.textContent === 'Alpha answer'));

	const members = new Members(null, {currentUserId: 9, agents: [{id: 21, name: 'Alpha'}, {id: 22, name: 'Beta'}, {id: 23, name: 'Gamma'}]});
	members.snapshot([21, 22, 23].map((id, index) => ({actor: {type: 'agent', id, name: ['Alpha', 'Beta', 'Gamma'][index]}, presence: {state: 'responding'}, participation: {state: 'active'}})), {agents: 3}, {presence_version: 'brain-1'});
	check('grouped_state_projects_to_all_agents', [21, 22, 23].every(id => members.people['agent:' + id].state === 'Responding'));
	check('grouped_state_preserves_agent_names', [21, 22, 23].map(id => members.people['agent:' + id].name).join(',') === 'Alpha,Beta,Gamma');
	check('grouped_state_keeps_presence_version', members.presenceVersion === 'brain-1');

	const plain = new URL(Api.buildRestUrl('http://localhost:10010/index.php?rest_route=/acl-agent-rooms/v1', '/rooms/7/brain-runs'));
	const pretty = new URL(Api.buildRestUrl('http://localhost:10010/wp-json/acl-agent-rooms/v1', '/rooms/7/brain-runs'));
	check('plain_permalink_brain_route', plain.searchParams.get('rest_route') === '/acl-agent-rooms/v1/rooms/7/brain-runs');
	check('pretty_permalink_brain_route', pretty.pathname === '/wp-json/acl-agent-rooms/v1/rooms/7/brain-runs');
	check('manager_diagnostics_are_capability_gated', brainsController.includes("'read_permissions'") && brainsController.includes('Capabilities::MANAGE_AGENTS'));
	check('ordinary_payload_omits_brain_internals', roomSource.indexOf('orchestration_prompt') === -1 && roomSource.indexOf('validated_response_json') === -1);
	check('brain_agent_test_requires_explicit_post', agentsPage.includes('acl_ar_brain_test_post_required') && agentsPage.includes('explicit Brain POST action'));
	check('brain_admin_diagnostics_omit_raw_provider_response', brainsController.indexOf('validated_response_json') === -1 && brainsController.indexOf('raw_response') === -1);
	check('responsive_accessibility_contracts', /@media\s*\(max-width:\s*520px\)/.test(css) && css.includes('prefers-reduced-motion') && css.includes('acl-ar-room-app--high-contrast'));

	console.log('PASS shared_brain_js assertions=' + assertions);
}

try { run(); } catch (error) { console.error(error.message); process.exit(1); }
