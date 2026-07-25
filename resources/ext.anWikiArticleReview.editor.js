/**
 * Enhance submission textareas with WikiEditor when the extension is installed.
 *
 * Loading ext.wikiEditor auto-inits #wpTextbox1. This module is a safety net
 * for race conditions and for textareas that were not ready when WikiEditor ran.
 *
 * VisualEditor is not embedded: VE is built around real page edit sessions
 * (Parsoid surface + edit API). Qualification submissions go through this
 * special-page form, so WikiEditor is the supported full editing toolbar.
 */
( function () {
	'use strict';

	/**
	 * @param {jQuery} $textarea
	 * @return {boolean}
	 */
	function alreadyEnhanced( $textarea ) {
		if ( !$textarea.length ) {
			return true;
		}
		// WikiEditor wraps the control in .wikiEditor-ui
		if ( $textarea.closest( '.wikiEditor-ui' ).length ) {
			return true;
		}
		if ( $textarea.data( 'wikiEditorContext' ) || $textarea.data( 'anwikiWikiEditor' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @param {jQuery} $textarea
	 */
	function enhanceTextarea( $textarea ) {
		if ( alreadyEnhanced( $textarea ) ) {
			return;
		}
		if ( typeof mw.addWikiEditor !== 'function' ) {
			return;
		}
		mw.addWikiEditor( $textarea );
		$textarea.data( 'anwikiWikiEditor', true );
	}

	function collectTextareas() {
		var $fields = $( 'textarea#wpTextbox1' );
		if ( !$fields.length ) {
			$fields = $( '#anwiki-submit-form textarea, #anwiki-resubmit-form textarea' );
		}
		return $fields;
	}

	function init() {
		var $fields = collectTextareas();
		if ( !$fields.length ) {
			return;
		}

		// Module missing → plain textarea (WikiEditor not installed).
		if ( !mw.loader.getState( 'ext.wikiEditor' ) ) {
			return;
		}

		mw.loader.using( 'ext.wikiEditor' ).then( function () {
			// Allow WikiEditor's own document-ready auto-init to run first.
			setTimeout( function () {
				collectTextareas().each( function () {
					enhanceTextarea( $( this ) );
				} );
			}, 0 );
		} );
	}

	$( init );
}() );
