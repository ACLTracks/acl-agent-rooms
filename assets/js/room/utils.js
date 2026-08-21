(function (root, factory) {
	'use strict';
	var api = factory();
	if (typeof module === 'object' && module.exports) { module.exports = api; }
	root.ACLARRoomUtils = api;
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
	'use strict';
	function text(value) { return String(value === null || value === undefined ? '' : value); }
	function number(value) { var parsed = parseInt(value, 10); return isFinite(parsed) ? parsed : 0; }
	function nearBottom(element, threshold) { return !element || element.scrollHeight - element.scrollTop - element.clientHeight <= (threshold || 80); }
	function legacyMessageToEvent(message, roomId) {
		var type = message.sender_type === 'agent' ? 'agent' : (message.sender_type === 'system' ? 'system' : 'user');
		return { id:number(message.id), room_id:number(roomId), type:message.sender_type === 'system' ? 'system_notice' : 'message', actor:{type:type,id:number(message.sender_user_id || message.sender_agent_id) || null,name:type === 'agent' ? (message.agent_name || 'Agent') : (type === 'system' ? 'Room system' : 'You'),avatar_url:message.avatar_url || null}, target:null, audience:{type:'room'}, parent_event_id:null, content:text(message.content), content_format:'plain', metadata:{}, created_at:text(message.created_at), edited_at:null, deleted_at:null };
	}
	return { text:text, number:number, nearBottom:nearBottom, legacyMessageToEvent:legacyMessageToEvent };
}));
