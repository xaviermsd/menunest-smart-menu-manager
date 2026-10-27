/**
 * MenuNest: helpers for Appearance > Menus.
 *
 * Works on top of WordPress core's nav-menu.js (6.7+) and only uses its public
 * pieces: wpNavMenu.addItemToMenu, wpNavMenu.addMenuItemToBottom,
 * wpNavMenu.changeMenuParent, the "menu-item-added" event and the jQuery
 * helpers core adds ($.fn.menuItemDepth, childMenuItems, ...).
 *
 * @package MenuNest
 */

( function ( $, wp ) {
	'use strict';

	var __ = wp.i18n.__;
	var cfg = window.MenuNestEditor || {};
	var api = window.wpNavMenu;

	var t = {
		pickerTitle: __( 'Add new items under', 'menunest-smart-menu-manager' ),
		topLevel: __( 'Top level (no parent)', 'menunest-smart-menu-manager' ),
		pickerHelp: __( 'Applies to every "Add to Menu" button below.', 'menunest-smart-menu-manager' ),
		search: __( 'Search this menu', 'menunest-smart-menu-manager' ),
		noMatches: __( 'No matches', 'menunest-smart-menu-manager' ),
		collapseAll: __( 'Collapse all', 'menunest-smart-menu-manager' ),
		expandAll: __( 'Expand all', 'menunest-smart-menu-manager' ),
		duplicate: __( 'Duplicate', 'menunest-smart-menu-manager' ),
		backup: __( 'Back up now', 'menunest-smart-menu-manager' ),
		backupsLink: __( 'All backups', 'menunest-smart-menu-manager' ),
		backupSaving: __( 'Saving backup...', 'menunest-smart-menu-manager' ),
		backupSaved: __( 'Backup saved. You can restore it any time from Appearance > MenuNest.', 'menunest-smart-menu-manager' ),
		backupFailed: __( 'The backup could not be saved. Please reload the page and try again.', 'menunest-smart-menu-manager' ),
		backupUnsaved: __( 'Note: backups contain the last SAVED version of this menu, not your unsaved changes.', 'menunest-smart-menu-manager' ),
		addChild: __( 'Add a sub-item here', 'menunest-smart-menu-manager' ),
		toggle: __( 'Show or hide sub-items', 'menunest-smart-menu-manager' ),
		linkText: __( 'Link Text', 'menunest-smart-menu-manager' ),
		url: __( 'URL', 'menunest-smart-menu-manager' ),
		add: __( 'Add', 'menunest-smart-menu-manager' ),
		cancel: __( 'Cancel', 'menunest-smart-menu-manager' ),
		usePanel: __( 'Or add pages and categories from the left panel', 'menunest-smart-menu-manager' ),
		needText: __( 'Please enter the link text.', 'menunest-smart-menu-manager' ),
		badUrl: __( 'Please enter a valid URL, or # for a heading with no link.', 'menunest-smart-menu-manager' ),
		withChildren: __( 'Include subcategories (nested)', 'menunest-smart-menu-manager' ),
		addFailed: __( 'Some items could not be added. Please reload the page and try again.', 'menunest-smart-menu-manager' ),
		unsavedCopy: __( 'You have unsaved changes. The copy will be made from the last SAVED version of this menu, and leaving this page will discard your unsaved changes. Continue?', 'menunest-smart-menu-manager' ),
		paused: __( 'MenuNest tools are off.', 'menunest-smart-menu-manager' ),
		enable: __( 'Turn on', 'menunest-smart-menu-manager' ),
		backupReminder: __( 'Backup tips', 'menunest-smart-menu-manager' ),
		modalTitle: __( 'Before you start: take a backup', 'menunest-smart-menu-manager' ),
		modalIntro: __( 'MenuNest moves and adds menu items for you. Nothing is saved until you click Save Menu, but one wrong save on a big menu can be painful to undo.', 'menunest-smart-menu-manager' ),
		modalStep1: __( 'Back up this menu now (one click, below). MenuNest also backs up a menu automatically every time you save it, and you can restore any backup from Appearance > MenuNest.', 'menunest-smart-menu-manager' ),
		modalStep2: __( 'Make sure you have a recent full-site backup from your host or a backup plugin.', 'menunest-smart-menu-manager' ),
		modalStep3: __( 'Check your menu on the site after saving.', 'menunest-smart-menu-manager' ),
		modalBackupNow: __( 'Back up this menu now', 'menunest-smart-menu-manager' ),
		modalAgree: __( 'I understand, and I have a backup (or will make one before saving).', 'menunest-smart-menu-manager' ),
		modalContinue: __( 'Continue', 'menunest-smart-menu-manager' ),
		modalNotNow: __( 'Not now', 'menunest-smart-menu-manager' ),
		modalClose: __( 'Close', 'menunest-smart-menu-manager' ),
		bulkTitle: __( 'Bulk add: paste a list', 'menunest-smart-menu-manager' ),
		bulkMenu: __( 'Menu:', 'menunest-smart-menu-manager' ),
		bulkUnder: __( 'Items will be added under:', 'menunest-smart-menu-manager' ),
		bulkPlaceholder: 'Frag Racks\nWall Art | /wall-art\n-- Small Prints\n-- Large Prints\nSpecial Orders',
		bulkHelp: __( 'One item per line. Add a link after a | sign. Start a line with -- (or indent it) to make it a sub-item of the line above. Bullets and numbers are removed.', 'menunest-smart-menu-manager' ),
		bulkMatch: __( 'Link names to existing categories with the same name', 'menunest-smart-menu-manager' ),
		bulkLinked: __( 'Will link to the existing category with this name', 'menunest-smart-menu-manager' ),
		bulkLink: __( 'Link', 'menunest-smart-menu-manager' ),
		bulkHeading: __( 'No link (#)', 'menunest-smart-menu-manager' ),
		bulkAmbiguous: __( 'Several categories have this name, added as # (no link)', 'menunest-smart-menu-manager' ),
		bulkBadUrl: __( 'Invalid link, skipped', 'menunest-smart-menu-manager' ),
		/* translators: %d: number of items. */
		bulkAdd: __( 'Add %d items', 'menunest-smart-menu-manager' ),
		/* translators: %d: number of items. */
		bulkDone: __( '%d items added. Click Save Menu to keep them.', 'menunest-smart-menu-manager' ),
		prev: __( 'Previous match', 'menunest-smart-menu-manager' ),
		next: __( 'Next match', 'menunest-smart-menu-manager' )
	};

	var $menu, menuId;
	var collapsed = {}; // Menu item ID => true.
	var pickerValue = '0';
	var started = false;
	var URL_RE = /^((\w+:)?\/\/\w.*|\w+:(?!\/\/$)|\/|\?|#)/; // Same rule core uses.

	/* Small helpers ------------------------------------------------------ */

	function store( key, value ) {
		try {
			if ( undefined === value ) {
				return JSON.parse( window.localStorage.getItem( 'menunest:' + key ) || 'null' );
			}
			window.localStorage.setItem( 'menunest:' + key, JSON.stringify( value ) );
		} catch ( e ) {}
		return null;
	}

	function items() {
		return $menu.children( 'li.menu-item' );
	}

	function idOf( $li ) {
		return String( $li.find( '.menu-item-data-db-id' ).first().val() );
	}

	function depthOf( $li ) {
		var m = ( $li.attr( 'class' ) || '' ).match( /menu-item-depth-(\d+)/ );
		return m ? parseInt( m[ 1 ], 10 ) : 0;
	}

	function titleOf( $li ) {
		return $.trim( $li.find( '.menu-item-title' ).first().text() );
	}

	function hasChildren( $li ) {
		var $next = $li.next( 'li.menu-item' );
		return $next.length > 0 && depthOf( $next ) > depthOf( $li );
	}

	function flash( $li ) {
		$li.addClass( 'menunest-flash' );
		setTimeout( function () {
			$li.removeClass( 'menunest-flash' );
		}, 1600 );
	}

	/**
	 * Duplicate the saved menu and open the copy.
	 */
	function duplicateMenu() {
		if ( api && api.menusChanged && ! window.confirm( t.unsavedCopy ) ) {
			return;
		}
		if ( api ) {
			api.menusChanged = false; // Already confirmed above; skip core's second prompt.
		}
		window.location.href = cfg.postUrl + '?' + $.param( {
			action: 'menunest_duplicate',
			menu: menuId,
			_wpnonce: cfg.nonce
		} );
	}

	/**
	 * Save a backup of the saved menu without leaving the page.
	 *
	 * @param {jQuery} $status Element that shows the result.
	 */
	function backupNow( $status ) {
		$status.removeClass( 'is-error is-success' ).text( t.backupSaving );
		$.post( cfg.ajaxUrl, { action: 'menunest_backup_create', menu: menuId, nonce: cfg.nonce } )
			.done( function ( res ) {
				if ( res && res.success ) {
					$status.addClass( 'is-success' ).text( t.backupSaved + ( api && api.menusChanged ? ' ' + t.backupUnsaved : '' ) );
				} else {
					$status.addClass( 'is-error' ).text( ( res && res.data && res.data.message ) || t.backupFailed );
				}
			} )
			.fail( function () {
				$status.addClass( 'is-error' ).text( t.backupFailed );
			} );
	}

	/**
	 * Move a menu item under a new parent using core's own logic.
	 */
	function reparent( $li, parentId ) {
		var $select = $li.find( '.edit-menu-item-parent' ).first();
		if ( ! $select.length || ! $( '#menu-item-' + parentId ).length ) {
			return;
		}
		if ( ! $select.find( 'option[value="' + parentId + '"]' ).length ) {
			$select.append( $( '<option>' ).val( parentId ) );
		}
		$select.val( String( parentId ) );
		api.changeMenuParent( $select );
	}

	/* Backup notice (shown before any tool is switched on) -------------- */

	function showBackupModal( infoOnly ) {
		var $overlay = $(
			'<div class="menunest-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="menunest-modal-title">' +
				'<div class="menunest-modal">' +
					'<h2 id="menunest-modal-title"></h2>' +
					'<p class="menunest-modal-intro"></p>' +
					'<ol><li class="s1"></li><li class="s2"></li><li class="s3"></li></ol>' +
					'<p><button type="button" class="button menunest-modal-backup"></button> <span class="menunest-status" role="status"></span></p>' +
					'<label class="menunest-modal-agree"><input type="checkbox"> <span></span></label>' +
					'<div class="menunest-modal-actions">' +
						'<button type="button" class="button menunest-modal-notnow"></button>' +
						'<button type="button" class="button button-primary menunest-modal-continue" disabled></button>' +
					'</div>' +
				'</div>' +
			'</div>'
		);

		$overlay.find( 'h2' ).text( t.modalTitle );
		$overlay.find( '.menunest-modal-intro' ).text( t.modalIntro );
		$overlay.find( '.s1' ).text( t.modalStep1 );
		$overlay.find( '.s2' ).text( t.modalStep2 );
		$overlay.find( '.s3' ).text( t.modalStep3 );
		$overlay.find( '.menunest-modal-backup' ).text( t.modalBackupNow );
		$overlay.find( '.menunest-modal-agree span' ).text( t.modalAgree );
		$overlay.find( '.menunest-modal-notnow' ).text( infoOnly ? t.modalClose : t.modalNotNow );
		$overlay.find( '.menunest-modal-continue' ).text( t.modalContinue );

		if ( infoOnly ) {
			$overlay.find( '.menunest-modal-agree, .menunest-modal-continue' ).remove();
		}

		function close() {
			$overlay.remove();
			$( document ).off( 'keydown.menunestModal' );
		}

		$overlay.on( 'change', '.menunest-modal-agree input', function () {
			$overlay.find( '.menunest-modal-continue' ).prop( 'disabled', ! this.checked );
		} );

		$overlay.on( 'click', '.menunest-modal-backup', function () {
			backupNow( $overlay.find( '.menunest-status' ) );
		} );

		$overlay.on( 'click', '.menunest-modal-notnow', function () {
			close();
			if ( ! infoOnly ) {
				// Remember the choice, so the notice is not shown again on later visits.
				if ( ! cfg.dismissed ) {
					$.post( cfg.ajaxUrl, { action: 'menunest_dismiss_notice', nonce: cfg.nonce } );
					cfg.dismissed = true;
				}
				showPausedBar();
			}
		} );

		$overlay.on( 'click', '.menunest-modal-continue', function () {
			$.post( cfg.ajaxUrl, { action: 'menunest_save_consent', nonce: cfg.nonce } );
			cfg.consent = true;
			close();
			start();
		} );

		$( document ).on( 'keydown.menunestModal', function ( e ) {
			if ( 27 === e.which ) {
				$overlay.find( '.menunest-modal-notnow' ).trigger( 'click' );
			}
		} );

		$( 'body' ).append( $overlay );
		$overlay.find( '.menunest-modal-backup' ).trigger( 'focus' );
	}

	function showPausedBar() {
		var $bar = $( '<div class="menunest-paused notice notice-info inline"><p><span></span> <button type="button" class="button button-small"></button></p></div>' );
		$bar.find( 'span' ).text( t.paused );
		$bar.find( 'button' ).text( t.enable ).on( 'click', function () {
			$bar.remove();
			showBackupModal( false );
		} );
		$( '#menu-settings-column' ).prepend( $bar );
	}

	/* 1. Quick parent picker (left column) ------------------------------ */

	var $picker;

	function buildPicker() {
		var $box = $(
			'<div class="menunest-picker">' +
				'<label for="menunest-parent"></label>' +
				'<select id="menunest-parent" class="widefat"></select>' +
				'<p class="description"></p>' +
			'</div>'
		);
		$box.find( 'label' ).text( t.pickerTitle );
		$box.find( '.description' ).text( t.pickerHelp );

		var $heading = $( '#menu-settings-column' ).children( 'h2' ).first();
		if ( $heading.length ) {
			$heading.after( $box );
		} else {
			$( '#menu-settings-column' ).prepend( $box );
		}

		$picker = $box.find( 'select' );
		pickerValue = String( store( 'parent:' + menuId ) || '0' );

		$picker.on( 'change', function () {
			pickerValue = String( $picker.val() );
			store( 'parent:' + menuId, pickerValue );
			markTarget();
		} );
	}

	function refreshPicker() {
		var frag = document.createDocumentFragment();
		var opt = document.createElement( 'option' );
		opt.value = '0';
		opt.textContent = t.topLevel;
		frag.appendChild( opt );

		items().each( function () {
			var $li = $( this );
			var o = document.createElement( 'option' );
			o.value = idOf( $li );
			o.textContent = new Array( ( depthOf( $li ) * 4 ) + 1 ).join( '\u00a0' ) + titleOf( $li );
			frag.appendChild( o );
		} );

		$picker.empty().append( frag );
		if ( ! $picker.find( 'option[value="' + pickerValue + '"]' ).length ) {
			pickerValue = '0';
		}
		$picker.val( pickerValue );
		markTarget();
		var u = $( '.menunest-bulk' ).data( 'updateUnder' );
		if ( u ) {
			u();
		}
	}

	function markTarget() {
		items().removeClass( 'menunest-target' );
		if ( '0' !== pickerValue ) {
			$( '#menu-item-' + pickerValue ).addClass( 'menunest-target' );
		}
	}

	/**
	 * Every time core adds items (any "Add to Menu" button), move them under
	 * the chosen parent. Items we add ourselves carry data-menunest-parent / data-menunest-skip.
	 */
	function onItemsAdded( e, $markup ) {
		var $added = $( $markup ).filter( 'li.menu-item' );
		if ( ! $added.length ) {
			return;
		}

		// Wait for core to finish its own callback (it refreshes dropdowns).
		setTimeout( function () {
			var $last = null;
			$added.each( function () {
				var $li = $( this );
				if ( $li.attr( 'data-menunest-skip' ) ) {
					return;
				}
				var parentId = $li.attr( 'data-menunest-parent' ) || pickerValue;
				if ( parentId && '0' !== String( parentId ) ) {
					expandPath( String( parentId ) );
					reparent( $li, String( parentId ) );
				}
				$last = $li;
			} );

			if ( $last ) {
				scheduleRefresh();
				$last[ 0 ].scrollIntoView( { block: 'center', behavior: 'smooth' } );
				$added.each( function () { flash( $( this ) ); } );
			}
		}, 0 );
	}

	/* 2. "+" add a sub-item right on the parent ------------------------- */

	function openAddChild( $li ) {
		$menu.find( '.menunest-addchild-form' ).remove();

		var parentId = idOf( $li );
		var $form = $(
			'<div class="menunest-addchild-form">' +
				'<p class="menunest-addchild-title"></p>' +
				'<label><span class="lbl-text"></span><input type="text" class="widefat menunest-ac-text"></label>' +
				'<label><span class="lbl-url"></span><input type="text" class="widefat code menunest-ac-url" placeholder="https://"></label>' +
				'<p class="menunest-ac-error" role="alert"></p>' +
				'<div class="menunest-ac-actions">' +
					'<button type="button" class="button-link menunest-ac-panel"></button>' +
					'<span class="spinner"></span>' +
					'<button type="button" class="button menunest-ac-cancel"></button>' +
					'<button type="button" class="button button-primary menunest-ac-add"></button>' +
				'</div>' +
			'</div>'
		);

		$form.find( '.menunest-addchild-title' ).text( t.addChild + ': ' + titleOf( $li ) );
		$form.find( '.lbl-text' ).text( t.linkText );
		$form.find( '.lbl-url' ).text( t.url );
		$form.find( '.menunest-ac-panel' ).text( t.usePanel );
		$form.find( '.menunest-ac-cancel' ).text( t.cancel );
		$form.find( '.menunest-ac-add' ).text( t.add );

		function submit() {
			var text = $.trim( $form.find( '.menunest-ac-text' ).val() );
			var url = $.trim( $form.find( '.menunest-ac-url' ).val() ) || '#';
			var $err = $form.find( '.menunest-ac-error' );

			if ( ! text ) {
				$err.text( t.needText );
				return;
			}
			if ( ! URL_RE.test( url ) ) {
				$err.text( t.badUrl );
				return;
			}
			$err.text( '' );
			$form.find( '.spinner' ).addClass( 'is-active' );
			$form.find( 'button' ).prop( 'disabled', true );

			api.addItemToMenu(
				{ '-1': { 'menu-item-type': 'custom', 'menu-item-url': url, 'menu-item-title': text } },
				function ( markup ) {
					var $m = $( markup );
					$m.filter( 'li' ).attr( 'data-menunest-parent', parentId );
					api.addMenuItemToBottom( $m );
				},
				function () {
					// Keep the form open for the next one: fast entry of many links.
					$form.find( '.spinner' ).removeClass( 'is-active' );
					$form.find( 'button' ).prop( 'disabled', false );
					$form.find( '.menunest-ac-text, .menunest-ac-url' ).val( '' );
					$form.find( '.menunest-ac-text' ).trigger( 'focus' );
				}
			);
		}

		$form.on( 'click', '.menunest-ac-add', submit );
		$form.on( 'click', '.menunest-ac-cancel', function () { $form.remove(); } );
		$form.on( 'keydown', 'input', function ( e ) {
			if ( 13 === e.which ) {
				e.preventDefault();
				submit();
			} else if ( 27 === e.which ) {
				$form.remove();
			}
		} );
		$form.on( 'click', '.menunest-ac-panel', function () {
			$form.remove();
			$picker.val( parentId ).trigger( 'change' );
			$picker.closest( '.menunest-picker' ).addClass( 'menunest-flash' )[ 0 ].scrollIntoView( { block: 'center', behavior: 'smooth' } );
			setTimeout( function () { $picker.closest( '.menunest-picker' ).removeClass( 'menunest-flash' ); }, 1600 );
		} );

		$li.children( '.menu-item-bar' ).after( $form );
		$form.find( '.menunest-ac-text' ).trigger( 'focus' );
	}

	/* 3. Collapse / expand branches ------------------------------------- */

	function applyCollapse() {
		var hideBelow = null;

		items().each( function () {
			var $li = $( this );
			var d = depthOf( $li );
			var id = idOf( $li );
			var kids = hasChildren( $li );

			if ( null !== hideBelow && d > hideBelow ) {
				$li.addClass( 'menunest-hidden' );
			} else {
				hideBelow = null;
				$li.removeClass( 'menunest-hidden' );
				if ( kids && collapsed[ id ] ) {
					hideBelow = d;
				}
			}

			var $toggle = $li.find( '.menunest-toggle' ).first();
			$toggle.toggle( kids );
			if ( kids ) {
				var isClosed = !! collapsed[ id ];
				$toggle.attr( 'aria-expanded', isClosed ? 'false' : 'true' ).toggleClass( 'is-closed', isClosed );
				$toggle.find( '.menunest-count' ).text( isClosed ? $li.childMenuItems().length : '' );
			}
		} );
	}

	function saveCollapse() {
		store( 'collapsed:' + menuId, Object.keys( collapsed ) );
	}

	function expandPath( id ) {
		// Open the item and every ancestor so a newly added child is visible.
		var $li = $( '#menu-item-' + id );
		var changed = false;
		while ( $li.length ) {
			var liId = idOf( $li );
			if ( collapsed[ liId ] ) {
				delete collapsed[ liId ];
				changed = true;
			}
			var d = depthOf( $li );
			if ( 0 === d ) {
				break;
			}
			$li = $li.prevAll( 'li.menu-item-depth-' + ( d - 1 ) ).first();
		}
		if ( changed ) {
			saveCollapse();
			applyCollapse();
		}
	}

	/**
	 * Hidden children must stay glued to their collapsed parent when the user
	 * drags something next to it. Snapshot before the drag, repair after.
	 */
	var dragSnapshot = [];

	function snapshotCollapsed() {
		dragSnapshot = [];
		items().each( function () {
			var $li = $( this );
			if ( collapsed[ idOf( $li ) ] && hasChildren( $li ) ) {
				dragSnapshot.push( { parent: $li, kids: $li.childMenuItems() } );
			}
		} );
	}

	function repairAfterDrag() {
		var repaired = false;

		$.each( dragSnapshot, function ( i, snap ) {
			if ( ! snap.kids.length || snap.parent.next( 'li.menu-item' )[ 0 ] === snap.kids[ 0 ] ) {
				return;
			}
			snap.kids.detach().insertAfter( snap.parent );
			repaired = true;
		} );
		dragSnapshot = [];

		if ( repaired ) {
			items().updateParentMenuItemDBId();
			$menu.updateParentDropdown().updateOrderDropdown();
			api.registerChange();
		}
	}

	/* 4. Search inside the menu ----------------------------------------- */

	var matches = [];
	var matchIndex = -1;

	function runSearch( q ) {
		q = $.trim( q || '' ).toLowerCase();
		items().removeClass( 'menunest-match menunest-dim menunest-current' );
		matches = [];
		matchIndex = -1;

		if ( ! q ) {
			$menu.removeClass( 'menunest-searching' );
			$( '.menunest-search-count' ).text( '' );
			return;
		}

		$menu.addClass( 'menunest-searching' );
		items().each( function () {
			var $li = $( this );
			if ( titleOf( $li ).toLowerCase().indexOf( q ) !== -1 ) {
				$li.addClass( 'menunest-match' );
				matches.push( $li );
			} else {
				$li.addClass( 'menunest-dim' );
			}
		} );

		$( '.menunest-search-count' ).text( matches.length ? '' : t.noMatches );
		jump( 1 );
	}

	function jump( dir ) {
		if ( ! matches.length ) {
			return;
		}
		if ( matchIndex >= 0 ) {
			matches[ matchIndex ].removeClass( 'menunest-current' );
		}
		matchIndex = ( matchIndex + dir + matches.length ) % matches.length;
		var $li = matches[ matchIndex ];
		$li.addClass( 'menunest-current' );
		$li[ 0 ].scrollIntoView( { block: 'center', behavior: 'smooth' } );
		$( '.menunest-search-count' ).text( ( matchIndex + 1 ) + ' / ' + matches.length );
	}

	/* Toolbar above "Menu structure" ------------------------------------ */

	function buildToolbar() {
		var icon = function ( name ) {
			return '<span class="dashicons dashicons-' + name + '" aria-hidden="true"></span>';
		};
		var $bar = $(
			'<div class="menunest-toolbar">' +
				'<div class="menunest-search">' +
					icon( 'search' ) +
					'<input type="search" class="menunest-search-input">' +
					'<span class="menunest-search-count" aria-live="polite"></span>' +
					'<button type="button" class="menunest-tool is-icon menunest-prev">' + icon( 'arrow-up-alt2' ) + '</button>' +
					'<button type="button" class="menunest-tool is-icon menunest-next">' + icon( 'arrow-down-alt2' ) + '</button>' +
				'</div>' +
				'<div class="menunest-actions">' +
					'<span class="menunest-group">' +
						'<button type="button" class="menunest-tool menunest-collapse-all">' + icon( 'arrow-up-alt2' ) + '<span class="menunest-label"></span></button>' +
						'<button type="button" class="menunest-tool menunest-expand-all">' + icon( 'arrow-down-alt2' ) + '<span class="menunest-label"></span></button>' +
					'</span>' +
					'<button type="button" class="menunest-tool menunest-backup">' + icon( 'backup' ) + '<span class="menunest-label"></span></button>' +
					'<button type="button" class="menunest-tool menunest-duplicate">' + icon( 'admin-page' ) + '<span class="menunest-label"></span></button>' +
					'<a class="menunest-tool menunest-backups-link">' + icon( 'list-view' ) + '<span class="menunest-label"></span></a>' +
					'<button type="button" class="menunest-tool is-icon menunest-tips">' + icon( 'editor-help' ) + '</button>' +
				'</div>' +
				'<p class="menunest-status menunest-toolbar-status" role="status"></p>' +
			'</div>'
		);

		$bar.find( '.menunest-search-input' ).attr( 'placeholder', t.search ).attr( 'aria-label', t.search );
		$bar.find( '.menunest-collapse-all .menunest-label' ).text( t.collapseAll );
		$bar.find( '.menunest-expand-all .menunest-label' ).text( t.expandAll );
		$bar.find( '.menunest-duplicate .menunest-label' ).text( t.duplicate );
		$bar.find( '.menunest-backup .menunest-label' ).text( t.backup );
		$bar.find( '.menunest-backups-link .menunest-label' ).text( t.backupsLink );
		$bar.find( '.menunest-backups-link' ).attr( 'href', cfg.backupsUrl );
		$bar.find( '.menunest-tips' ).attr( { 'aria-label': t.backupReminder, title: t.backupReminder } );
		$bar.find( '.menunest-prev' ).attr( { 'aria-label': t.prev, title: t.prev } );
		$bar.find( '.menunest-next' ).attr( { 'aria-label': t.next, title: t.next } );

		var timer;
		$bar.on( 'input', '.menunest-search-input', function () {
			var v = this.value;
			clearTimeout( timer );
			timer = setTimeout( function () { runSearch( v ); }, 150 );
		} );
		$bar.on( 'keydown', '.menunest-search-input', function ( e ) {
			if ( 13 === e.which ) {
				e.preventDefault(); // Never submit the menu form from here.
				jump( e.shiftKey ? -1 : 1 );
			} else if ( 27 === e.which ) {
				this.value = '';
				runSearch( '' );
			}
		} );
		$bar.on( 'click', '.menunest-next', function () { jump( 1 ); } );
		$bar.on( 'click', '.menunest-prev', function () { jump( -1 ); } );

		$bar.on( 'click', '.menunest-collapse-all', function () {
			items().each( function () {
				var $li = $( this );
				if ( hasChildren( $li ) ) {
					collapsed[ idOf( $li ) ] = true;
				}
			} );
			saveCollapse();
			applyCollapse();
		} );
		$bar.on( 'click', '.menunest-expand-all', function () {
			collapsed = {};
			saveCollapse();
			applyCollapse();
		} );
		$bar.on( 'click', '.menunest-duplicate', duplicateMenu );
		$bar.on( 'click', '.menunest-backup', function () {
			backupNow( $bar.find( '.menunest-toolbar-status' ) );
		} );
		$bar.on( 'click', '.menunest-tips', function () { showBackupModal( true ); } );

		$menu.before( $bar );
	}

	/* Per-item buttons (toggle + add child) ----------------------------- */

	function decorate() {
		items().each( function () {
			var $li = $( this );
			if ( $li.data( 'menunestDecorated' ) ) {
				return;
			}
			$li.data( 'menunestDecorated', true );

			var $title = $li.find( '.menu-item-handle .item-title' ).first();
			var $toggle = $( '<button type="button" class="menunest-toggle" aria-expanded="true"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span><span class="menunest-count"></span></button>' )
				.attr( 'aria-label', t.toggle ).attr( 'title', t.toggle );
			$title.prepend( $toggle );

			var $add = $( '<button type="button" class="menunest-add-child"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span></button>' )
				.attr( 'aria-label', t.addChild ).attr( 'title', t.addChild );
			$li.find( '.menu-item-handle .item-controls' ).first().prepend( $add );
		} );
	}

	var refreshTimer;

	function refresh() {
		decorate();
		applyCollapse();
		refreshPicker();
		if ( $menu.hasClass( 'menunest-searching' ) ) {
			runSearch( $( '.menunest-search-input' ).val() );
		}
	}

	function scheduleRefresh() {
		clearTimeout( refreshTimer );
		refreshTimer = setTimeout( refresh, 60 );
	}

	/* 5. Add a category together with all its subcategories ------------- */

	function setupTermTrees() {
		$.each( cfg.trees || {}, function ( tax, map ) {
			var $box = $( '#taxonomy-' + tax );
			var $button = $( '#submit-taxonomy-' + tax );
			if ( ! $box.length || ! $button.length ) {
				return;
			}

			var children = {};
			$.each( map, function ( id, row ) {
				var p = String( row[ 0 ] );
				( children[ p ] = children[ p ] || [] ).push( String( id ) );
			} );
			$.each( children, function ( p, list ) {
				list.sort( function ( a, b ) { return map[ a ][ 1 ].localeCompare( map[ b ][ 1 ] ); } );
			} );

			var $opt = $( '<label class="menunest-with-children"><input type="checkbox"> <span></span></label>' );
			$opt.find( 'span' ).text( t.withChildren );
			$button.closest( '.button-controls' ).before( $opt );

			// Runs before core's delegated handler on #menu-settings-column.
			$button.on( 'click', function ( e ) {
				if ( ! $opt.find( 'input' ).prop( 'checked' ) ) {
					return; // Normal core behaviour (still honours the parent picker).
				}

				var $checked = $box.find( '.tabs-panel-active .categorychecklist li input.menu-item-checkbox:checked' );
				if ( ! $checked.length ) {
					return;
				}
				e.preventDefault();
				e.stopImmediatePropagation();

				var picked = {};
				$checked.each( function () { picked[ String( this.value ) ] = true; } );

				// Skip a term when one of its ancestors is also ticked: it comes along anyway.
				var roots = Object.keys( picked ).filter( function ( id ) {
					var p = map[ id ] ? String( map[ id ][ 0 ] ) : '0';
					while ( p !== '0' && map[ p ] ) {
						if ( picked[ p ] ) {
							return false;
						}
						p = String( map[ p ][ 0 ] );
					}
					return true;
				} );

				var $spinner = $button.siblings( '.spinner' );
				$spinner.addClass( 'is-active' );
				$button.prop( 'disabled', true );

				addNodes( termNodes( tax, map, children, roots ), pickerValue, function () {
					$checked.prop( 'checked', false );
					$box.find( '.button-controls .select-all' ).prop( 'checked', false );
					$spinner.removeClass( 'is-active' );
					$button.prop( 'disabled', false );
				} );
			} );
		} );
	}

	/**
	 * Turn ticked terms into the generic node format used by addNodes().
	 */
	function termNodes( tax, map, children, ids ) {
		return $.map( ids, function ( id ) {
			return {
				payload: {
					'menu-item-type': 'taxonomy',
					'menu-item-object': tax,
					'menu-item-object-id': id,
					'menu-item-title': map[ id ] ? map[ id ][ 1 ] : ''
				},
				children: termNodes( tax, map, children, children[ id ] || [] )
			};
		} );
	}

	/**
	 * Add a tree of nodes ({ payload, children }) one level at a time, so each
	 * child can be attached to its freshly created parent (we only learn the new
	 * parent's ID once it exists). Items are added in order, one request per level.
	 */
	function addNodes( roots, targetParent, done ) {
		var failed = false;
		var added = [];

		function addLevel( level ) {
			if ( ! level.length ) {
				finish();
				return;
			}

			var payload = {};
			$.each( level, function ( i, entry ) {
				payload[ '-' + ( i + 1 ) ] = $.extend( { 'menu-item-parent-id': 0 }, entry.node.payload );
			} );

			api.addItemToMenu(
				payload,
				function ( markup ) {
					var $m = $( markup );
					var $lis = $m.filter( 'li.menu-item' );
					$lis.attr( 'data-menunest-skip', '1' );
					api.addMenuItemToBottom( $m );

					if ( $lis.length !== level.length ) {
						failed = true; // Can't pair results with requests safely: stop nesting here.
						level = [];
						return;
					}

					$lis.each( function ( i ) {
						var $li = $( this );
						var parentId = String( level[ i ].parentId || '0' );
						level[ i ].newId = idOf( $li );
						if ( '0' !== parentId ) {
							expandPath( parentId );
							reparent( $li, parentId );
						}
						added.push( $li );
					} );
				},
				function () {
					var next = [];
					$.each( level, function ( i, entry ) {
						$.each( entry.node.children || [], function ( j, child ) {
							next.push( { node: child, parentId: entry.newId } );
						} );
					} );
					addLevel( next );
				}
			);
		}

		function finish() {
			if ( failed ) {
				window.alert( t.addFailed );
			}
			if ( added.length ) {
				added[ added.length - 1 ][ 0 ].scrollIntoView( { block: 'center', behavior: 'smooth' } );
				$.each( added, function ( i, $li ) { flash( $li ); } );
			}
			$menu.updateParentDropdown().updateOrderDropdown();
			scheduleRefresh();
			done( added.length, failed );
		}

		addLevel( $.map( roots, function ( node ) {
			return { node: node, parentId: targetParent };
		} ) );
	}

	/* 6. Bulk add: paste a list, preview it, add it in one go ----------- */

	var nameIndex = null;

	function norm( s ) {
		return String( s || '' ).toLowerCase().replace( /\s+/g, ' ' ).trim();
	}

	function buildNameIndex() {
		nameIndex = {};
		var order = Object.keys( cfg.trees || {} ).sort( function ( a, b ) {
			// Product categories first, then post categories, then the rest.
			var rank = function ( x ) { return 'product_cat' === x ? 0 : ( 'category' === x ? 1 : 2 ); };
			return rank( a ) - rank( b );
		} );
		$.each( order, function ( i, tax ) {
			var map = cfg.trees[ tax ];
			$.each( map, function ( id, row ) {
				var key = norm( row[ 1 ] );
				( nameIndex[ key ] = nameIndex[ key ] || [] ).push( {
					tax: tax,
					id: String( id ),
					name: row[ 1 ],
					parentName: map[ row[ 0 ] ] ? norm( map[ row[ 0 ] ][ 1 ] ) : ''
				} );
			} );
		} );
	}

	/**
	 * One item per line. Supports:
	 *  - bullets (-, *, •) and numbering (1. 2) …) which are removed
	 *  - "Name | https://link"  or  "Name https://link"
	 *  - indentation (spaces / tabs) or a "--" prefix for sub-items
	 */
	function parseList( text ) {
		var roots = [];
		var stack = [];

		$.each( String( text || '' ).split( /\r?\n/ ), function ( i, raw ) {
			if ( ! $.trim( raw ) ) {
				return;
			}

			var lead = raw.match( /^[\t  ]*/ )[ 0 ];
			var width = 0;
			$.each( lead.split( '' ), function ( j, ch ) { width += '\t' === ch ? 4 : 1; } );
			var rest = raw.slice( lead.length );

			var dashes = rest.match( /^(-{2,})\s*/ );
			if ( dashes ) {
				width += ( dashes[ 1 ].length - 1 ) * 4;
				rest = rest.slice( dashes[ 0 ].length );
			} else {
				rest = rest.replace( /^([-*\u2022\u25aa\u25e6\u00b7\u2023\u2013\u2014>]|\(?\d{1,3}[.)])\s+/, '' );
			}

			var title = rest;
			var url = '';
			var pipe = rest.lastIndexOf( '|' );
			if ( pipe !== -1 ) {
				title = rest.slice( 0, pipe );
				url = $.trim( rest.slice( pipe + 1 ) );
			} else {
				var m = rest.match( /^(.*?)\s+((?:https?:\/\/|www\.)\S+)\s*$/i );
				if ( m ) {
					title = m[ 1 ];
					url = m[ 2 ];
				}
			}
			if ( url && /^www\./i.test( url ) ) {
				url = 'https://' + url;
			}
			title = $.trim( title ).replace( /\s+/g, ' ' );
			if ( ! title ) {
				return;
			}

			var node = { title: title, url: url, children: [], checked: true };
			while ( stack.length && stack[ stack.length - 1 ].w >= width ) {
				stack.pop();
			}
			if ( stack.length ) {
				node.parent = stack[ stack.length - 1 ].node;
				node.parent.children.push( node );
			} else {
				roots.push( node );
			}
			stack.push( { w: width, node: node } );
		} );

		return roots;
	}

	/**
	 * Decide what each line becomes: its own link, a matching category, or a plain "#" item.
	 */
	function resolveNodes( nodes, useMatches ) {
		$.each( nodes, function ( i, node ) {
			node.kind = 'heading';
			node.term = null;

			if ( node.url ) {
				node.kind = URL_RE.test( node.url ) ? 'link' : 'badurl';
			} else if ( useMatches ) {
				var cands = nameIndex[ norm( node.title ) ] || [];
				if ( cands.length ) {
					var tax = cands[ 0 ].tax;
					var same = cands.filter( function ( c ) { return c.tax === tax; } );
					if ( same.length > 1 && node.parent ) {
						var byParent = same.filter( function ( c ) { return c.parentName === norm( node.parent.title ); } );
						if ( 1 === byParent.length ) {
							same = byParent;
						}
					}
					if ( 1 === same.length ) {
						node.kind = 'term';
						node.term = same[ 0 ];
					} else {
						node.kind = 'ambiguous';
					}
				}
			}
			resolveNodes( node.children, useMatches );
		} );
	}

	function flatten( nodes, depth, out ) {
		$.each( nodes, function ( i, node ) {
			node.depth = depth;
			out.push( node );
			flatten( node.children, depth + 1, out );
		} );
		return out;
	}

	/**
	 * Keep only ticked lines. Children of an unticked line move up to the nearest ticked ancestor.
	 */
	function toAddNodes( nodes ) {
		var result = [];
		$.each( nodes, function ( i, node ) {
			var kids = toAddNodes( node.children );
			if ( ! node.checked || 'badurl' === node.kind ) {
				result = result.concat( kids );
				return;
			}
			var payload;
			if ( 'term' === node.kind ) {
				payload = {
					'menu-item-type': 'taxonomy',
					'menu-item-object': node.term.tax,
					'menu-item-object-id': node.term.id,
					'menu-item-title': node.title
				};
			} else {
				payload = {
					'menu-item-type': 'custom',
					'menu-item-url': 'link' === node.kind ? node.url : '#',
					'menu-item-title': node.title
				};
			}
			result.push( { payload: payload, children: kids } );
		} );
		return result;
	}

	function setupBulk() {
		var $box = $(
			'<details class="menunest-bulk">' +
				'<summary></summary>' +
				'<div class="menunest-bulk-body">' +
					'<label class="menunest-bulk-menu"><span></span> <select></select></label>' +
					'<p class="menunest-bulk-under"></p>' +
					'<textarea rows="7" class="widefat code" spellcheck="false" wrap="off"></textarea>' +
					'<p class="description menunest-bulk-help"></p>' +
					'<label class="menunest-bulk-match"><input type="checkbox" checked> <span></span></label>' +
					'<div class="menunest-bulk-preview" aria-live="polite"></div>' +
					'<div class="menunest-bulk-actions">' +
						'<span class="spinner"></span>' +
						'<button type="button" class="button button-primary menunest-bulk-add" disabled></button>' +
					'</div>' +
					'<p class="menunest-bulk-done" role="status"></p>' +
				'</div>' +
			'</details>'
		);

		$box.find( 'summary' ).text( t.bulkTitle );
		$box.find( '.menunest-bulk-menu span' ).text( t.bulkMenu );
		$box.find( 'textarea' ).attr( 'placeholder', t.bulkPlaceholder ).attr( 'aria-label', t.bulkTitle );
		$box.find( '.menunest-bulk-help' ).text( t.bulkHelp );
		$box.find( '.menunest-bulk-match span' ).text( t.bulkMatch );

		// Menu switcher: the list is added to the menu open in the editor, so
		// choosing another menu opens it and carries the pasted list across.
		var $menuSelect = $box.find( '.menunest-bulk-menu select' );
		$.each( cfg.menus || [], function ( i, m ) {
			$menuSelect.append( $( '<option>' ).val( m.id ).text( m.name ) );
		} );
		$menuSelect.val( String( menuId ) );
		$menuSelect.on( 'change', function () {
			try {
				window.sessionStorage.setItem( 'menunest:bulk', $box.find( 'textarea' ).val() );
			} catch ( e ) {}
			window.location.href = cfg.menusUrl + '&menu=' + encodeURIComponent( this.value );
		} );

		$( '.menunest-picker' ).after( $box );

		var tree = [];

		function updateUnder() {
			var label = '0' === pickerValue ? t.topLevel : titleOf( $( '#menu-item-' + pickerValue ) );
			$box.find( '.menunest-bulk-under' ).text( t.bulkUnder + ' ' + label );
		}
		$picker.on( 'change', updateUnder );
		$box.data( 'updateUnder', updateUnder );
		updateUnder();

		function render() {
			if ( ! nameIndex ) {
				buildNameIndex();
			}
			tree = parseList( $box.find( 'textarea' ).val() );
			resolveNodes( tree, $box.find( '.menunest-bulk-match input' ).prop( 'checked' ) );
			var flat = flatten( tree, 0, [] );
			var $preview = $box.find( '.menunest-bulk-preview' ).empty();

			$.each( flat, function ( i, node ) {
				var $row = $( '<label class="menunest-bulk-row"><input type="checkbox"> <span class="menunest-bulk-name"></span> <span class="menunest-badge"></span></label>' );
				$row.css( 'padding-left', ( node.depth * 18 ) + 'px' );
				$row.find( 'input' ).prop( 'checked', node.checked ).on( 'change', function () {
					node.checked = this.checked;
					count();
				} );
				$row.find( '.menunest-bulk-name' ).text( node.title );

				var $badge = $row.find( '.menunest-badge' );
				if ( 'term' === node.kind ) {
					$badge.text( ( cfg.taxLabels[ node.term.tax ] || node.term.tax ) ).addClass( 'is-term' ).attr( 'title', t.bulkLinked );
				} else if ( 'link' === node.kind ) {
					$badge.text( t.bulkLink ).addClass( 'is-link' ).attr( 'title', node.url );
				} else if ( 'badurl' === node.kind ) {
					$badge.text( t.bulkBadUrl ).addClass( 'is-bad' );
					$row.find( 'input' ).prop( { checked: false, disabled: true } );
				} else if ( 'ambiguous' === node.kind ) {
					$badge.text( t.bulkAmbiguous ).addClass( 'is-warn' );
				} else {
					$badge.text( t.bulkHeading );
				}
				$preview.append( $row );
			} );

			count();
		}

		function count() {
			var n = 0;
			$.each( flatten( tree, 0, [] ), function ( i, node ) {
				if ( node.checked && 'badurl' !== node.kind ) {
					n++;
				}
			} );
			$box.find( '.menunest-bulk-add' ).prop( 'disabled', 0 === n ).text( t.bulkAdd.replace( '%d', n ) );
		}

		var timer;
		$box.on( 'input', 'textarea', function () {
			$box.find( '.menunest-bulk-done' ).text( '' );
			clearTimeout( timer );
			timer = setTimeout( render, 200 );
		} );
		$box.on( 'change', '.menunest-bulk-match input', render );

		$box.on( 'click', '.menunest-bulk-add', function () {
			var nodes = toAddNodes( tree );
			if ( ! nodes.length ) {
				return;
			}
			var $btn = $( this ).prop( 'disabled', true );
			$box.find( '.spinner' ).addClass( 'is-active' );

			addNodes( nodes, pickerValue, function ( added, failed ) {
				$box.find( '.spinner' ).removeClass( 'is-active' );
				$btn.prop( 'disabled', false );
				if ( ! failed ) {
					$box.find( 'textarea' ).val( '' );
					render();
				}
				$box.find( '.menunest-bulk-done' ).text( t.bulkDone.replace( '%d', added ) );
			} );
		} );

		render();

		// Restore a list carried over from another menu.
		var carried = null;
		try {
			carried = window.sessionStorage.getItem( 'menunest:bulk' );
			window.sessionStorage.removeItem( 'menunest:bulk' );
		} catch ( e ) {}
		if ( carried ) {
			$box.prop( 'open', true ).find( 'textarea' ).val( carried );
			render();
		}
	}

	/* Start ------------------------------------------------------------- */

	function start() {
		if ( started ) {
			return;
		}
		started = true;

		collapsed = {};
		$.each( store( 'collapsed:' + menuId ) || [], function ( i, id ) { collapsed[ String( id ) ] = true; } );

		$( 'body' ).addClass( 'menunest-on' );
		buildPicker();
		buildToolbar();
		decorate();
		applyCollapse();
		refreshPicker();
		setupTermTrees();
		setupBulk();

		$( document ).on( 'menu-item-added', onItemsAdded );

		$menu.on( 'click', '.menunest-toggle', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			var id = idOf( $( this ).closest( 'li.menu-item' ) );
			if ( collapsed[ id ] ) {
				delete collapsed[ id ];
			} else {
				collapsed[ id ] = true;
			}
			saveCollapse();
			applyCollapse();
		} );

		$menu.on( 'click', '.menunest-add-child', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			openAddChild( $( this ).closest( 'li.menu-item' ) );
		} );

		// The "sortstart" event fires before core's own start handler, so the DOM is still intact here.
		$menu.on( 'sortstart', function () {
			$menu.find( '.menunest-addchild-form' ).remove();
			snapshotCollapsed();
		} );
		$menu.on( 'sortstop', function () {
			setTimeout( function () {
				repairAfterDrag();
				scheduleRefresh();
			}, 0 );
		} );

		// Catch everything else that changes the list: removals, keyboard moves, core dropdowns.
		if ( window.MutationObserver ) {
			new window.MutationObserver( scheduleRefresh ).observe( $menu[ 0 ], { childList: true } );
		}
		$menu.on( 'change', '.edit-menu-item-parent, .edit-menu-item-order', scheduleRefresh );
		$menu.on( 'input', '.edit-menu-item-title', scheduleRefresh );
	}

	$( function () {
		$menu = $( '#menu-to-edit' );
		menuId = $( '#menu' ).val();

		if ( ! api || ! $menu.length || ! menuId || '0' === String( menuId ) ) {
			return; // No menu being edited (e.g. "create a new menu" or Manage Locations).
		}

		if ( cfg.consent ) {
			start();
		} else if ( cfg.dismissed ) {
			showPausedBar(); // Chose "Not now" before: no popup, just a small "Turn on" bar.
		} else {
			showBackupModal( false );
		}
	} );
}( jQuery, window.wp ) );
