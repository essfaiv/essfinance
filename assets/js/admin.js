( function () {
	'use strict';

	// ── Date mask: dd/mm/yyyy ────────────────────────────────
	document.querySelectorAll( '.essf-datepicker' ).forEach( function ( input ) {
		input.addEventListener( 'input', function ( e ) {
			var v = e.target.value.replace( /\D/g, '' ).slice( 0, 8 );
			if ( v.length >= 5 ) {
				v = v.slice( 0, 2 ) + '/' + v.slice( 2, 4 ) + '/' + v.slice( 4 );
			} else if ( v.length >= 3 ) {
				v = v.slice( 0, 2 ) + '/' + v.slice( 2 );
			}
			e.target.value = v;
		} );
	} );

	// ── Income/Expense toggle ────────────────────────────────
	var radios = document.querySelectorAll( 'input[name="essf_is_income"]' );
	radios.forEach( function ( radio ) {
		radio.addEventListener( 'change', function () {
			document.querySelectorAll( '.essf-toggle-option' ).forEach( function ( label ) {
				label.classList.remove( 'is-active', 'essf-income', 'essf-expense' );
			} );
			var label = radio.closest( '.essf-toggle-option' );
			if ( label ) {
				label.classList.add( 'is-active' );
				if ( radio.value === '1' ) {
					label.classList.add( 'essf-income' );
				} else {
					label.classList.add( 'essf-expense' );
				}
			}
		} );
	} );
} )();
