(function (root, factory) {
	'use strict';
	var Renderer = factory(root.ACLARRoomUtils || (typeof require==='function'?require('./utils.js'):null));
	if (typeof module === 'object' && module.exports) { module.exports = Renderer; }
	root.ACLARRoomRenderer = Renderer;
}(typeof globalThis !== 'undefined' ? globalThis : this, function (utils) {
	'use strict';
	function Renderer(list,currentUserId,doc){this.list=list;this.currentUserId=parseInt(currentUserId,10)||0;this.doc=doc||(typeof document!=='undefined'?document:null);}
	Renderer.prototype.visible=function(event){return event&&['message','system_notice'].indexOf(event.type)>=0;};
	Renderer.prototype.label=function(event){var actor=event.actor||{};if(actor.type==='user'&&parseInt(actor.id,10)===this.currentUserId){return 'You';}return actor.name||(actor.type==='agent'?'Agent':(actor.type==='system'?'Room system':'Former user'));};
	Renderer.prototype.initials=function(value){var words=utils.text(value||'Agent').trim().split(/\s+/);return ((words[0]||'A').charAt(0)+(words[1]||'').charAt(0)).toUpperCase();};
	Renderer.prototype.node=function(event){if(!this.visible(event)||!this.doc){return null;}var item=this.doc.createElement('article');var actor=event.actor||{};item.className='acl-ar-message acl-ar-message--'+(actor.type||'system');item.dataset.eventId=String(event.id||0);if(actor.type==='agent'){var avatar=this.doc.createElement('span');avatar.className='acl-ar-message__avatar'+(actor.avatar_url?'':' acl-ar-message__avatar--fallback');if(actor.avatar_url){var image=this.doc.createElement('img');image.src=actor.avatar_url;image.alt=actor.name||'Agent';avatar.appendChild(image);}else{avatar.setAttribute('aria-hidden','true');avatar.textContent=this.initials(actor.name);}item.appendChild(avatar);}var body=this.doc.createElement('div');body.className='acl-ar-message__body';var meta=this.doc.createElement('div');meta.className='acl-ar-message__meta';var strong=this.doc.createElement('strong');strong.textContent=this.label(event);var time=this.doc.createElement('time');time.textContent=utils.text(event.created_at);meta.appendChild(strong);meta.appendChild(time);var content=this.doc.createElement('div');content.className='acl-ar-message__content';content.textContent=utils.text(event.content);body.appendChild(meta);body.appendChild(content);item.appendChild(body);return item;};
	Renderer.prototype.clear=function(){if(!this.list){return;}this.list.querySelectorAll('.acl-ar-message').forEach(function(node){node.remove();});};
	Renderer.prototype.replace=function(events){this.clear();this.append(events,false);};
	Renderer.prototype.append=function(events,autoScroll){if(!this.list){return;}var fragment=this.doc.createDocumentFragment();(events||[]).forEach(function(event){if(this.list.querySelector('[data-event-id="'+parseInt(event.id,10)+'"]')){return;}var node=this.node(event);if(node){fragment.appendChild(node);}},this);this.list.appendChild(fragment);if(autoScroll){this.list.scrollTop=this.list.scrollHeight;}};
	Renderer.prototype.prepend=function(events){if(!this.list){return;}var before=this.list.scrollHeight;var fragment=this.doc.createDocumentFragment();(events||[]).forEach(function(event){if(this.list.querySelector('[data-event-id="'+parseInt(event.id,10)+'"]')){return;}var node=this.node(event);if(node){fragment.appendChild(node);}},this);this.list.insertBefore(fragment,this.list.firstChild);this.list.scrollTop+=this.list.scrollHeight-before;};
	return Renderer;
}));
