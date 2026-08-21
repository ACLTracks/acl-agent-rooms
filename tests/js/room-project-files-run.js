'use strict';

const fs=require('fs'),path=require('path');
const Api=require('../../assets/js/room/api.js');
const RoomFiles=require('../../assets/js/room/room-files.js');
let assertions=0;
function ok(value,name){assertions++;if(!value){throw new Error('FAIL '+name);}console.log('PASS '+name);}
function same(expected,actual,name){ok(JSON.stringify(expected)===JSON.stringify(actual),name+' expected='+JSON.stringify(expected)+' actual='+JSON.stringify(actual));}

class Node{
	constructor(tag){this.tagName=tag;this.children=[];this.parentNode=null;this.dataset={};this.attributes={};this.listeners={};this.className='';this.textContent='';this.hidden=false;this.type='';this.id='';this.scrolled=false;this.classList={add:()=>{}};}
	appendChild(node){if(node.tagName==='#fragment'){node.children.slice().forEach(child=>this.appendChild(child));return node;}node.parentNode=this;this.children.push(node);return node;}
	insertBefore(node,ref){node.parentNode=this;let index=this.children.indexOf(ref);if(index<0){index=this.children.length;}this.children.splice(index,0,node);return node;}
	setAttribute(key,value){this.attributes[key]=String(value);}
	getAttribute(key){return this.attributes[key];}
	addEventListener(key,fn){this.listeners[key]=fn;}
	querySelector(selector){if(selector==='[data-room-file-selection-label]'){return descendants(this).find(n=>n.dataset.roomFileSelectionLabel)||null;}if(selector.indexOf('#acl-ar-file-')===0){return descendants(this).find(n=>'#'+n.id===selector)||null;}return null;}
	querySelectorAll(){return [];}
	scrollIntoView(){this.scrolled=true;}
}
class Doc{createElement(tag){return new Node(tag);}createTextNode(text){const n=new Node('#text');n.textContent=text;return n;}createDocumentFragment(){return new Node('#fragment');}}
function descendants(node){return (node.children||[]).reduce((all,n)=>all.concat(n,descendants(n)),[]);}
function fixture(sendImpl){
	const doc=new Doc(),root=new Node('section'),parent=new Node('main'),composer=new Node('form'),trigger=new Node('button');trigger.dataset.action='files';parent.appendChild(composer);root.querySelector=function(selector){if(selector==='[data-action="files"]'){return trigger;}if(selector==='[data-room-file-selection]'){return null;}if(selector==='.acl-ar-room__composer'){return composer;}if(selector==='.acl-ar-room-app__transcript'){return null;}return Node.prototype.querySelector.call(this,selector);};
	root.querySelectorAll=()=>[];
	const calls=[];const api={send(content,key,reply,ids){calls.push({content,key,reply,ids});return sendImpl?sendImpl():Promise.resolve({ok:true});},command(input,key,recipient){calls.push({command:input,key,recipient});return Promise.resolve({ok:true});},roomFiles(){return Promise.resolve({files:[]});},roomFileView(){return Promise.resolve({});}};
	const dialogs={open(){}};const files=new RoomFiles(root,api,dialogs,{room:{fileContextMaxFiles:2},permissions:{canSelectRoomFiles:true}},doc);return{doc,root,parent,composer,trigger,api,files,calls};
}

