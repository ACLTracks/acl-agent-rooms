(function ( root, factory ) {
	'use strict';
	var Search = factory();
	if ( typeof module === 'object' && module.exports ) {
		module.exports = Search;
	}
	root.ACLARRoomSearch = Search;
}( typeof globalThis !== 'undefined' ? globalThis : this, function () {
	'use strict';

	function Search( root, api, transcript, doc ) {
		this.root = root;
		this.api = api;
		this.transcript = transcript;
		this.doc = doc || document;
		this.panel = this.doc.createElement( 'section' );
		this.panel.className = 'acl-ar-room-app__search';
		this.panel.dataset.searchPanel = '';
		this.panel.id = 'acl-ar-search-' + ( root.dataset.roomId || 'room' );
		this.panel.hidden = true;
		this.panel.innerHTML = '<form data-search-form role="search"><label>Search this room <input type="search" minlength="2" maxlength="190" data-search-input></label><button type="submit">Search</button><button type="button" data-action="search-close">Close</button></form><div data-search-status role="status" aria-live="polite"></div><ol data-search-results></ol>';

		var toolbar = root.querySelector( '.acl-ar-room-app__toolbar' );
		this.trigger = this.doc.createElement( 'button' );
		this.trigger.type = 'button';
		this.trigger.dataset.action = 'search';
		this.trigger.textContent = 'Search';
		this.trigger.setAttribute( 'aria-label', 'Search this room' );
		this.trigger.setAttribute( 'aria-controls', this.panel.id );
		this.trigger.setAttribute( 'aria-expanded', 'false' );
		toolbar.insertBefore( this.trigger, toolbar.firstChild );
		toolbar.parentNode.insertBefore( this.panel, toolbar );

		this.form = this.panel.querySelector( 'form' );
		this.input = this.panel.querySelector( 'input' );
		this.results = this.panel.querySelector( 'ol' );
		this.status = this.panel.querySelector( '[role="status"]' );
		this.bind();
	}

	Search.prototype.open = function () {
		this.panel.hidden = false;
		this.trigger.setAttribute( 'aria-expanded', 'true' );
		this.input.focus();
	};

	Search.prototype.close = function ( restoreFocus ) {
		this.panel.hidden = true;
		this.trigger.setAttribute( 'aria-expanded', 'false' );
		if ( restoreFocus ) {
			this.trigger.focus();
		}
	};

	Search.prototype.clearForCutoff = function () {
		this.results.textContent = '';
		this.status.textContent = '';
		this.close( false );
	};

	Search.prototype.bind = function () {
		this.root.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-action]' );
			if ( ! button ) {
				return;
			}
			if ( button.dataset.action === 'search' ) {
				this.open();
			}
			if ( button.dataset.action === 'search-close' ) {
				this.close( true );
			}
			if ( button.dataset.action === 'search-result' ) {
				this.api.context( parseInt( button.dataset.eventId, 10 ), 3 ).then( function ( body ) {
					this.transcript.replace( body.events || [] );
					this.close( true );
				}.bind( this ) ).catch( function ( error ) {
					this.status.textContent = error.message;
				}.bind( this ) );
			}
		}.bind( this ) );

		this.panel.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' ) {
				event.preventDefault();
				this.close( true );
			}
		}.bind( this ) );

		this.form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var query = this.input.value.trim();
			if ( query.length < 2 ) {
				this.status.textContent = 'Enter at least 2 characters.';
				return;
			}
			this.status.textContent = 'Searching…';
			this.api.search( query, '', 20 ).then( function ( body ) {
				this.results.textContent = '';
				( body.results || [] ).forEach( function ( roomEvent ) {
					var item = this.doc.createElement( 'li' );
					var button = this.doc.createElement( 'button' );
					button.type = 'button';
					button.dataset.action = 'search-result';
					button.dataset.eventId = roomEvent.id;
					button.textContent = ( roomEvent.actor && roomEvent.actor.name ? roomEvent.actor.name + ': ' : '' ) + ( roomEvent.content || '' );
					item.appendChild( button );
					this.results.appendChild( item );
				}, this );
				this.status.textContent = ( body.results || [] ).length ? 'Search results' : 'No visible matches';
			}.bind( this ) ).catch( function ( error ) {
				this.status.textContent = error.message;
			}.bind( this ) );
		}.bind( this ) );
	};

	return Search;
} ) );
