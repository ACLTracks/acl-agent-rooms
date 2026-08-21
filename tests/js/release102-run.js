'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const Api = require( '../../assets/js/room/api.js' );

const prettyBase = 'https://example.test/wp-json/acl-agent-rooms/v1';
const plainBase = 'https://example.test/index.php?rest_route=/acl-agent-rooms/v1';
let assertions = 0;

function check( value, message ) {
	assertions += 1;
	if ( ! value ) {
		throw new Error( message );
	}
}

function same( expected, actual, message ) {
	assertions += 1;
	if ( expected !== actual ) {
		throw new Error( message + ' expected=' + JSON.stringify( expected ) + ' actual=' + JSON.stringify( actual ) );
	}
}

function parsed( value ) {
	return new URL( value );
}

function routeOf( value ) {
	const url = parsed( value );
	return url.searchParams.get( 'rest_route' ) || url.pathname;
}

function successfulResponse() {
	return {
		status: 200,
		ok: true,
		headers: { get: () => null },
		text: () => Promise.resolve( '{}' )
	};
}

async function capture( base, method, args ) {
	let request;
	const api = new Api( { restBase: base, roomId: 7, nonce: 'nonce-102' }, ( url, options ) => {
		request = { url, options };
		return Promise.resolve( successfulResponse() );
	} );
	await api[ method ].apply( api, args || [] );
	return request;
}

