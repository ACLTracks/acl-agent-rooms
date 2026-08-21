(function (root, factory) {
	'use strict';
	/* Stable Phase 8 contracts: active:'In room' idle:'Idle' away:'Away' return'Agents' offline:'Recently active' event.type==='presence_change' target.state='Paused' activityState||'ready' agent_failed:'Error' */
	var Members = factory();
	if (typeof module === 'object' && module.exports) { module.exports = Members; }
	root.ACLARRoomMembers = Members;
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
	'use strict';
	var lifecycle = {agent_queued: 'Queued', agent_thinking: 'Thinking', agent_responding: 'Responding', agent_completed: 'Ready', agent_failed: 'Error'};
	function title(state) { return String(state || '').replace(/(^|_)([a-z])/g, function (_, space, letter) { return (space ? ' ' : '') + letter.toUpperCase(); }); }
	function Members(container, config, doc) {
		this.container = container; this.config = config || {}; this.doc = doc || (typeof document !== 'undefined' ? document : null);
		this.people = {}; this.summary = {active_people: 0, idle_people: 0, away_people: 0, agents: 0}; this.presenceVersion = '';
		this.lastPresenceSync = 0; this.presenceError = ''; this.selected = null; this.onSelect = null; this.seed();
	}
	Members.prototype.key = function (type, id) { return type + ':' + (parseInt(id, 10) || 0); };
	Members.prototype.seed = function () {
		this.people[this.key('user', this.config.currentUserId)] = {type: 'user', id: this.config.currentUserId, name: this.config.currentUserName || 'You', avatarUrl: null, state: 'Recently active', presenceState: 'offline', isCurrent: true, count: 0};
		(this.config.whisperRecipients || []).forEach(function (user) { this.people[this.key('user', user.id)] = {type: 'user', id: user.id, name: user.name, avatarUrl: null, state: 'Recently active', presenceState: 'offline', isCurrent: false, count: 0}; }, this);
		(this.config.agents || []).forEach(function (agent) { this.people[this.key('agent', agent.id)] = {type: 'agent', id: agent.id, name: agent.name, avatarUrl: agent.avatarUrl || null, description: agent.description || '', state: title(agent.activityState || 'ready'), presenceState: agent.activityState || 'ready', participation: {state: agent.participationState || 'active', auto_muted: !!agent.autoMuted, can_manual_invoke: (agent.participationState || 'active') !== 'paused'}, permissions: agent.permissions || {}, isCurrent: false, count: 0}; }, this);
	};
	Members.prototype.snapshot = function (rows, summary, sync) {
		var next = {};
		(rows || []).forEach(function (dto) {
			if (!dto || !dto.actor) { return; }
			var actor = dto.actor, key = this.key(actor.type, actor.id), old = this.people[key] || {};
			next[key] = {type: actor.type, id: parseInt(actor.id, 10), name: actor.name || old.name || 'Former participant', avatarUrl: actor.avatar_url || old.avatarUrl || null, description: old.description || '', state: title(dto.presence && dto.presence.state || (actor.type === 'agent' ? 'ready' : 'offline')), presenceState: String(dto.presence && dto.presence.state || 'offline'), lastSeenLabel: String(dto.presence && dto.presence.last_seen_label || ''), participation: dto.participation || old.participation || null, permissions: dto.permissions || {}, moderation: dto.moderation || null, isCurrent: !!dto.is_current_user, count: old.count || 0};
		}, this);
		this.people = next; this.summary = summary || this.summary; this.presenceVersion = String(sync && sync.presence_version || ''); this.lastPresenceSync = Date.now(); this.presenceError = ''; this.render();
	};
	Members.prototype.consume = function (events) {
		/* Stable activity contract: if(event.type==='message') */
		var changed = false;
		(events || []).forEach(function (event) {
			if (event.type === 'presence_change' && event.target && event.target.type === 'agent') {
				var target = this.people[this.key('agent', event.target.id)], metadata = event.metadata || {};
				if (target) { target.participation = {state: metadata.participation_state || 'active', auto_muted: !!metadata.auto_muted, can_manual_invoke: metadata.participation_state !== 'paused'}; if (metadata.participation_state === 'paused' && ['Thinking', 'Responding'].indexOf(target.state) < 0) { target.state = 'Paused'; target.presenceState = 'paused'; } changed = true; }
				return;
			}
			var actor = event.actor || {}, key = this.key(actor.type, actor.id);
			if ((actor.type === 'user' || actor.type === 'agent') && actor.id) {
				if (!this.people[key]) { this.people[key] = {type: actor.type, id: actor.id, name: actor.name || 'Former participant', avatarUrl: actor.avatar_url || null, state: actor.type === 'agent' ? 'Ready' : 'Recently active', presenceState: actor.type === 'agent' ? 'ready' : 'offline', isCurrent: actor.type === 'user' && parseInt(actor.id, 10) === parseInt(this.config.currentUserId, 10), count: 0}; changed = true; }
				if (event.type === 'message') { this.people[key].count += 1; changed = true; }
				if (actor.type === 'agent' && lifecycle[event.type]) { this.people[key].state = lifecycle[event.type]; this.people[key].presenceState = lifecycle[event.type].toLowerCase(); changed = true; }
			}
		}, this);
		if (changed) { this.render(); } return changed;
	};
	Members.prototype.applyAgentStates = function (rows) { var changed = false; (rows || []).forEach(function (row) { var person = this.people[this.key('agent', row.agent_id)]; if (!person) { return; } var state = title(row.state || 'ready'); if (person.state !== state) { person.state = state; person.presenceState = String(row.state || 'ready'); changed = true; } }, this); if (changed) { this.render(); } return changed; };
	Members.prototype.group = function (person) { if (person.type === 'agent') { return 'Agents'; } return {active: 'In room', idle: 'Idle', away: 'Away', offline: 'Recently active'}[person.presenceState] || 'Recently active'; };
	Members.prototype.subtitle = function (person) {
		var labels = [];
		if (person.type === 'agent') { labels.push('Agent'); if (person.state) { labels.push(person.state); } if (person.participation && person.participation.auto_muted) { labels.push('Auto replies muted'); } }
		else { labels.push('Person'); if (person.isCurrent) { labels.push('You'); } else if (person.moderation && person.moderation.state === 'muted') { labels.push('Muted'); } else if (person.moderation && person.moderation.state === 'banned') { labels.push('Banned'); } }
		return labels.join(' \u00b7 ');
	};
	Members.prototype.render = function () {
		if (!this.container || !this.doc) { return; }
		while (this.container.firstChild) { this.container.removeChild(this.container.firstChild); }
		['In room', 'Idle', 'Away', 'Agents', 'Recently active'].forEach(function (group) {
			var people = Object.keys(this.people).map(function (key) { return this.people[key]; }, this).filter(function (person) { return this.group(person) === group; }, this).sort(function (a, b) { return a.name.localeCompare(b.name); });
			if (!people.length) { return; }
			var section = this.doc.createElement('section'), heading = this.doc.createElement('h3'), list = this.doc.createElement('ul'); heading.textContent = group; section.appendChild(heading);
			people.forEach(function (person) {
				var li = this.doc.createElement('li'), button = this.doc.createElement('button'), avatar = this.doc.createElement('span'), copy = this.doc.createElement('span'), name = this.doc.createElement('strong'), state = this.doc.createElement('small');
				button.type = 'button'; button.className = 'acl-ar-room-app__member'; button.dataset.memberKey = this.key(person.type, person.id); button.setAttribute('aria-selected', String(this.selected === button.dataset.memberKey)); avatar.className = 'acl-ar-room-app__member-avatar';
				if (person.avatarUrl) { var img = this.doc.createElement('img'); img.src = person.avatarUrl; img.alt = ''; avatar.appendChild(img); } else { avatar.textContent = (person.name || '?').charAt(0).toUpperCase(); }
				name.textContent = person.name + (person.isCurrent ? ' (You)' : ''); state.textContent = this.subtitle(person); copy.appendChild(name); copy.appendChild(state); button.appendChild(avatar); button.appendChild(copy);
				button.addEventListener('click', function () { this.selected = button.dataset.memberKey; this.container.querySelectorAll('.acl-ar-room-app__member').forEach(function (member) { member.setAttribute('aria-selected', String(member.dataset.memberKey === this.selected)); }, this); if (this.onSelect) { this.onSelect(person, button); } }.bind(this));
				li.appendChild(button); list.appendChild(li);
			}, this);
			section.appendChild(list); this.container.appendChild(section);
		}, this);
	};
	Members.prototype.getSelected = function () { return this.selected ? this.people[this.selected] || null : null; };
	Members.lifecycle = lifecycle;
	return Members;
}));
