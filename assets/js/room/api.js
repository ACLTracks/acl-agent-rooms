(function (root, factory) {
	'use strict';
	var Api = factory();
	if (typeof module === 'object' && module.exports) { module.exports = Api; }
	root.ACLARRoomApi = Api;
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
	'use strict';

	function normalizePath(path) {
		path = String(path || '').replace(/\\/g, '/').replace(/\/{2,}/g, '/');
		return '/' + path.replace(/^\/+|\/+$/g, '');
	}

	function endpointPath(routePath) {
		var route = String(routePath || '');
		if (/^[a-z][a-z\d+.-]*:/i.test(route) || /^\/\//.test(route) || route.indexOf('://') !== -1 || /[?#]/.test(route)) {
			throw new TypeError('ACL Agent Rooms REST endpoint paths must be relative route paths.');
		}
		return normalizePath(route);
	}

	function appendRoute(baseRoute, routePath) {
		var base = normalizePath(baseRoute);
		var route = endpointPath(routePath);
		if (route === base || route.indexOf(base + '/') === 0) {
			return route;
		}
		return (base === '/' ? '' : base) + route;
	}

	function addQueryParameters(url, queryParameters) {
		if (!queryParameters) { return; }
		if (typeof URLSearchParams === 'function' && queryParameters instanceof URLSearchParams) {
			queryParameters.forEach(function (value, key) {
				if (key !== 'rest_route') { url.searchParams.append(key, value); }
			});
			return;
		}
		Object.keys(queryParameters).forEach(function (key) {
			var value = queryParameters[key];
			if (key !== 'rest_route' && value !== undefined && value !== null) {
				url.searchParams.append(key, String(value));
			}
		});
	}

	function buildRestUrl(restBase, routePath, queryParameters) {
		if (typeof URL !== 'function' || typeof URLSearchParams !== 'function') {
			throw new Error('ACL Agent Rooms requires the browser URL APIs.');
		}
		var url = new URL(String(restBase || ''));
		var route = endpointPath(routePath);
		if (url.searchParams.has('rest_route')) {
			url.searchParams.set('rest_route', appendRoute(url.searchParams.get('rest_route'), route));
		} else {
			url.pathname = appendRoute(url.pathname, route);
		}
		addQueryParameters(url, queryParameters);
		return url.toString();
	}

	function Api(config, fetcher) {
		this.config = config || {};
		this.fetcher = fetcher || (typeof fetch === 'function' ? fetch.bind(globalThis) : null);
		this.etags = {};
	}

	Api.buildRestUrl = buildRestUrl;

	Api.prototype.request = function (path, options, queryParameters) {
		options = options || {};
		var requestUrl = buildRestUrl(this.config.restBase || '', path, queryParameters);
		var headers = Object.assign({'Accept': 'application/json', 'X-WP-Nonce': this.config.nonce || ''}, options.headers || {});
		if (this.etags[requestUrl]) { headers['If-None-Match'] = this.etags[requestUrl]; }
		return this.fetcher(requestUrl, Object.assign({}, options, {headers: headers})).then(function (response) {
			if (response.status === 304) { return {notModified: true}; }
			var etag = response.headers && response.headers.get ? response.headers.get('ETag') : null;
			if (etag) { this.etags[requestUrl] = etag; }
			return response.text().then(function (raw) {
				var body = {};
				try { body = raw ? JSON.parse(raw) : {}; } catch (ignore) { body = {}; }
				if (!response.ok) {
					var error = new Error(body.message || 'The room request failed.');
					error.code = body.code || 'acl_ar_request_failed';
					error.status = response.status;
					throw error;
				}
				return body;
			});
		}.bind(this));
	};

	Api.prototype.events = function (params, signal) {
		params = params || {};
		return this.request('/rooms/' + encodeURIComponent(this.config.roomId) + '/events', {signal: signal}, {
			limit: params.limit === undefined || params.limit === null ? 50 : params.limit,
			before_cursor: params.beforeCursor,
			after_cursor: params.afterCursor
		});
	};
	Api.prototype.legacyMessages = function () { return this.request('/rooms/' + encodeURIComponent(this.config.roomId) + '/messages', {}, {limit: 80}); };
	Api.prototype.send = function (content, requestId, replyTo, roomFileIds) { var body = {content: content, client_request_id: requestId}; if (replyTo) { body.reply_to_event_id = parseInt(replyTo, 10); } if (roomFileIds && roomFileIds.length) { body.room_file_ids = roomFileIds.map(function (id) { return parseInt(id, 10); }); } return this.request('/rooms/' + encodeURIComponent(this.config.roomId) + '/messages', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)}); };
	Api.prototype.work = function (brainRunIds, jobIds) { return this.request('/rooms/' + encodeURIComponent(this.config.roomId) + '/work', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({brain_run_ids: brainRunIds || [], job_ids: jobIds || []})}); };
	Api.prototype.roomFiles = function () { return this.request('/rooms/' + encodeURIComponent(this.config.roomId) + '/files'); };
	Api.prototype.roomFileView = function (fileId, start, end) { return this.request('/rooms/' + encodeURIComponent(this.config.roomId) + '/files/' + encodeURIComponent(fileId), {}, {start: start || 1, end: end || 200}); };
	Api.prototype.edit = function (eventId, content, requestId) { return this.request('/rooms/' + encodeURIComponent(this.config.roomId) + '/events/' + encodeURIComponent(eventId), {method:'PATCH', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({content: content, client_request_id: requestId})}); };
	Api.prototype.react = function (eventId, reaction, operation, requestId) { return this.request('/rooms/' + encodeURIComponent(this.config.roomId) + '/events/' + encodeURIComponent(eventId) + '/reactions/' + encodeURIComponent(reaction), {method:operation==='remove'?'DELETE':'PUT', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({client_request_id: requestId})}); };
	Api.prototype.readState = function () { return this.request('/rooms/' + encodeURIComponent(this.config.roomId) + '/read-state'); };
	Api.prototype.advanceRead = function (eventId) { return this.request('/rooms/' + encodeURIComponent(this.config.roomId) + '/read-state', {method: 'PATCH', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({last_read_event_id: parseInt(eventId, 10) || 0})}); };
	Api.prototype.command = function (input, requestId, recipientId) { var body = {input: input, client_request_id: requestId}; if (recipientId) { body.recipient_user_id = parseInt(recipientId, 10); } return this.request('/rooms/' + encodeURIComponent(this.config.roomId) + '/commands', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)}); };
	Api.prototype.reply = function (agentId) { return this.request('/rooms/' + encodeURIComponent(this.config.roomId) + '/agents/' + encodeURIComponent(agentId) + '/reply', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: '{}'}); };
	Api.prototype.participants = function () { return this.request('/rooms/' + encodeURIComponent(this.config.roomId) + '/participants'); };
	Api.prototype.heartbeat = function (sessionId, visibility, activity) { return this.request('/rooms/' + encodeURIComponent(this.config.roomId) + '/presence/heartbeat', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({session_id: sessionId, visibility_state: visibility, activity_state: activity})}); };
	Api.prototype.leavePresence = function (sessionId, keepalive) { return this.request('/rooms/' + encodeURIComponent(this.config.roomId) + '/presence/session', {method: 'DELETE', keepalive: !!keepalive, headers: {'Content-Type': 'application/json'}, body: JSON.stringify({session_id: sessionId})}); };
	Api.prototype.participation = function (agentId, state, muted, requestId) { return this.request('/rooms/' + encodeURIComponent(this.config.roomId) + '/agents/' + encodeURIComponent(agentId) + '/participation', {method: 'PATCH', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({participation_state: state, auto_muted: !!muted, client_request_id: requestId})}); };
	Api.prototype.search = function (query, cursor, limit) { return this.request('/rooms/' + encodeURIComponent(this.config.roomId) + '/search', {}, {q: query, limit: limit || 20, cursor: cursor || undefined}); };
	Api.prototype.context = function (eventId, radius) { return this.request('/rooms/' + encodeURIComponent(this.config.roomId) + '/events/' + encodeURIComponent(eventId) + '/context', {}, {radius: radius || 3}); };
	Api.prototype.moderate = function (targetUserId, action, reason) { return this.request('/rooms/' + encodeURIComponent(this.config.roomId) + '/moderation', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({target_user_id: parseInt(targetUserId, 10), action: action, reason: reason || ''})}); };
	Api.prototype.removeMessage = function (eventId, reason) { return this.request('/rooms/' + encodeURIComponent(this.config.roomId) + '/events/' + encodeURIComponent(eventId), {method: 'DELETE', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({reason: reason || ''})}); };
	Api.prototype.clearRoom = function (idempotencyKey) { return this.request('/rooms/' + encodeURIComponent(this.config.roomId) + '/clear', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({confirm:true,idempotency_key:String(idempotencyKey||'')})}); };

	return Api;
}));