async function run(){
	let prettyRequest;const pretty=new Api({restBase:'http://localhost:10010/wp-json/acl-agent-rooms/v1',roomId:9,nonce:'n'},(url,options)=>{prettyRequest={url,options};return Promise.resolve({status:200,ok:true,headers:{get:()=>null},text:()=>Promise.resolve('{}')});});await pretty.send('Use files','key',0,[4,7]);same([4,7],JSON.parse(prettyRequest.options.body).room_file_ids,'api_sends_selected_file_ids');ok(prettyRequest.url.includes('/rooms/9/messages'),'pretty_message_route_preserved');
	let plainRequest;const plain=new Api({restBase:'http://localhost:10010/?rest_route=%2Facl-agent-rooms%2Fv1',roomId:9,nonce:'n'},(url,options)=>{plainRequest={url,options};return Promise.resolve({status:200,ok:true,headers:{get:()=>null},text:()=>Promise.resolve('{}')});});await plain.roomFileView(12,84,112);const parsed=new URL(plainRequest.url);same('/acl-agent-rooms/v1/rooms/9/files/12',parsed.searchParams.get('rest_route'),'plain_file_viewer_route');same('84',parsed.searchParams.get('start'),'plain_citation_start');same('112',parsed.searchParams.get('end'),'plain_citation_end');

	const f=fixture();f.files.files=[{id:4,label:'alpha.php'},{id:7,label:'beta.md'},{id:8,label:'gamma.txt'}];f.files.toggle(4,true);f.files.toggle(7,true);f.files.toggle(8,true);same([4,7],f.files.ids(),'selection_respects_max_files');same(false,f.files.banner.hidden,'selected_file_banner_visible');ok(f.files.banner.querySelector('[data-room-file-selection-label]').textContent.includes('alpha.php'),'selected_file_indicator_names_file');await f.api.send('next','request',0);same([4,7],f.calls[0].ids,'selection_applies_to_next_message');same([],f.files.ids(),'selection_clears_after_success');
	const failed=fixture(()=>Promise.reject(new Error('network')));failed.files.files=[{id:3,label:'keep.md'}];failed.files.toggle(3,true);try{await failed.api.send('retry','request',0);}catch(ignore){}same([3],failed.files.ids(),'failed_send_preserves_selection');
	const roomA=fixture(),roomB=fixture();roomA.files.files=[{id:1,label:'one.txt'}];roomA.files.toggle(1,true);same([],roomB.files.ids(),'selection_does_not_leak_to_another_room_instance');
	f.files.toggle(4,true);f.files.toggle(7,true);await f.api.command('/ask @agent evidence','ask-key',0);same([4,7],f.calls[1].ids,'ask_command_uses_selected_files');

	const citationFixture=fixture();const content=new Node('div');content.className='acl-ar-chat-line__content';content.textContent='Grounded fact [alpha.php, lines 84-112].';citationFixture.root.querySelectorAll=selector=>selector.indexOf('.acl-ar-chat-line__content')===0?[content]:[];citationFixture.files.decorateCitations();const citation=descendants(content).find(n=>n.dataset.fileCitation);ok(!!citation,'citation_is_rendered_as_control');same('alpha.php',citation.dataset.fileLabel,'citation_label_parsed');same('84',citation.dataset.fileStart,'citation_start_parsed');same('112',citation.dataset.fileEnd,'citation_end_parsed');same('button',citation.type,'citation_is_keyboard_button');ok(citation.attributes['aria-label'].includes('lines 84 to 112'),'citation_has_accessible_label');

	const base=path.resolve(__dirname,'../..'),roomFilesSource=fs.readFileSync(path.join(base,'assets/js/room/room-files.js'),'utf8'),adminSource=fs.readFileSync(path.join(base,'assets/js/admin-room-files.js'),'utf8'),roomSource=fs.readFileSync(path.join(base,'assets/js/room.js'),'utf8'),css=fs.readFileSync(path.join(base,'assets/css/room.css'),'utf8')+fs.readFileSync(path.join(base,'assets/css/room-aol.css'),'utf8');
	ok(roomFilesSource.includes('This does not upload a new file.'),'composer_files_control_is_selection_only');ok(!roomFilesSource.includes("form.append('file'"),'composer_has_no_upload_implementation');ok(adminSource.includes("form.append('file'"),'settings_panel_owns_upload_flow');ok(adminSource.includes('storage_asset_id'),'settings_panel_can_attach_existing_asset');ok(adminSource.includes('Delete storage asset'),'underlying_delete_is_explicit_action');ok(adminSource.includes('line.text')&&!adminSource.includes('innerHTML = line'),'viewer_escapes_file_content');ok(roomFilesSource.includes('textContent=String(line.number)'),'live_viewer_escapes_file_content');ok(roomFilesSource.includes('scrollIntoView'),'citation_jump_scrolls_to_line');ok(roomSource.includes('new ACLARRoomFiles'),'room_mounts_file_selection_controller');ok(roomSource.includes('handleClear')&&!roomSource.includes('roomFiles.clear()'),'clear_chat_does_not_detach_persistent_files');ok(css.includes('prefers-reduced-motion'),'reduced_motion_contract_retained');ok(css.includes('forced-colors')||css.includes('contrast'),'high_contrast_contract_retained');
	console.log('PASS room_project_files_js assertions='+assertions);
}
run().catch(error=>{console.error(error.stack||error);process.exit(1);});
