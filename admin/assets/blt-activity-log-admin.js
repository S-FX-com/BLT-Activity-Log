/**
 * BLT Activity Log — admin script.
 *
 * Everything on the screen works without this file. It only keeps the
 * selected look of the radio cards in step with the radio inside them,
 * as the design system asks (DESIGN.md: toggle .is-selected on change,
 * do not rely on :has() alone).
 */
( function () {
	'use strict';

	document.addEventListener( 'change', function ( event ) {
		var input = event.target;

		if ( ! input || 'radio' !== input.type ) {
			return;
		}

		var group = input.closest( '.blt-select-cards' );

		if ( ! group ) {
			return;
		}

		group.querySelectorAll( '.blt-select-card' ).forEach( function ( card ) {
			var radio = card.querySelector( 'input[type="radio"]' );

			card.classList.toggle( 'is-selected', !! radio && radio.checked );
		} );
	} );
}() );
