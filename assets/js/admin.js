/**
 * MenuNest: Appearance > MenuNest screen (confirmations, import preview and progress).
 *
 * @package MenuNest
 */

( function ( $, wp ) {
	'use strict';

	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var cfg = window.MenuNestAdmin || {};

	// Ask before destructive actions.
	$( document ).on( 'click', '[data-menunest-confirm]', function ( e ) {
		if ( ! window.confirm( $( this ).attr( 'data-menunest-confirm' ) ) ) {
			e.preventDefault();
		}
	} );

	var $form = $( '#menunest-import-form' );
	if ( ! $form.length ) {
		return;
	}

	var token = $form.data( 'token' );

	// Manual mapping: show the search box when "Link to content on this site" is chosen.
	$form.on( 'change', '.menunest-ov-mode', function () {
		var $pick = $( this ).siblings( '.menunest-pick' );
		$pick.prop( 'hidden', 'pick' !== this.value );
		if ( 'pick' === this.value ) {
			$pick.find( '.menunest-pick-search' ).trigger( 'focus' );
		}
	} );

	var timer;
	$form.on( 'input', '.menunest-pick-search', function () {
		var $input = $( this );
		var $select = $input.siblings( '.menunest-pick-result' );
		clearTimeout( timer );
		timer = setTimeout( function () {
			if ( $input.val().length < 2 ) {
				$select.empty();
				return;
			}
			$.post( cfg.ajaxUrl, { action: 'menunest_import_search', nonce: cfg.nonce, q: $input.val() } ).done( function ( res ) {
				$select.empty();
				if ( ! res || ! res.success || ! res.data.length ) {
					$select.append( $( '<option>' ).val( '' ).text( __( 'Nothing found', 'menunest-smart-menu-manager' ) ) );
					return;
				}
				$.each( res.data, function ( i, row ) {
					$select.append( $( '<option>' ).val( row.value ).text( row.label ) );
				} );
			} );
		}, 250 );
	} );

	// Collect the manual choices.
	function overrides() {
		var out = {};
		$form.find( 'tr[data-item]' ).each( function () {
			var $row = $( this );
			var mode = $row.find( '.menunest-ov-mode' ).val();
			if ( 'custom' === mode ) {
				out[ $row.data( 'item' ) ] = 'custom';
			} else if ( 'pick' === mode && $row.find( '.menunest-pick-result' ).val() ) {
				out[ $row.data( 'item' ) ] = $row.find( '.menunest-pick-result' ).val();
			}
		} );
		return out;
	}

	function showResult( type, html ) {
		$form.find( '.menunest-result' ).html( '<div class="notice notice-' + type + ' inline"><p>' + html + '</p></div>' );
	}

	function escapeHtml( text ) {
		return $( '<div>' ).text( text ).html();
	}

	function fail( message ) {
		$form.find( '.menunest-progress' ).prop( 'hidden', true );
		$form.find( '.menunest-import-actions .button' ).prop( 'disabled', false );
		showResult( 'error', escapeHtml( message || __( 'The import stopped because of an error. Please try again.', 'menunest-smart-menu-manager' ) ) );
		wp.a11y.speak( message || __( 'The import stopped because of an error.', 'menunest-smart-menu-manager' ), 'assertive' );
	}

	function step( total, errors ) {
		$.post( cfg.ajaxUrl, { action: 'menunest_import_step', nonce: cfg.nonce, token: token } )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					fail( res && res.data && res.data.message );
					return;
				}
				var d = res.data;
				errors = errors.concat( d.errors || [] );
				$form.find( 'progress' ).val( Math.round( ( d.done / d.total ) * 100 ) );
				/* translators: 1: items done, 2: total items. */
				$form.find( '.menunest-progress-text' ).text( sprintf( __( 'Creating items: %1$d of %2$d', 'menunest-smart-menu-manager' ), d.done, d.total ) );

				if ( ! d.finished ) {
					step( total, errors );
					return;
				}

				$form.find( '.menunest-progress' ).prop( 'hidden', true );
				/* translators: 1: number of items created, 2: number of items turned into custom links. */
				var summary = sprintf( __( 'Import finished: %1$d items created, %2$d turned into custom links.', 'menunest-smart-menu-manager' ), d.stats.created, d.stats.converted );
				var html = escapeHtml( summary ) + ' <a class="button button-primary" href="' + escapeHtml( d.edit_url ) + '">' + escapeHtml( __( 'Open the new menu', 'menunest-smart-menu-manager' ) ) + '</a>';
				if ( errors.length ) {
					html += '<br>' + errors.map( escapeHtml ).join( '<br>' );
				}
				showResult( errors.length ? 'warning' : 'success', html );
				wp.a11y.speak( summary );
			} )
			.fail( function () {
				fail();
			} );
	}

	$form.on( 'submit', function ( e ) {
		e.preventDefault();
		$form.find( '.menunest-import-actions .button' ).prop( 'disabled', true );
		$form.find( '.menunest-result' ).empty();
		$form.find( '.menunest-progress' ).prop( 'hidden', false );
		$form.find( '.menunest-progress-text' ).text( __( 'Creating the menu...', 'menunest-smart-menu-manager' ) );

		$.post( cfg.ajaxUrl, {
			action: 'menunest_import_start',
			nonce: cfg.nonce,
			token: token,
			menu_name: $( '#menunest-import-name' ).val(),
			include_meta: $( '#menunest-import-meta' ).is( ':checked' ) ? 1 : 0,
			overrides: overrides()
		} )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					fail( res && res.data && res.data.message );
					return;
				}
				step( res.data.total, [] );
			} )
			.fail( function () {
				fail();
			} );
	} );
}( jQuery, window.wp ) );
