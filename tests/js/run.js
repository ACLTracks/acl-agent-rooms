'use strict';

const fs = require('fs');
const path = require('path');
const Store = require('../../assets/js/room/store.js');
const Sync = require('../../assets/js/room/sync.js');
const Renderer = require('../../assets/js/room/render-compat.js');

let assertions = 0;
function assert(value, message) { assertions += 1; if (!value) { throw new Error(message); } }
function same(expected, actual, message) { assertions += 1; if (JSON.stringify(expected) !== JSON.stringify(actual)) { throw new Error(message + ' expected=' + JSON.stringify(expected) + ' actual=' + JSON.stringify(actual)); } }
function event(id, roomId, name) { return {id:id,room_id:roomId,type:'message',actor:{type:'user',id:2,name:name||'Other Human',avatar_url:null},content:'message '+id,created_at:'2026-07-11T12:00:00+00:00'}; }

class FakeNode {
	constructor(tag) { this.tag=tag;this.children=[];this.parentNode=null;this.dataset={};this.attributes={};this.className='';this.textContent='';this.scrollTop=0;this.clientHeight=40; }
	get scrollHeight(){return this.children.length*20;}
	get firstChild(){return this.children[0]||null;}
	appendChild(node){if(node.tag==='#fragment'){node.children.slice().forEach(child=>this.appendChild(child));return node;}node.parentNode=this;this.children.push(node);return node;}
	insertBefore(node,before){if(node.tag==='#fragment'){node.children.slice().forEach(child=>this.insertBefore(child,before));return node;}node.parentNode=this;let index=before?this.children.indexOf(before):-1;if(index<0){this.children.push(node);}else{this.children.splice(index,0,node);}return node;}
	setAttribute(key,value){this.attributes[key]=String(value);}
	remove(){if(this.parentNode){this.parentNode.children=this.parentNode.children.filter(child=>child!==this);}}
	querySelector(selector){let match=selector.match(/^\[data-event-id="(\d+)"\]$/);if(match){return this.children.find(child=>String(child.dataset.eventId)===match[1])||null;}return null;}
	querySelectorAll(selector){if(selector!=='.acl-ar-message'){return [];}return this.children.filter(child=>String(child.className).split(/\s+/).includes('acl-ar-message'));}
}
class FakeDocument { createElement(tag){return new FakeNode(tag);} createDocumentFragment(){return new FakeNode('#fragment');} }

async function run() {
	const store = new Store(7);
	store.initial([event(3,7),event(1,7)],{before_cursor:'b1',after_cursor:'a3',has_more_before:true});
	same([1,3],store.ids,'Store initial ordering failed.');
	store.merge([event(3,7)]);same([1,3],store.ids,'Store duplicate ID was not deduplicated.');
	store.merge([event(2,8)]);same([1,3],store.ids,'Store accepted cross-room event.');
	store.incremental([event(4,7)],{after_cursor:'a4'});same([1,3,4],store.ids,'Incremental merge lost existing events.');
	store.prepend([event(2,7)],{before_cursor:null,has_more_before:false});same([1,2,3,4],store.ids,'History prepend ordering failed.');
	same({before:null,after:'a4',more:false},{before:store.beforeCursor,after:store.afterCursor,more:store.hasMoreBefore},'Cursor state update failed.');
	const beforeEmpty=store.ids.slice();store.beforeCursor='history';store.hasMoreBefore=true;store.incremental([],{after_cursor:'a4',before_cursor:null,has_more_before:false});same(beforeEmpty,store.ids,'Empty poll mutated transcript.');assert(store.beforeCursor==='history'&&store.hasMoreBefore,'Incremental poll erased history cursor.');

	let resolvePoll;let calls=0;const overlapApi={events:()=>{calls+=1;return new Promise(resolve=>{resolvePoll=resolve;});}};const overlapStore=new Store(7);overlapStore.afterCursor='a0';const overlap=new Sync(overlapApi,overlapStore,{document:{hidden:false},navigator:{onLine:true}});const one=overlap.catchUp();const two=overlap.catchUp();assert(calls===1,'Poll overlap guard failed.');resolvePoll({events:[],paging:{after_cursor:'a0'}});await Promise.all([one,two]);
	const backoffStore=new Store(7);backoffStore.afterCursor='a0';const backoff=new Sync({events:()=>Promise.resolve({events:[event(5,7)],paging:{after_cursor:'a5'}})},backoffStore,{activeDelay:3000,idleDelay:6000,maxDelay:24000,document:{hidden:false},navigator:{onLine:true}});await backoff.catchUp();assert(backoff.delay===3000,'Polling backoff did not reset after new events.');
	let hiddenCalls=0;const paused=new Sync({events:()=>{hiddenCalls+=1;return Promise.resolve({events:[],paging:{}});}},new Store(7),{document:{hidden:true},navigator:{onLine:true}});await paused.catchUp();assert(hiddenCalls===0,'Hidden-tab polling was not paused.');
	backoffStore.setError('temporary');await backoff.catchUp();assert(backoffStore.connectionError==='', 'Successful recovery did not clear connection error.');

	const doc=new FakeDocument();const list=new FakeNode('section');const renderer=new Renderer(list,1,doc);const human=event(10,7,'Real Display Name');const node=renderer.node(human);assert(node.children[0].children[0].children[0].textContent==='Real Display Name','Compatibility renderer lost real human display name.');human.content='<img src=x onerror=alert(1)>';const safe=renderer.node(human);assert(safe.children[0].children[1].textContent==='<img src=x onerror=alert(1)>'&&!('innerHTML' in safe.children[0].children[1]),'Renderer did not keep content as text.');
	renderer.append([event(20,7),event(30,7)],false);list.scrollTop=10;const anchor=list.scrollTop;renderer.prepend([event(5,7)]);assert(list.scrollTop===anchor+20,'History prepend did not preserve simulated scroll anchor.');

	const roomSource=fs.readFileSync(path.join(__dirname,'../../assets/js/room.js'),'utf8');assert(/api\.send\([\s\S]*?sync\.immediate\(\)/.test(roomSource),'Legacy send does not trigger event catch-up.');assert(!/api\.send\([\s\S]{0,500}renderer\.replace/.test(roomSource),'Legacy send still replaces the transcript.');assert(roomSource.indexOf('setInterval')===-1&&roomSource.indexOf('visibilitychange')>=0,'Polling loop is not timeout/visibility based.');
	console.log('PASS phase4_js_transport assertions='+assertions);
}

run().catch(error=>{console.error('FAIL phase4_js_transport: '+error.message);process.exit(1);});
