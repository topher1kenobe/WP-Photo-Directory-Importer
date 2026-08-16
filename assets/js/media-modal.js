/* global wp, PDI_Settings */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.media || ! wp.media.view || ! wp.media.view.MediaFrame || ! window.PDI ) {
		return;
	}

	var media = wp.media;
	var S = window.PDI_Settings || {};
	var TAB_ID = 'pdi-photo-directory';
	var TAB_LABEL = ( S.strings && S.strings.tabLabel ) || 'Photo Directory';

	/**
	 * Content view rendered when the "Photo Directory" router tab is active
	 * inside a native wp.media modal (e.g. the picker opened by "Set
	 * featured image", "Add Media", or an Image block's "Upload/Media
	 * Library" control). Wraps the shared search/grid picker from admin.js.
	 *
	 * On import, the new attachment is dropped straight into the frame's
	 * current selection rather than being handled directly by this plugin
	 * — the frame's own toolbar button (Set featured image / Insert into
	 * post / Select, depending on context) then finishes the job exactly as
	 * it would for any attachment chosen from the normal Media Library tab.
	 */
	var ContentView = media.View.extend( {
		className: 'pdi-media-modal-content',

		render: function () {
			media.View.prototype.render.apply( this, arguments );

			var self = this;
			window.PDI.render( this.el, {
				autoSelect: true,
				onImport: function ( attachment ) {
					self.selectAttachment( attachment.id );
				},
			} );

			return this;
		},

		/**
		 * @param {number} id Local attachment ID.
		 */
		selectAttachment: function ( id ) {
			var controller = this.controller;
			var state = controller.state();
			var selection = state.get( 'selection' );

			if ( ! selection ) {
				return;
			}

			var attachment = media.model.Attachment.get( id );
			attachment.fetch();

			if ( state.get( 'multiple' ) ) {
				selection.add( attachment );
				return;
			}

			selection.reset( [ attachment ] );

			// Single-selection contexts (e.g. featured image): hand the
			// user back to the familiar Media Library grid, where their
			// newly imported photo is now selected and the toolbar's
			// primary button is enabled.
			if ( controller.content && controller.content.mode ) {
				controller.content.mode( 'browse' );
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
				text: TAB_LABEL,
				priority: 60,
			} );
		};
	}

	/**
	 * Wraps a frame's existing bindHandlers so our content view is rendered
	 * when the "Photo Directory" tab is selected.
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
					this.content.set( new ContentView( { controller: this } ) );
				},
				this
			);
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
