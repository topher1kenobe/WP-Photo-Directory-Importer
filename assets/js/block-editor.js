/* global wp */
( function ( wp ) {
	'use strict';

	var registerPlugin = wp.plugins.registerPlugin;
	var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
	var createElement = wp.element.createElement;
	var Button = wp.components.Button;
	var useDispatch = wp.data.useDispatch;
	var __ = wp.i18n.__;

	function PDIPanel() {
		var editPost = useDispatch( 'core/editor' ).editPost;

		function openModal() {
			window.PDI.openModal( {
				onImport: function ( attachment ) {
					editPost( { featured_media: attachment.id } );
				},
			} );
		}

		return createElement(
			PluginDocumentSettingPanel,
			{ name: 'pdi-panel', title: __( 'Photo Directory', 'pdi' ), className: 'pdi-panel' },
			createElement(
				Button,
				{ variant: 'secondary', onClick: openModal },
				__( 'Import a photo…', 'pdi' )
			)
		);
	}

	registerPlugin( 'pdi-photo-directory', { render: PDIPanel, icon: 'camera' } );
} )( window.wp );
