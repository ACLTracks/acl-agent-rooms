(function (root, factory) {
	'use strict';
	var Moderation = factory();
	if (typeof module === 'object' && module.exports) { module.exports = Moderation; }
	root.ACLARRoomModeration = Moderation;
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
	'use strict';
	var labels = {mute: 'Mute', unmute: 'Unmute', ban: 'Ban', unban: 'Unban'};
	function Moderation(api, config, dialogs, members, setState, doc) {
		this.api = api; this.config = config || {}; this.dialogs = dialogs; this.members = members; this.setState = setState || function () {};
		this.doc = doc || (typeof document !== 'undefined' ? document : null); this.pending = false; this.onMessageRemoved = null;
	}
	Moderation.prototype.permitted = function () { return !!(this.config.permissions && this.config.permissions.canModerate); };
	Moderation.prototype.feedback = function (node, message) { node.textContent = String(message || 'The moderation action could not be completed.'); node.hidden = false; };
	Moderation.prototype.cancelButton = function () { var button = this.doc.createElement('button'); button.type = 'button'; button.className = 'acl-ar-bevel-button'; button.textContent = 'Cancel'; button.addEventListener('click', function () { if (!this.pending) { this.dialogs.close(); } }.bind(this)); return button; };
	Moderation.prototype.restrict = function (person, action, trigger) {
		if (!this.permitted() || !person || person.type !== 'user' || person.isCurrent || !person.moderation || !person.moderation.can_target || !labels[action]) { return false; }
		var title = labels[action] + ' ' + person.name + '?';
		return this.dialogs.open(title, function (node) {
			var copy = this.doc.createElement('p'); copy.textContent = action === 'ban' ? 'This person will lose access to the room until unbanned.' : action === 'mute' ? 'This person will be unable to post until unmuted.' : 'This change takes effect immediately.'; node.appendChild(copy);
			var reasonLabel = this.doc.createElement('label'), reason = this.doc.createElement('textarea'); reasonLabel.textContent = 'Reason (optional)'; reason.rows = 3; reason.maxLength = 500; reasonLabel.appendChild(reason); node.appendChild(reasonLabel);
			var feedback = this.doc.createElement('p'); feedback.setAttribute('role', 'alert'); feedback.hidden = true; node.appendChild(feedback);
			node.appendChild(this.cancelButton());
			var confirm = this.doc.createElement('button'); confirm.type = 'button'; confirm.className = 'acl-ar-bevel-button'; confirm.dataset.confirmModeration = action; confirm.textContent = labels[action]; confirm.setAttribute('aria-label', labels[action] + ' ' + person.name);
			confirm.addEventListener('click', function () {
				if (this.pending) { return; } this.pending = true; confirm.disabled = true; reason.disabled = true;
				this.api.moderate(person.id, action, reason.value).then(function () { return this.api.participants(); }.bind(this)).then(function (body) {
					if (!body || !Array.isArray(body.participants) || !body.summary) { throw new Error('Invalid participant response.'); }
					this.members.snapshot(body.participants, body.summary, body.sync || {}); var refreshed = this.members.container && this.members.container.querySelector('[data-member-key="user:' + parseInt(person.id, 10) + '"]'); if (refreshed) { this.dialogs.trigger = refreshed; }
					this.dialogs.close(); this.setState(labels[action] + ' succeeded.', 'success');
				}.bind(this)).catch(function (error) { confirm.disabled = false; reason.disabled = false; this.feedback(feedback, error && error.message); }.bind(this)).finally(function () { this.pending = false; }.bind(this));
			}.bind(this));
			node.appendChild(confirm);
		}.bind(this), trigger);
	};
	Moderation.prototype.remove = function (eventId, trigger) {
		if (!this.permitted() || this.pending || !parseInt(eventId, 10)) { return false; }
		return this.dialogs.open('Remove message?', function (node) {
			var copy = this.doc.createElement('p'); copy.textContent = 'The original content will be replaced for everyone and removed from search.'; node.appendChild(copy);
			var feedback = this.doc.createElement('p'); feedback.setAttribute('role', 'alert'); feedback.hidden = true; node.appendChild(feedback); node.appendChild(this.cancelButton());
			var confirm = this.doc.createElement('button'); confirm.type = 'button'; confirm.className = 'acl-ar-bevel-button'; confirm.dataset.confirmMessageRemoval = ''; confirm.textContent = 'Remove message';
			confirm.addEventListener('click', function () {
				if (this.pending) { return; } this.pending = true; confirm.disabled = true;
				this.api.removeMessage(eventId, '').then(function (body) { return this.onMessageRemoved ? this.onMessageRemoved(eventId, body) : body; }.bind(this)).then(function (focusTarget) {
					if (focusTarget && focusTarget.focus) { this.dialogs.trigger = focusTarget; } this.dialogs.close(); this.setState('Message removed.', 'success');
				}.bind(this)).catch(function (error) { confirm.disabled = false; this.feedback(feedback, error && error.message); }.bind(this)).finally(function () { this.pending = false; }.bind(this));
			}.bind(this));
			node.appendChild(confirm);
		}.bind(this), trigger);
	};
	return Moderation;
}));
