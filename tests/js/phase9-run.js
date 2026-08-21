'use strict';
const fs = require( 'fs' );
const path = require( 'path' );
const root = path.resolve( __dirname, '../..' );
const read = p => fs.readFileSync( path.join( root, p ), 'utf8' );
const api = read( 'assets/js/room/api.js' );
const search = read( 'assets/js/room/search.js' );
const moderation = read( 'assets/js/room/moderation.js' );
const app = read( 'assets/js/room.js' );
const css = read( 'assets/css/room-aol.css' );
const shortcode = read( 'includes/Shortcodes/AgentRoomShortcode.php' );
const source = [ api, search, moderation, app, css, shortcode ].join( '\n' );
const tokens = [
	'Api.prototype.search','Api.prototype.context','Api.prototype.moderate','Api.prototype.removeMessage','X-WP-Nonce','encodeURIComponent','ACLARRoomSearch','role="search"','aria-live','minlength="2"','maxlength="190"','data-search-result','Search results','No visible matches','context( parseInt','transcript.replace','ACLARRoomModeration','canModerate','confirmModeration','confirmMessageRemoval','removeMessage','this.api.moderate','phase9','searchController','moderationController','features','search','moderation','canSend','can_write_room','focus-visible','outline','acl-ar-room-app__search','overflow','max-height','private','no-store','next_cursor','eventId','target_user_id','reason','DELETE','POST','Content-Type','application/json','roomId','restBase','nonce','result','status','button','label','input','ol','section','hidden'
];
let n = 0;
function check( ok, label ) {
	n++;
	if ( ! ok ) {
		throw new Error( 'FAIL ' + label );
	}
}
tokens.forEach( token => check( source.includes( token ), token ) );
check( search.includes( "className = 'acl-ar-room-app__search'" ), 'search_panel_class' );
check( search.includes( "setAttribute( 'aria-controls'" ) && search.includes( "setAttribute( 'aria-expanded'" ), 'search_expanded_contract' );
check( search.includes( "event.key === 'Escape'" ) && search.includes( 'this.close( true )' ), 'search_escape_close' );
check( search.includes( 'this.trigger.focus()' ), 'search_focus_return' );
check( search.includes( "this.api.context( parseInt" ) && search.includes( ".catch( function ( error )" ), 'context_error_preserved' );
if ( n !== 61 ) {
	throw new Error( 'Expected 61 assertions, got ' + n );
}
console.log( 'PASS phase9_js_release assertions=' + n );
