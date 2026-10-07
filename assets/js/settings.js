/**
 * Settings > Photo Directory: shows the quality field only when a
 * conversion format (WebP or AVIF) is selected — it has no effect when
 * keeping the original format, so there's nothing to hide it from.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var radios = document.querySelectorAll( '.photofetch-format-radio' );
		var row = document.getElementById( 'photofetch-quality-row' );

		if ( ! radios.length || ! row ) {
			return;
		}

		function sync() {
			var checked = document.querySelector( '.photofetch-format-radio:checked' );
			row.style.display = checked && 'original' !== checked.value ? '' : 'none';
		}

		Array.prototype.forEach.call( radios, function ( radio ) {
			radio.addEventListener( 'change', sync );
		} );

		sync();
	} );
} )();
