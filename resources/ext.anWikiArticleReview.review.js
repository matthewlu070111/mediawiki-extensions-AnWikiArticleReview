( function () {
	'use strict';

	function bindConfirm( selector ) {
		var buttons = document.querySelectorAll( selector );
		for ( var i = 0; i < buttons.length; i++ ) {
			buttons[ i ].addEventListener( 'click', function ( e ) {
				var msg = this.getAttribute( 'data-confirm' );
				if ( msg && !window.confirm( msg ) ) {
					e.preventDefault();
				}
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			bindConfirm( '.anwiki-btn-approve' );
			bindConfirm( '.anwiki-btn-reject' );
		} );
	} else {
		bindConfirm( '.anwiki-btn-approve' );
		bindConfirm( '.anwiki-btn-reject' );
	}
}() );
