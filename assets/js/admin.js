/* global PDI_Settings, wp */
( function () {
	'use strict';

	window.PDI = window.PDI || {};

	var S = window.PDI_Settings || {};

	function el( tag, attrs, children ) {
		var e = document.createElement( tag );
		attrs = attrs || {};
		Object.keys( attrs ).forEach( function ( k ) {
			if ( 'class' === k ) {
				e.className = attrs[ k ];
			} else if ( 'html' === k ) {
				e.innerHTML = attrs[ k ];
			} else {
				e.setAttribute( k, attrs[ k ] );
			}
		} );
		( children || [] ).forEach( function ( c ) {
			if ( c ) {
				e.appendChild( c );
			}
		} );
		return e;
	}

	function ajax( action, data ) {
		var params = Object.assign( { action: action, nonce: S.nonce }, data );
		var body = Object.keys( params )
			.map( function ( k ) {
				return encodeURIComponent( k ) + '=' + encodeURIComponent( params[ k ] );
			} )
			.join( '&' );

		return fetch( S.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body,
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	/**
	 * Renders a search + results-grid UI into `container`.
	 * opts.onImport, if provided, is called with the imported attachment object.
	 * opts.autoSelect, if true, calls onImport immediately after a successful
	 * import instead of showing an explicit "use as featured image" button —
	 * used when the picker is embedded inside a WP media modal, where the
	 * modal's own toolbar button (Set featured image / Insert / Select)
	 * becomes the confirmation step instead.
	 */
	function PDIView( container, opts ) {
		this.container = container;
		this.opts = opts || {};
		this.state = { search: '', page: 1, totalPages: 1, loading: false };
		this.build();
	}

	PDIView.prototype.build = function () {
		var self = this;
		this.container.innerHTML = '';
		this.container.classList.add( 'pdi-app' );

		this.searchInput = el( 'input', {
			type: 'search',
			class: 'pdi-search-input',
			placeholder: S.strings.search,
		} );
		this.searchBtn = el( 'button', { type: 'button', class: 'button button-primary' }, [
			document.createTextNode( S.strings.search ),
		] );
		this.grid = el( 'div', { class: 'pdi-grid' } );
		this.status = el( 'div', { class: 'pdi-status' } );
		this.loadMoreBtn = el(
			'button',
			{ type: 'button', class: 'button pdi-load-more', style: 'display:none' },
			[ document.createTextNode( S.strings.loadMore ) ]
		);

		var form = el( 'div', { class: 'pdi-searchbar' }, [ this.searchInput, this.searchBtn ] );

		this.container.appendChild( form );
		this.container.appendChild( this.status );
		this.container.appendChild( this.grid );
		this.container.appendChild( this.loadMoreBtn );

		this.searchBtn.addEventListener( 'click', function () {
			self.doSearch( true );
		} );
		this.searchInput.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
				self.doSearch( true );
			}
		} );
		this.loadMoreBtn.addEventListener( 'click', function () {
			self.doSearch( false );
		} );

		this.doSearch( true );
	};

	PDIView.prototype.setStatus = function ( msg ) {
		this.status.textContent = msg || '';
	};

	PDIView.prototype.doSearch = function ( reset ) {
		var self = this;
		if ( this.state.loading ) {
			return;
		}
		if ( reset ) {
			this.state.page = 1;
			this.state.search = this.searchInput.value.trim();
			this.grid.innerHTML = '';
		}
		this.state.loading = true;
		this.setStatus( '…' );

		ajax( 'pdi_search', { search: this.state.search, page: this.state.page } )
			.then( function ( res ) {
				self.state.loading = false;

				if ( ! res.success ) {
					self.setStatus( ( res.data && res.data.message ) || S.strings.error );
					return;
				}

				var data = res.data;
				self.state.totalPages = data.totalPages || 1;

				if ( reset && ( ! data.photos || ! data.photos.length ) ) {
					self.setStatus( S.strings.noResults );
				} else {
					self.setStatus( '' );
				}

				( data.photos || [] ).forEach( function ( photo ) {
					self.renderCard( photo );
				} );

				self.loadMoreBtn.style.display = self.state.page < self.state.totalPages ? '' : 'none';
				self.state.page++;
			} )
			.catch( function () {
				self.state.loading = false;
				self.setStatus( S.strings.error );
			} );
	};

	PDIView.prototype.renderCard = function ( photo ) {
		var self = this;
		var img = el( 'img', { src: photo.thumbUrl, alt: photo.alt || photo.title, loading: 'lazy' } );
		var importBtn = el( 'button', { type: 'button', class: 'button button-small pdi-import-btn' }, [
			document.createTextNode( S.strings.import ),
		] );
		var actions = el( 'div', { class: 'pdi-card-actions' }, [ importBtn ] );
		var card = el( 'div', { class: 'pdi-card' }, [ img, actions ] );

		importBtn.addEventListener( 'click', function () {
			self.importPhoto( photo, card, importBtn );
		} );

		this.grid.appendChild( card );
	};

	PDIView.prototype.importPhoto = function ( photo, card, btn ) {
		var self = this;
		btn.disabled = true;
		btn.textContent = S.strings.importing;

		ajax( 'pdi_import', { photo_id: photo.id, size: 'full' } )
			.then( function ( res ) {
				if ( ! res.success ) {
					btn.disabled = false;
					btn.textContent = S.strings.import;
					window.alert( ( res.data && res.data.message ) || S.strings.error );
					return;
				}

				var attachment = res.data;
				card.classList.add( 'pdi-imported' );

				var actions = card.querySelector( '.pdi-card-actions' );
				actions.innerHTML = '';

				if ( self.opts.autoSelect && self.opts.onImport ) {
					actions.appendChild(
						el( 'span', { class: 'pdi-imported-label' }, [
							document.createTextNode( '✓ ' + S.strings.selected ),
						] )
					);
					self.opts.onImport( attachment );
					return;
				}

				actions.appendChild(
					el( 'span', { class: 'pdi-imported-label' }, [ document.createTextNode( '✓ ' + S.strings.imported ) ] )
				);

				actions.appendChild(
					el( 'a', { href: attachment.editUrl, target: '_blank', class: 'button button-small' }, [
						document.createTextNode( S.strings.viewInLibrary ),
					] )
				);

				if ( self.opts.onImport ) {
					var setBtn = el(
						'button',
						{ type: 'button', class: 'button button-small button-primary' },
						[ document.createTextNode( S.strings.useFeatured ) ]
					);
					setBtn.addEventListener( 'click', function () {
						self.opts.onImport( attachment );
					} );
					actions.appendChild( setBtn );
				}
			} )
			.catch( function () {
				btn.disabled = false;
				btn.textContent = S.strings.import;
				window.alert( S.strings.error );
			} );
	};

	window.PDI.render = function ( container, opts ) {
		return new PDIView( container, opts );
	};

	// ---- Reusable modal, used by the classic-editor button and the block-editor sidebar ----
	var modalEl = null;

	window.PDI.openModal = function ( opts ) {
		if ( modalEl ) {
			modalEl.style.display = 'flex';
			return;
		}

		var closeBtn = el( 'button', { type: 'button', class: 'pdi-modal-close', 'aria-label': S.strings.close }, [
			document.createTextNode( '×' ),
		] );
		var body = el( 'div', { class: 'pdi-modal-body' } );
		var box = el( 'div', { class: 'pdi-modal-box' }, [ closeBtn, body ] );
		modalEl = el( 'div', { class: 'pdi-modal-overlay' }, [ box ] );
		document.body.appendChild( modalEl );

		closeBtn.addEventListener( 'click', function () {
			modalEl.style.display = 'none';
		} );
		modalEl.addEventListener( 'click', function ( e ) {
			if ( e.target === modalEl ) {
				modalEl.style.display = 'none';
			}
		} );

		window.PDI.render( body, opts );
	};

	window.PDI.closeModal = function () {
		if ( modalEl ) {
			modalEl.style.display = 'none';
		}
	};

	document.addEventListener( 'DOMContentLoaded', function () {
		var page = document.querySelector( '#pdi-app[data-context="page"]' );
		if ( page ) {
			window.PDI.render( page );
		}

		document.querySelectorAll( '.pdi-open-modal' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				window.PDI.openModal( {
					onImport: function ( attachment ) {
						if ( window.wp && wp.media && wp.media.featuredImage ) {
							wp.media.featuredImage.set( attachment.id );
						}
						window.PDI.closeModal();
					},
				} );
			} );
		} );
	} );
} )();
