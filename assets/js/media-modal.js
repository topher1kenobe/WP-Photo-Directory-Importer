/* global wp, PDI_Modal */
/**
 * "Photo Directory" tab inside the native wp.media modal.
 *
 * Built with Backbone views because that is what wp.media itself is: the tab
 * lives inside a core frame region, so matching the surrounding architecture
 * keeps it working with core's own routing, selection and toolbar.
 *
 * The layout mirrors core's Media Library tab: a browsing pane on the left
 * and an "Attachment details" sidebar on the right, reflecting the last
 * photo clicked.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.media || ! wp.media.view || ! wp.media.view.MediaFrame ) {
		return;
	}

	var media   = wp.media;
	var S       = window.PDI_Modal || {};
	var strings = S.strings || {};
	var TAB_ID  = 'pdi-photo-directory';

	var TAX_CATEGORY    = 'photo-categories';
	var TAX_ORIENTATION = 'photo-orientations';

	// ---------------------------------------------------------------- utils

	function format( template, values ) {
		var auto = 0;
		return String( template || '' ).replace( /%(?:(\d+)\$)?s/g, function ( match, position ) {
			var index = position ? parseInt( position, 10 ) - 1 : auto++;
			return 'undefined' !== typeof values[ index ] ? values[ index ] : '';
		} );
	}

	/**
	 * Wraps wp.i18n._n() so the plural form is chosen using the current
	 * locale's real plural rule, rather than a hardcoded "count === 1"
	 * check — English only has two plural forms, but many languages have
	 * three, four, or six, and a locale-aware _n() is the only way a
	 * translator can supply the right number of them. Falls back to a
	 * plain English-style check if wp.i18n isn't available for some
	 * reason ('wp-i18n' is a hard dependency of this script, so that
	 * should never actually happen).
	 *
	 * @param {string} single Singular source string, with a %s placeholder.
	 * @param {string} plural Plural source string, with a %s placeholder.
	 * @param {number} count  The actual count.
	 * @return {string} The chosen template, not yet interpolated — pass to format().
	 */
	function ni18n( single, plural, count ) {
		if ( wp.i18n && wp.i18n._n ) {
			return wp.i18n._n( single, plural, count, 'wp-photo-directory-importer' );
		}
		return 1 === count ? single : plural;
	}

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			if ( 'class' === key ) {
				node.className = attrs[ key ];
			} else if ( 'text' === key ) {
				node.textContent = attrs[ key ];
			} else if ( null !== attrs[ key ] && false !== attrs[ key ] ) {
				node.setAttribute( key, attrs[ key ] );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( child );
			}
		} );
		return node;
	}

	function ajax( action, data ) {
		var params = Object.assign( { action: action, nonce: S.nonce }, data || {} );
		var body   = Object.keys( params )
			.filter( function ( key ) {
				return null !== params[ key ] && 'undefined' !== typeof params[ key ];
			} )
			.map( function ( key ) {
				return encodeURIComponent( key ) + '=' + encodeURIComponent( params[ key ] );
			} )
			.join( '&' );

		return fetch( S.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body,
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function formatBytes( bytes ) {
		if ( ! bytes ) {
			return '';
		}
		var units = [ 'B', 'KB', 'MB', 'GB' ];
		var index = 0;
		var value = bytes;
		while ( value >= 1024 && index < units.length - 1 ) {
			value /= 1024;
			index++;
		}
		return ( value >= 10 || 0 === index ? Math.round( value ) : value.toFixed( 1 ) ) + ' ' + units[ index ];
	}

	function mimeLabel( mime ) {
		return mime ? String( mime ).replace( 'image/', '' ).toUpperCase() : '';
	}

	// ----------------------------------------------------------- the content

	var ContentView = media.View.extend( {
		className: 'pdi-tab',

		initialize: function () {
			this.terms   = {};
			this.results = [];
			this.selected = [];
			this.photos  = {};
			this.edits   = {};
			this.importedMap = {};
			this.lastClicked = null;
			this.size    = ( S.sizes && S.sizes[ 0 ] && S.sizes[ 0 ].value ) || 'full';
			this.query   = '';
			this.filters = { category: 0, orientation: 0, sort: 'relevance' };
			this.page    = 1;
			this.hasMore = false;
			this.busy    = false;
			this.requestId = 0;
			this.lightboxEl = null;
			this.lightboxKeydown = null;
		},

		render: function () {
			media.View.prototype.render.apply( this, arguments );

			this.gridEl    = el( 'div', { class: 'pdi-tab__grid' } );
			this.statusEl  = el( 'div', { class: 'pdi-tab__status' } );
			this.moreEl    = el( 'div', { class: 'pdi-tab__more' } );
			this.sidebarEl = el( 'div', { class: 'pdi-tab__sidebar' } );
			this.footerEl  = el( 'div', { class: 'pdi-tab__footer' } );

			var browse = el( 'div', { class: 'pdi-tab__browse' }, [
				this.renderControls(),
				el( 'div', { class: 'pdi-tab__scroll' }, [ this.statusEl, this.gridEl, this.moreEl ] ),
			] );

			this.el.appendChild( el( 'div', { class: 'pdi-tab__main' }, [ browse, this.sidebarEl ] ) );
			this.el.appendChild( this.footerEl );

			this.renderSidebar();
			this.renderFooter();
			this.loadTerms();
			this.fetch( true );

			return this;
		},

		renderControls: function () {
			var self = this;

			this.searchEl = el( 'input', {
				type: 'search',
				class: 'pdi-tab__search',
				placeholder: strings.searchPlaceholder,
				'aria-label': strings.searchLabel,
			} );

			var timer = null;
			this.searchEl.addEventListener( 'input', function () {
				window.clearTimeout( timer );
				timer = window.setTimeout( function () {
					self.query = self.searchEl.value.trim();
					self.fetch( true );
				}, 400 );
			} );
			this.searchEl.addEventListener( 'keydown', function ( event ) {
				if ( 'Enter' === event.key ) {
					event.preventDefault();
					window.clearTimeout( timer );
					self.query = self.searchEl.value.trim();
					self.fetch( true );
				}
			} );

			this.categoryEl    = this.buildSelect( strings.allCategories, function ( value ) {
				self.filters.category = value;
				self.fetch( true );
			} );
			this.orientationEl = this.buildSelect( strings.anyOrientation, function ( value ) {
				self.filters.orientation = value;
				self.fetch( true );
			} );

			this.sortEl = el( 'select', { class: 'pdi-tab__select', 'aria-label': strings.sortLabel }, [
				el( 'option', { value: 'relevance', text: strings.sortRelevance } ),
				el( 'option', { value: 'date', text: strings.sortNewest } ),
			] );
			this.sortEl.addEventListener( 'change', function () {
				self.filters.sort = self.sortEl.value;
				self.fetch( true );
			} );

			return el( 'div', { class: 'pdi-tab__controls' }, [
				el( 'div', { class: 'pdi-tab__searchwrap' }, [
					el( 'span', { class: 'dashicons dashicons-search pdi-tab__searchicon', 'aria-hidden': 'true' } ),
					this.searchEl,
				] ),
				this.categoryEl,
				this.orientationEl,
				this.sortEl,
			] );
		},

		buildSelect: function ( anyLabel, onChange ) {
			var select = el( 'select', { class: 'pdi-tab__select', 'aria-label': anyLabel }, [
				el( 'option', { value: '0', text: anyLabel } ),
			] );
			select.addEventListener( 'change', function () {
				onChange( parseInt( select.value, 10 ) || 0 );
			} );
			return select;
		},

		loadTerms: function () {
			var self = this;
			ajax( 'pdi_terms' ).then( function ( response ) {
				if ( ! response || ! response.success ) {
					return;
				}
				self.terms = response.data;
				self.fillSelect( self.categoryEl, self.terms[ TAX_CATEGORY ] );
				self.fillSelect( self.orientationEl, self.terms[ TAX_ORIENTATION ] );
			} );
		},

		fillSelect: function ( select, terms ) {
			( terms || [] ).forEach( function ( term ) {
				select.appendChild( el( 'option', { value: term.id, text: term.name } ) );
			} );
		},

		fetch: function ( reset ) {
			var self    = this;
			var current = ++this.requestId;

			if ( reset ) {
				this.page = 1;
			}

			this.busy = true;
			this.setStatus( strings.loading );
			if ( reset ) {
				this.gridEl.innerHTML = '';
			}
			this.moreEl.innerHTML = '';

			ajax( 'pdi_search', {
				search: this.query,
				page: this.page,
				category: this.filters.category,
				orientation: this.filters.orientation,
				sort: this.filters.sort,
			} )
				.then( function ( response ) {
					if ( current !== self.requestId ) {
						return;
					}
					self.busy = false;

					if ( ! response || ! response.success ) {
						self.setStatus( ( response && response.data && response.data.message ) || strings.error );
						return;
					}

					var data = response.data;
					self.importedMap = Object.assign( {}, reset ? {} : self.importedMap, data.importedMap );
					self.results = reset ? data.photos : self.results.concat( data.photos );
					self.hasMore = data.page < data.totalPages;

					if ( ! self.results.length ) {
						self.setStatus( strings.noResults );
						return;
					}

					self.setStatus( '' );
					data.photos.forEach( function ( photo ) {
						self.gridEl.appendChild( self.renderTile( photo ) );
					} );
					self.renderMore();
				} )
				.catch( function () {
					if ( current === self.requestId ) {
						self.busy = false;
						self.setStatus( strings.error );
					}
				} );
		},

		renderMore: function () {
			var self = this;
			this.moreEl.innerHTML = '';
			if ( ! this.hasMore ) {
				return;
			}
			var button = el( 'button', { type: 'button', class: 'button pdi-tab__loadmore', text: strings.loadMore } );
			button.addEventListener( 'click', function () {
				if ( self.busy ) {
					return;
				}
				self.page++;
				self.fetch( false );
			} );
			this.moreEl.appendChild( button );
		},

		setStatus: function ( message ) {
			this.statusEl.textContent = message || '';
			this.statusEl.style.display = message ? '' : 'none';
		},

		/**
		 * The whole tile is the control here: there is no per-card Import
		 * button in the modal, so a `checkbox` role keeps Space/Enter and
		 * screen reader state on the element people actually click.
		 *
		 * @param {Object} photo Normalized photo from the API.
		 * @return {HTMLElement} Grid tile.
		 */
		renderTile: function ( photo ) {
			var self     = this;
			var selected = -1 !== this.selected.indexOf( photo.id );
			var children = [
				el( 'img', {
					class: 'pdi-tile__img',
					src: photo.thumbUrl,
					alt: photo.alt || photo.title,
					loading: 'lazy',
				} ),
				el( 'span', { class: 'pdi-tile__chip dashicons dashicons-yes', 'aria-hidden': 'true' } ),
			];

			if ( this.importedMap[ photo.id ] ) {
				children.push( el( 'span', { class: 'pdi-tile__badge', text: strings.inLibrary } ) );
			}

			var tile = el(
				'div',
				{
					class: 'pdi-tile' + ( selected ? ' is-selected' : '' ),
					'data-photo': photo.id,
					role: 'checkbox',
					tabindex: '0',
					'aria-checked': selected ? 'true' : 'false',
					'aria-label': format( strings.selectPhoto, [ photo.title ] ),
				},
				children
			);

			tile.addEventListener( 'click', function () {
				self.toggle( photo );
			} );
			tile.addEventListener( 'keydown', function ( event ) {
				if ( ' ' === event.key || 'Enter' === event.key ) {
					event.preventDefault();
					self.toggle( photo );
				}
			} );

			return tile;
		},

		toggle: function ( photo ) {
			var index = this.selected.indexOf( photo.id );

			if ( -1 === index ) {
				this.selected.push( photo.id );
				this.photos[ photo.id ] = photo;
				this.lastClicked = photo.id;
			} else {
				this.selected.splice( index, 1 );
				delete this.photos[ photo.id ];
				if ( this.lastClicked === photo.id ) {
					this.lastClicked = this.selected.length ? this.selected[ this.selected.length - 1 ] : null;
				}
			}

			this.syncTiles();
			this.renderSidebar();
			this.renderFooter();
		},

		syncTiles: function () {
			var self  = this;
			var tiles = this.gridEl.querySelectorAll( '.pdi-tile' );

			Array.prototype.forEach.call( tiles, function ( tile ) {
				var id       = parseInt( tile.getAttribute( 'data-photo' ), 10 );
				var selected = -1 !== self.selected.indexOf( id );

				tile.classList.toggle( 'is-selected', selected );
				tile.setAttribute( 'aria-checked', selected ? 'true' : 'false' );

				if ( self.importedMap[ id ] && ! tile.querySelector( '.pdi-tile__badge' ) ) {
					tile.appendChild( el( 'span', { class: 'pdi-tile__badge', text: strings.inLibrary } ) );
				}
			} );
		},

		// --------------------------------------------------------- sidebar

		renderSidebar: function () {
			var self  = this;
			var photo = this.currentPhoto();

			this.sidebarEl.innerHTML = '';
			this.sidebarEl.appendChild( el( 'span', { class: 'pdi-tab__sidebarlabel', text: strings.detailsLabel } ) );

			if ( ! photo ) {
				this.sidebarEl.appendChild( el( 'p', { class: 'pdi-tab__sidebarempty', text: strings.detailsEmpty } ) );
				return;
			}

			var edit = this.edits[ photo.id ] || {};
			var spec = [ photo.width + ' × ' + photo.height, mimeLabel( photo.mime ), formatBytes( photo.filesize ) ]
				.filter( Boolean )
				.join( ' · ' );

			this.sidebarEl.appendChild(
				el( 'img', { class: 'pdi-tab__preview', src: photo.thumbUrl, alt: photo.alt || photo.title } )
			);

			if ( photo.author ) {
				this.sidebarEl.appendChild(
					el( 'span', { class: 'pdi-tab__meta', text: format( strings.byLine, [ photo.author ] ) } )
				);
			}
			if ( spec ) {
				this.sidebarEl.appendChild( el( 'span', { class: 'pdi-tab__meta', text: spec } ) );
			}

			this.sidebarEl.appendChild( el( 'hr', { class: 'pdi-tab__divider' } ) );

			function field( name, label, control ) {
				control.value = 'undefined' !== typeof edit[ name ] ? edit[ name ] : control.value;
				control.addEventListener( 'input', function () {
					self.edits[ photo.id ] = self.edits[ photo.id ] || {};
					self.edits[ photo.id ][ name ] = control.value;
				} );
				return el( 'label', { class: 'pdi-tab__field' }, [
					el( 'span', { class: 'pdi-tab__fieldlabel', text: label } ),
					control,
				] );
			}

			var titleInput = el( 'input', { type: 'text', class: 'pdi-tab__input' } );
			titleInput.value = photo.title || '';

			var altInput = el( 'textarea', { class: 'pdi-tab__textarea', rows: '3', placeholder: strings.fieldAltHint } );
			altInput.value = photo.alt || '';

			var captionInput = el( 'textarea', { class: 'pdi-tab__textarea pdi-tab__textarea--caption', rows: '2' } );
			captionInput.value = photo.credit || '';

			this.sidebarEl.appendChild( field( 'title', strings.fieldTitle, titleInput ) );
			this.sidebarEl.appendChild( field( 'alt', strings.fieldAlt, altInput ) );
			this.sidebarEl.appendChild( field( 'caption', strings.fieldCaption, captionInput ) );

			var sizeSelect = el(
				'select',
				{ class: 'pdi-tab__select' },
				( S.sizes || [] ).map( function ( size ) {
					return el( 'option', { value: size.value, text: size.label } );
				} )
			);
			sizeSelect.value = this.size;
			sizeSelect.addEventListener( 'change', function () {
				self.size = sizeSelect.value;
			} );

			this.sidebarEl.appendChild(
				el( 'label', { class: 'pdi-tab__field' }, [
					el( 'span', { class: 'pdi-tab__fieldlabel', text: strings.importSize } ),
					sizeSelect,
				] )
			);
		},

		// ---------------------------------------------------------- footer

		renderFooter: function () {
			var self  = this;
			var count = this.selected.length;
			var photo = this.currentPhoto();

			this.footerEl.innerHTML = '';

			var status = el( 'span', {
				class: 'pdi-tab__count',
				text: count
					? format(
							ni18n(
								'%s selected · imported to your Media Library on insert',
								'%s selected · imported to your Media Library on insert',
								count
							),
							[ count ]
					  )
					: strings.nothingSelected,
			} );

			var viewFullBtn = el( 'button', {
				type: 'button',
				class: 'button pdi-tab__action',
				text: strings.viewFull,
			} );
			viewFullBtn.disabled = ! photo;
			viewFullBtn.addEventListener( 'click', function () {
				var current = self.currentPhoto();
				if ( current ) {
					self.openLightbox( current );
				}
			} );

			var importBtn = el( 'button', {
				type: 'button',
				class: 'button pdi-tab__action',
				text: strings.importOnly,
			} );
			var insertBtn = el( 'button', {
				type: 'button',
				class: 'button button-primary pdi-tab__action',
				text: this.primaryLabel(),
			} );

			importBtn.disabled = ! count;
			insertBtn.disabled = ! count;

			importBtn.addEventListener( 'click', function () {
				self.runImport( false );
			} );
			insertBtn.addEventListener( 'click', function () {
				self.runImport( true );
			} );

			this.footerEl.appendChild( status );
			this.footerEl.appendChild( el( 'div', { class: 'pdi-tab__actions' }, [ viewFullBtn, importBtn, insertBtn ] ) );
		},

		/**
		 * The same tab shows up in frames that finish very differently:
		 * "Insert into post", "Set featured image", "Select". Borrow the
		 * frame's own button label so the tab never promises the wrong thing.
		 *
		 * @return {string} Label for the primary action.
		 */
		primaryLabel: function () {
			var button = this.primaryButton();
			var text   = button ? button.textContent.trim() : '';

			return text || strings.insertIntoPost;
		},

		/**
		 * @return {HTMLElement|null} The frame's own primary toolbar button.
		 */
		primaryButton: function () {
			var frame = this.controller;

			if ( ! frame || ! frame.el ) {
				return null;
			}

			return frame.el.querySelector( '.media-toolbar-primary .media-button-select, .media-toolbar-primary .button-primary' );
		},

		setFooterBusy: function ( message ) {
			Array.prototype.forEach.call( this.footerEl.querySelectorAll( 'button' ), function ( button ) {
				button.disabled = true;
			} );
			var status = this.footerEl.querySelector( '.pdi-tab__count' );
			if ( status ) {
				status.textContent = message;
			}
		},

		// -------------------------------------------------------- lightbox

		/**
		 * @return {Object|null} The photo currently shown in the sidebar, or
		 *                        null when nothing has been clicked yet.
		 */
		currentPhoto: function () {
			return this.lastClicked ? this.photos[ this.lastClicked ] : null;
		},

		/**
		 * Opens a full-size, image-only overlay for one photo. Appended to
		 * document.body rather than nested inside this view's own element,
		 * so its z-index only has to clear wp.media's modal chrome once,
		 * rather than also contend with any stacking context this view's
		 * ancestors might introduce.
		 *
		 * @param {Object} photo Normalized photo data.
		 */
		openLightbox: function ( photo ) {
			var self  = this;
			var sizes = photo.sizes || {};
			var full  = sizes.full || sizes.large || sizes.medium;
			var url   = full ? full.url : photo.thumbUrl;

			if ( ! url ) {
				return;
			}

			this.closeLightbox();

			var closeBtn = el( 'button', {
				type: 'button',
				class: 'pdi-tab-lightbox__close',
				'aria-label': strings.close,
				text: '×',
			} );
			closeBtn.addEventListener( 'click', function () {
				self.closeLightbox();
			} );

			var overlay = el( 'div', { class: 'pdi-tab-lightbox' }, [
				el( 'div', { class: 'pdi-tab-lightbox__frame' }, [
					closeBtn,
					el( 'img', { class: 'pdi-tab-lightbox__image', src: url, alt: photo.alt || photo.title } ),
				] ),
			] );

			overlay.addEventListener( 'click', function ( event ) {
				if ( event.target === overlay ) {
					self.closeLightbox();
				}
			} );

			this.lightboxKeydown = function ( event ) {
				if ( 'Escape' === event.key ) {
					self.closeLightbox();
				}
			};
			document.addEventListener( 'keydown', this.lightboxKeydown );

			document.body.appendChild( overlay );
			this.lightboxEl = overlay;
			closeBtn.focus();
		},

		closeLightbox: function () {
			if ( this.lightboxKeydown ) {
				document.removeEventListener( 'keydown', this.lightboxKeydown );
				this.lightboxKeydown = null;
			}
			if ( this.lightboxEl ) {
				this.lightboxEl.parentNode.removeChild( this.lightboxEl );
				this.lightboxEl = null;
			}
		},

		/**
		 * Backbone calls this when the frame tears the tab's content view
		 * down (e.g. the modal closes, or another router tab is chosen).
		 * Overridden only to make sure a still-open lightbox doesn't leak
		 * as an orphaned element on document.body.
		 */
		remove: function () {
			this.closeLightbox();
			return media.View.prototype.remove.apply( this, arguments );
		},

		/**
		 * Imports every selected photo, one request at a time, then hands the
		 * resulting attachments to the frame's selection.
		 *
		 * @param {boolean} insert Whether to also fire the frame's primary
		 *                         action once the files are in the library.
		 */
		runImport: function ( insert ) {
			var self    = this;
			var photos  = this.selected.map( function ( id ) {
				return self.photos[ id ];
			} ).filter( Boolean );

			if ( ! photos.length ) {
				return;
			}

			var attachmentIds = [];
			var failed        = 0;
			var index         = 0;

			function next() {
				if ( index >= photos.length ) {
					self.finishImport( attachmentIds, failed, insert );
					return;
				}

				var photo = photos[ index ];
				var edit  = self.edits[ photo.id ] || {};

				self.setFooterBusy( format( strings.importingProgress, [ index + 1, photos.length ] ) );

				ajax( 'pdi_import', {
					photo_id: photo.id,
					size: self.size,
					title: 'undefined' !== typeof edit.title ? edit.title : photo.title,
					alt: 'undefined' !== typeof edit.alt ? edit.alt : photo.alt,
					caption: 'undefined' !== typeof edit.caption ? edit.caption : null,
				} )
					.then( function ( response ) {
						if ( response && response.success ) {
							attachmentIds.push( response.data.id );
							self.importedMap[ photo.id ] = response.data.id;
						} else {
							failed++;
						}
					} )
					.catch( function () {
						failed++;
					} )
					.then( function () {
						index++;
						next();
					} );
			}

			next();
		},

		finishImport: function ( attachmentIds, failed, insert ) {
			if ( ! attachmentIds.length ) {
				this.renderFooter();
				this.setStatus( strings.importFailed );
				return;
			}

			this.addToSelection( attachmentIds );

			if ( failed ) {
				this.setStatus( strings.importFailed );
			}

			if ( insert ) {
				// Deferred by a tick so core's toolbar has processed the
				// selection change and re-enabled its button before we
				// click it.
				var self = this;
				window.setTimeout( function () {
					self.triggerPrimaryAction();
				}, 0 );
				return;
			}

			// "Import only": the files are in the library and staged in the
			// frame's selection, so core's own controls can finish the job.
			this.selected = [];
			this.photos = {};
			this.edits = {};
			this.lastClicked = null;
			this.syncTiles();
			this.renderSidebar();
			this.renderFooter();
		},

		/**
		 * @param {number[]} attachmentIds Freshly imported attachment IDs.
		 */
		addToSelection: function ( attachmentIds ) {
			var state     = this.controller.state();
			var selection = state && state.get( 'selection' );

			if ( ! selection ) {
				return;
			}

			var attachments = attachmentIds.map( function ( id ) {
				var attachment = media.model.Attachment.get( id );
				attachment.fetch();
				return attachment;
			} );

			if ( state.get( 'multiple' ) ) {
				selection.add( attachments );
				return;
			}

			selection.reset( attachments.slice( -1 ) );
		},

		/**
		 * Clicks the frame's own primary button rather than reimplementing
		 * what it does. The same tab appears in the insert, featured-image
		 * and generic select frames, and each finishes differently; core
		 * already knows which, so it is left to decide.
		 */
		triggerPrimaryAction: function () {
			var button = this.primaryButton();

			if ( button && ! button.disabled ) {
				button.click();
				return;
			}

			// Nothing to delegate to: the attachments are imported and
			// selected, so closing leaves the user where core would.
			if ( this.controller.close ) {
				this.controller.close();
			}
		},
	} );

	/**
	 * Wraps a frame's existing browseRouter so our tab is added alongside
	 * "Upload files" / "Media Library" without disturbing them.
	 *
	 * @param {Function} original The frame's existing browseRouter method.
	 * @return {Function} Wrapped method.
	 */
	function withPhotoDirectoryTab( original ) {
		return function ( routerView ) {
			original.apply( this, arguments );
			routerView.set( TAB_ID, {
				text: strings.tabLabel || 'Photo Directory',
				priority: 60,
			} );
		};
	}

	/**
	 * Wraps a frame's existing bindHandlers so our content view is rendered
	 * when the "Photo Directory" tab is selected.
	 *
	 * Core's toolbar is hidden while the tab is open, because the tab carries
	 * its own footer: the photos are not attachments yet, so core's button
	 * has nothing to act on until an import has run.
	 *
	 * @param {Function} original The frame's existing bindHandlers method.
	 * @return {Function} Wrapped method.
	 */
	function withPhotoDirectoryContent( original ) {
		return function () {
			original.apply( this, arguments );

			this.on(
				'content:render:' + TAB_ID,
				function () {
					this.$el.addClass( 'pdi-tab-active' );
					this.content.set( new ContentView( { controller: this } ) );
				},
				this
			);

			[ 'browse', 'upload' ].forEach( function ( mode ) {
				this.on(
					'content:render:' + mode,
					function () {
						this.$el.removeClass( 'pdi-tab-active' );
					},
					this
				);
			}, this );
		};
	}

	// Patch both the generic "Select" frame (used e.g. for inserting media
	// into post content) and "Post" (used e.g. for the featured image
	// picker), since Post overrides browseRouter/bindHandlers rather than
	// simply extending them.
	try {
		[ media.view.MediaFrame.Select, media.view.MediaFrame.Post ].forEach( function ( Frame ) {
			if ( ! Frame || ! Frame.prototype || ! Frame.prototype.browseRouter ) {
				return;
			}
			Frame.prototype.browseRouter = withPhotoDirectoryTab( Frame.prototype.browseRouter );
			Frame.prototype.bindHandlers = withPhotoDirectoryContent( Frame.prototype.bindHandlers );
		} );
	} catch ( e ) {
		// If wp.media's internals ever change shape, fail quietly rather
		// than breaking the native media modal for everything else.
		if ( window.console && window.console.warn ) {
			window.console.warn( 'WP Photo Directory Importer: could not add media modal tab.', e );
		}
	}
} )( window.wp );