async function run() {
	const build = Api.buildRestUrl;

	same( prettyBase + '/rooms/7/events', build( prettyBase, '/rooms/7/events' ), 'Pretty base without query failed.' );
	same( '50', parsed( build( prettyBase, '/rooms/7/events', { limit: 50 } ) ).searchParams.get( 'limit' ), 'Pretty single query failed.' );
	let url = parsed( build( prettyBase, '/rooms/7/events', { limit: 50, after_cursor: 'abc' } ) );
	same( '50', url.searchParams.get( 'limit' ), 'Pretty multi-query limit failed.' );
	same( 'abc', url.searchParams.get( 'after_cursor' ), 'Pretty multi-query cursor failed.' );
	same( '/acl-agent-rooms/v1/rooms/7/events', routeOf( build( plainBase, '/rooms/7/events' ) ), 'Plain base without query failed.' );
	same( '50', parsed( build( plainBase, '/rooms/7/events', { limit: 50 } ) ).searchParams.get( 'limit' ), 'Plain single query failed.' );
	url = parsed( build( plainBase, '/rooms/7/events', { limit: 50, after_cursor: 'abc' } ) );
	same( '/acl-agent-rooms/v1/rooms/7/events', url.searchParams.get( 'rest_route' ), 'Plain multi-query route failed.' );
	same( 'abc', url.searchParams.get( 'after_cursor' ), 'Plain multi-query cursor failed.' );
	url = parsed( build( plainBase + '&site=staging', '/rooms/7/events', { limit: 50 } ) );
	same( 'staging', url.searchParams.get( 'site' ), 'Existing unrelated base query was lost.' );
	url = parsed( build( plainBase + '&_locale=user', '/rooms/7/events', { limit: 50 } ) );
	same( 'user', url.searchParams.get( '_locale' ), 'Existing locale query was lost.' );
	const appended = routeOf( build( plainBase, '/rooms/7/events' ) );
	same( 1, ( appended.match( /rooms\/7\/events/g ) || [] ).length, 'Endpoint was appended more than once.' );
	same( '/acl-agent-rooms/v1/rooms/7/events', routeOf( build( plainBase, 'rooms/7/events' ) ), 'Leading route slash was not normalized.' );
	same( '/wp-json/acl-agent-rooms/v1/rooms/7/events', routeOf( build( prettyBase + '/', '/rooms/7/events' ) ), 'Trailing pretty base slash was not normalized.' );
	same( '/acl-agent-rooms/v1/rooms/7/events', routeOf( build( 'https://example.test/index.php?rest_route=%2Facl-agent-rooms%2Fv1', '/rooms/7/events' ) ), 'Encoded REST route failed.' );

	const beforeCursor = 'eyJiZWZvcmUiOjEyM30.sig+/=%25';
	const afterCursor = 'eyJhZnRlciI6NDU2fQ.sig+/=%2F';
	const searchCursor = 'eyJxdWVyeSI6IsOpIn0.search+/=%';
	same( beforeCursor, parsed( build( plainBase, '/rooms/7/events', { before_cursor: beforeCursor } ) ).searchParams.get( 'before_cursor' ), 'Signed before cursor changed.' );
	same( afterCursor, parsed( build( plainBase, '/rooms/7/events', { after_cursor: afterCursor } ) ).searchParams.get( 'after_cursor' ), 'Signed after cursor changed.' );
	same( searchCursor, parsed( build( plainBase, '/rooms/7/search', { cursor: searchCursor } ) ).searchParams.get( 'cursor' ), 'Search cursor changed.' );
	url = parsed( build( prettyBase, '/rooms/7/search', { q: 'hello café 世界' } ) );
	same( 'hello café 世界', url.searchParams.get( 'q' ), 'Spaces or Unicode did not round-trip.' );
	url = parsed( build( plainBase, '/rooms/7/events', { zero: 0, disabled: false } ) );
	same( '0', url.searchParams.get( 'zero' ), 'Numeric zero was lost.' );
	same( 'false', url.searchParams.get( 'disabled' ), 'Boolean false was lost.' );
	url = parsed( build( plainBase, '/rooms/7/events', { absent: null, missing: undefined, present: '' } ) );
	check( ! url.searchParams.has( 'absent' ) && ! url.searchParams.has( 'missing' ), 'Null or undefined query value was retained.' );
	check( url.searchParams.has( 'present' ), 'Intentional empty query value was lost.' );
	const query = { limit: 50, cursor: searchCursor, absent: null };
	const snapshot = JSON.stringify( query );
	build( plainBase, '/rooms/7/search', query );
	same( snapshot, JSON.stringify( query ), 'Query input was mutated.' );
	let absoluteRejected = false;
	try { build( prettyBase, 'https://attacker.test/rooms/7' ); } catch ( error ) { absoluteRejected = error instanceof TypeError; }
	check( absoluteRejected, 'Absolute endpoint origin was accepted.' );
	let protocolRejected = false;
	try { build( plainBase, '/rooms/7/https://attacker.test' ); } catch ( error ) { protocolRejected = error instanceof TypeError; }
	check( protocolRejected, 'Injected endpoint protocol was accepted.' );
	url = parsed( build( plainBase, '/rooms/7/search', { q: 'route attack', rest_route: '/outside/v1' } ) );
	same( '/acl-agent-rooms/v1/rooms/7/search', url.searchParams.get( 'rest_route' ), 'Query value altered REST route.' );
	same( 1, url.searchParams.getAll( 'rest_route' ).length, 'Reserved REST route query key was not rejected.' );

	let request = await capture( prettyBase, 'events', [ { limit: 50, afterCursor }, null ] );
	same( '/wp-json/acl-agent-rooms/v1/rooms/7/events', routeOf( request.url ), 'Pretty event polling bypassed composer.' );
	same( afterCursor, parsed( request.url ).searchParams.get( 'after_cursor' ), 'Pretty event cursor changed.' );
	request = await capture( plainBase, 'events', [ { limit: 50, beforeCursor }, null ] );
	same( '/acl-agent-rooms/v1/rooms/7/events', routeOf( request.url ), 'Plain event polling bypassed composer.' );
	same( beforeCursor, parsed( request.url ).searchParams.get( 'before_cursor' ), 'Plain history cursor changed.' );
	request = await capture( plainBase, 'send', [ 'hello', 'request-1', null ] );
	same( '/acl-agent-rooms/v1/rooms/7/messages', routeOf( request.url ), 'Plain message POST bypassed composer.' );
	same( 'POST', request.options.method, 'Message method changed.' );
	for ( const input of [ '/roll d20', '/coin', '/me waves', '/whisper User hello' ] ) {
		request = await capture( plainBase, 'command', [ input, 'request-command', 2 ] );
		same( '/acl-agent-rooms/v1/rooms/7/commands', routeOf( request.url ), 'Plain command bypassed composer: ' + input );
	}
	request = await capture( plainBase, 'heartbeat', [ 'session-1', 'visible', 'active' ] );
	same( '/acl-agent-rooms/v1/rooms/7/presence/heartbeat', routeOf( request.url ), 'Plain presence heartbeat bypassed composer.' );
	request = await capture( plainBase, 'leavePresence', [ 'session-1', true ] );
	same( '/acl-agent-rooms/v1/rooms/7/presence/session', routeOf( request.url ), 'Plain presence deletion bypassed composer.' );
	request = await capture( plainBase, 'participants' );
	same( '/acl-agent-rooms/v1/rooms/7/participants', routeOf( request.url ), 'Plain participants request bypassed composer.' );
	request = await capture( plainBase, 'participation', [ 4, 'paused', true, 'request-participation' ] );
	same( '/acl-agent-rooms/v1/rooms/7/agents/4/participation', routeOf( request.url ), 'Plain participation bypassed composer.' );
	request = await capture( plainBase, 'search', [ 'hello', searchCursor, 20 ] );
	same( '/acl-agent-rooms/v1/rooms/7/search', routeOf( request.url ), 'Plain search bypassed composer.' );
	same( searchCursor, parsed( request.url ).searchParams.get( 'cursor' ), 'Plain search cursor changed in request.' );
	request = await capture( plainBase, 'context', [ 99, 4 ] );
	same( '/acl-agent-rooms/v1/rooms/7/events/99/context', routeOf( request.url ), 'Plain search context bypassed composer.' );
	request = await capture( plainBase, 'moderate', [ 3, 'mute', 'test' ] );
	same( '/acl-agent-rooms/v1/rooms/7/moderation', routeOf( request.url ), 'Plain moderation bypassed composer.' );
	request = await capture( plainBase, 'removeMessage', [ 99, 'test' ] );
	same( '/acl-agent-rooms/v1/rooms/7/events/99', routeOf( request.url ), 'Plain message removal bypassed composer.' );

	const apiSource = fs.readFileSync( path.join( __dirname, '../../assets/js/room/api.js' ), 'utf8' );
	same( 1, ( apiSource.match( /this\.fetcher\(/g ) || [] ).length, 'Frontend API introduced another request dispatch surface.' );
	check( ! apiSource.includes( "(this.config.restBase||'').replace" ) && ! /config\.restBase\s*\+/.test( apiSource ), 'Unsafe REST URL concatenation remains.' );
	check( apiSource.includes( 'Api.buildRestUrl = buildRestUrl' ), 'Central composer is not exported for deterministic verification.' );

	console.log( 'PASS release_102_rest_url assertions=' + assertions );
}

run().catch( error => {
	console.error( 'FAIL release_102_rest_url: ' + error.message );
	process.exit( 1 );
} );
