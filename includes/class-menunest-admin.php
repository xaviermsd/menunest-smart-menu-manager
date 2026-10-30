<?php
/**
 * Appearance > MenuNest screen.
 *
 * @package MenuNest
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Backups, Import and Export, History and Settings tabs.
 *
 * @phpstan-import-type MenuData from MenuNest_Serializer
 */
final class MenuNest_Admin {

	const PAGE = 'menunest';

	/**
	 * Screen hook name, set when the page is registered.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MENUNEST_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Add Appearance > MenuNest, right next to Menus.
	 */
	public static function register_page(): void {
		self::$hook = (string) add_theme_page(
			__( 'MenuNest: Smart Menu Manager', 'menunest-smart-menu-manager' ),
			__( 'MenuNest', 'menunest-smart-menu-manager' ),
			MenuNest_Plugin::CAPABILITY,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Links on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @phpstan-param array<int|string, string> $links
	 * @return array
	 * @phpstan-return array<int|string, string>
	 */
	public static function action_links( array $links ): array {
		array_unshift(
			$links,
			'<a href="' . esc_url( MenuNest_Plugin::admin_url( 'backups' ) ) . '">' . esc_html__( 'Backups and tools', 'menunest-smart-menu-manager' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Scripts for this screen.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue( string $hook ): void {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'menunest-admin', MENUNEST_URL . 'assets/css/admin.css', array(), MENUNEST_VERSION );
		wp_enqueue_script( 'menunest-admin', MENUNEST_URL . 'assets/js/admin.js', array( 'jquery', 'wp-i18n', 'wp-a11y' ), MENUNEST_VERSION, true );
		wp_set_script_translations( 'menunest-admin', 'menunest-smart-menu-manager', MENUNEST_DIR . 'languages' );
		wp_localize_script(
			'menunest-admin',
			'MenuNestAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'menunest_import' ),
			)
		);
	}

	/**
	 * Date and time in the site's format.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private static function format_date( int $timestamp ): string {
		$formatted = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
		return false === $formatted ? '' : $formatted;
	}

	/**
	 * The tabs.
	 *
	 * @return array<string, string>
	 */
	private static function tabs(): array {
		return array(
			'backups'  => __( 'Backups', 'menunest-smart-menu-manager' ),
			'transfer' => __( 'Import and Export', 'menunest-smart-menu-manager' ),
			'history'  => __( 'History', 'menunest-smart-menu-manager' ),
			'settings' => __( 'Settings', 'menunest-smart-menu-manager' ),
		);
	}

	/**
	 * Render the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( MenuNest_Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage menus.', 'menunest-smart-menu-manager' ), 403 );
		}

		$tabs = self::tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects which tab to show.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'backups';
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'backups';
		?>
		<div class="wrap menunest-wrap">
			<h1 class="menunest-title">
				<img class="menunest-logo" src="<?php echo esc_url( MENUNEST_URL . 'assets/images/menunest-icon.svg' ); ?>" width="32" height="32" alt="">
				<?php esc_html_e( 'MenuNest: Smart Menu Manager', 'menunest-smart-menu-manager' ); ?>
			</h1>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'MenuNest sections', 'menunest-smart-menu-manager' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( MenuNest_Plugin::admin_url( $slug ) ); ?>" class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>"<?php echo $slug === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php self::render_notice(); ?>

			<div class="menunest-tab menunest-tab-<?php echo esc_attr( $tab ); ?>">
				<?php
				switch ( $tab ) {
					case 'transfer':
						self::render_transfer();
						break;
					case 'history':
						self::render_history();
						break;
					case 'settings':
						self::render_settings();
						break;
					default:
						self::render_backups();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Messages after an action.
	 */
	private static function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only chooses which message to show.
		$notice = isset( $_GET['menunest_notice'] ) ? sanitize_key( wp_unslash( $_GET['menunest_notice'] ) ) : '';
		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'backup_created'  => array( 'success', __( 'Backup saved.', 'menunest-smart-menu-manager' ) ),
			'backup_deleted'  => array( 'success', __( 'Backup deleted.', 'menunest-smart-menu-manager' ) ),
			'history_cleared' => array( 'success', __( 'History cleared.', 'menunest-smart-menu-manager' ) ),
			'error'           => array( 'error', __( 'Something went wrong. Please try again.', 'menunest-smart-menu-manager' ) ),
		);

		if ( 'import_error' === $notice ) {
			$key     = 'menunest_import_error_' . get_current_user_id();
			$message = get_transient( $key );
			delete_transient( $key );
			$messages['import_error'] = array( 'error', $message ? (string) $message : __( 'The file could not be imported.', 'menunest-smart-menu-manager' ) );
		}

		if ( isset( $messages[ $notice ] ) ) {
			wp_admin_notice(
				$messages[ $notice ][1],
				array(
					'type'        => $messages[ $notice ][0],
					'dismissible' => true,
				)
			);
		}
	}

	/**
	 * A dropdown of all menus.
	 *
	 * @param string $name Field name.
	 * @param string $id   Field ID.
	 */
	private static function menu_select( string $name, string $id ): void {
		$menus = wp_get_nav_menus();
		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" required>';
		foreach ( $menus as $menu ) {
			echo '<option value="' . esc_attr( (string) $menu->term_id ) . '">' . esc_html( $menu->name ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * A signed admin-post.php link.
	 *
	 * @param string $action Action and nonce name.
	 * @param array  $args   Extra arguments.
	 * @phpstan-param array<string, int|string> $args
	 * @return string
	 */
	private static function action_url( string $action, array $args = array() ): string {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => $action ), $args ), admin_url( 'admin-post.php' ) ), $action );
	}

	/**
	 * Backups tab.
	 */
	private static function render_backups(): void {
		$backups   = MenuNest_Backups::query();
		$reasons   = MenuNest_Backups::reason_labels();
		$has_menus = (bool) wp_get_nav_menus();
		?>
		<div class="menunest-card">
			<h2><?php esc_html_e( 'Back up a menu now', 'menunest-smart-menu-manager' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %d: number of backups kept per menu. */
					esc_html__( 'MenuNest also backs up a menu automatically every time you save it, and keeps the newest %d backups of each menu. Restoring always saves a backup of the current version first, so a restore can be undone too.', 'menunest-smart-menu-manager' ),
					(int) MenuNest_Settings::get( 'max_backups' )
				);
				?>
			</p>
			<?php if ( $has_menus ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="menunest-inline-form">
					<input type="hidden" name="action" value="menunest_backup_create">
					<?php wp_nonce_field( 'menunest_backup_create' ); ?>
					<label for="menunest-backup-menu"><?php esc_html_e( 'Menu', 'menunest-smart-menu-manager' ); ?></label>
					<?php self::menu_select( 'menu', 'menunest-backup-menu' ); ?>
					<?php submit_button( __( 'Back up now', 'menunest-smart-menu-manager' ), 'primary', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'You have no menus yet.', 'menunest-smart-menu-manager' ); ?></p>
			<?php endif; ?>
		</div>

		<h2><?php esc_html_e( 'Saved backups', 'menunest-smart-menu-manager' ); ?></h2>
		<?php if ( ! $backups ) : ?>
			<div class="menunest-empty">
				<span class="dashicons dashicons-backup" aria-hidden="true"></span>
				<p><?php esc_html_e( 'No backups yet. They appear here as soon as you save a menu or click Back up now.', 'menunest-smart-menu-manager' ); ?></p>
			</div>
			<?php
			return;
		endif;
		?>
		<table class="widefat striped menunest-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Menu', 'menunest-smart-menu-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Saved', 'menunest-smart-menu-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'menunest-smart-menu-manager' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Items', 'menunest-smart-menu-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'By', 'menunest-smart-menu-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'menunest-smart-menu-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $backups as $backup ) :
					$menu_exists = (bool) wp_get_nav_menu_object( (int) get_post_meta( $backup->ID, '_menunest_menu_id', true ) );
					$reason      = (string) get_post_meta( $backup->ID, '_menunest_reason', true );
					$author      = get_userdata( (int) $backup->post_author );
					$when        = self::format_date( (int) get_post_timestamp( $backup ) );
					?>
					<tr>
						<td><strong><?php echo esc_html( $backup->post_title ); ?></strong>
							<?php if ( ! $menu_exists ) : ?>
								<br><span class="menunest-muted"><?php esc_html_e( 'This menu no longer exists.', 'menunest-smart-menu-manager' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $when ); ?></td>
						<td><?php echo esc_html( $reasons[ $reason ] ?? $reason ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) get_post_meta( $backup->ID, '_menunest_items', true ) ) ); ?></td>
						<td><?php echo esc_html( $author ? $author->display_name : '' ); ?></td>
						<td class="menunest-actions">
							<?php if ( $menu_exists ) : ?>
								<a class="button button-small" href="
								<?php
								echo esc_url(
									self::action_url(
										'menunest_backup_restore',
										array(
											'backup' => $backup->ID,
											'mode'   => 'replace',
										)
									)
								);
								?>
																		"
									data-menunest-confirm="<?php echo esc_attr( sprintf( /* translators: 1: menu name, 2: date. */ __( 'Restore "%1$s" to how it was on %2$s? The current version is backed up first, so you can undo this.', 'menunest-smart-menu-manager' ), $backup->post_title, $when ) ); ?>">
									<?php esc_html_e( 'Restore', 'menunest-smart-menu-manager' ); ?>
								</a>
							<?php endif; ?>
							<a class="button button-small" href="
							<?php
							echo esc_url(
								self::action_url(
									'menunest_backup_restore',
									array(
										'backup' => $backup->ID,
										'mode'   => 'new',
									)
								)
							);
							?>
																	"><?php esc_html_e( 'Restore as new menu', 'menunest-smart-menu-manager' ); ?></a>
							<a class="button button-small" href="<?php echo esc_url( self::action_url( 'menunest_backup_download', array( 'backup' => $backup->ID ) ) ); ?>"><?php esc_html_e( 'Download JSON', 'menunest-smart-menu-manager' ); ?></a>
							<a class="button button-small menunest-button-danger" href="<?php echo esc_url( self::action_url( 'menunest_backup_delete', array( 'backup' => $backup->ID ) ) ); ?>"
								data-menunest-confirm="<?php esc_attr_e( 'Delete this backup permanently?', 'menunest-smart-menu-manager' ); ?>">
								<?php esc_html_e( 'Delete', 'menunest-smart-menu-manager' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Import and Export tab.
	 */
	private static function render_transfer(): void {
		if ( isset( $_GET['step'], $_GET['token'], $_GET['_wpnonce'] ) ) {
			$token = sanitize_key( wp_unslash( $_GET['token'] ) );
			$valid = wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'menunest_preview_' . $token );
			$step  = sanitize_key( wp_unslash( $_GET['step'] ) );
			// The stored file is also tied to the user who uploaded it.
			$data = ( $valid && 'preview' === $step ) ? MenuNest_Importer::get_upload( $token ) : null;
			if ( $data ) {
				self::render_preview( $data, $token );
				return;
			}
			wp_admin_notice(
				__( 'This preview has expired. Please upload the file again.', 'menunest-smart-menu-manager' ),
				array(
					'type'        => 'warning',
					'dismissible' => true,
				)
			);
		}
		?>
		<div class="menunest-columns">
			<div class="menunest-card">
				<h2><?php esc_html_e( 'Export a menu', 'menunest-smart-menu-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Download one menu as a JSON file. Use it to move the menu to another site, or keep it as an offline backup.', 'menunest-smart-menu-manager' ); ?></p>
				<?php if ( wp_get_nav_menus() ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="menunest_export">
						<?php wp_nonce_field( 'menunest_export' ); ?>
						<p>
							<label for="menunest-export-menu"><?php esc_html_e( 'Menu', 'menunest-smart-menu-manager' ); ?></label><br>
							<?php self::menu_select( 'menu', 'menunest-export-menu' ); ?>
						</p>
						<p>
							<label><input type="checkbox" name="include_meta" value="1" checked> <?php esc_html_e( 'Include extra item settings from your theme and plugins (icons, mega menu options)', 'menunest-smart-menu-manager' ); ?></label>
						</p>
						<?php submit_button( __( 'Download export file', 'menunest-smart-menu-manager' ), 'primary', 'submit', false ); ?>
					</form>
				<?php else : ?>
					<p><?php esc_html_e( 'You have no menus yet.', 'menunest-smart-menu-manager' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="menunest-card">
				<h2><?php esc_html_e( 'Import a menu', 'menunest-smart-menu-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Choose a file exported with MenuNest. You will see a full preview before anything is created, and the menu is always imported as a new menu, so your existing menus are never changed.', 'menunest-smart-menu-manager' ); ?></p>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="menunest_import_preview">
					<?php wp_nonce_field( 'menunest_import' ); ?>
					<p>
						<label for="menunest-import-file"><?php esc_html_e( 'Export file (.json)', 'menunest-smart-menu-manager' ); ?></label><br>
						<input type="file" name="import_file" id="menunest-import-file" accept=".json,application/json">
					</p>
					<details class="menunest-paste">
						<summary><?php esc_html_e( 'Or paste the file contents', 'menunest-smart-menu-manager' ); ?></summary>
						<label for="menunest-import-text" class="screen-reader-text"><?php esc_html_e( 'File contents', 'menunest-smart-menu-manager' ); ?></label>
						<textarea name="import_text" id="menunest-import-text" rows="6" class="large-text code"></textarea>
					</details>
					<?php submit_button( __( 'Preview import', 'menunest-smart-menu-manager' ), 'primary', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Import preview.
	 *
	 * @param array  $data  Import data.
	 * @param string $token Preview token.
	 * @phpstan-param MenuData $data
	 */
	private static function render_preview( array $data, string $token ): void {
		$preview  = MenuNest_Importer::build_preview( $data );
		$time     = strtotime( $data['exported_at'] );
		$labels   = array(
			MenuNest_Builder::STATUS_MATCHED   => __( 'Matched', 'menunest-smart-menu-manager' ),
			MenuNest_Builder::STATUS_CONVERTED => __( 'Becomes a custom link', 'menunest-smart-menu-manager' ),
			MenuNest_Builder::STATUS_CUSTOM    => __( 'Custom link', 'menunest-smart-menu-manager' ),
			MenuNest_Builder::STATUS_REWRITTEN => __( 'Custom link, address updated', 'menunest-smart-menu-manager' ),
		);
		$has_meta = false;
		foreach ( $data['items'] as $item ) {
			if ( ! empty( $item['meta'] ) ) {
				$has_meta = true;
				break;
			}
		}
		?>
		<h2><?php esc_html_e( 'Import preview', 'menunest-smart-menu-manager' ); ?></h2>
		<p><?php esc_html_e( 'Nothing has been created yet. Check the details, change any item you want, then click Import as new menu.', 'menunest-smart-menu-manager' ); ?></p>

		<table class="widefat menunest-summary">
			<tbody>
				<tr><th scope="row"><?php esc_html_e( 'Menu name', 'menunest-smart-menu-manager' ); ?></th><td><?php echo esc_html( $data['menu']['name'] ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Menu items', 'menunest-smart-menu-manager' ); ?></th><td><?php echo esc_html( number_format_i18n( count( $data['items'] ) ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Source site', 'menunest-smart-menu-manager' ); ?></th><td><?php echo esc_html( $data['site_url'] ); ?><?php echo $preview['context']['same_site'] ? ' <span class="menunest-badge">' . esc_html__( 'This site', 'menunest-smart-menu-manager' ) . '</span>' : ''; ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Exported', 'menunest-smart-menu-manager' ); ?></th><td><?php echo esc_html( $time ? self::format_date( $time ) : __( 'Unknown', 'menunest-smart-menu-manager' ) ); ?></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Matched', 'menunest-smart-menu-manager' ); ?></th><td><span class="menunest-badge is-ok"><?php echo esc_html( number_format_i18n( $preview['matched'] ) ); ?></span></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Not matched', 'menunest-smart-menu-manager' ); ?></th><td><span class="menunest-badge <?php echo $preview['unmatched'] ? 'is-warn' : 'is-ok'; ?>"><?php echo esc_html( number_format_i18n( $preview['unmatched'] ) ); ?></span></td></tr>
			</tbody>
		</table>

		<?php
		$warnings = $preview['missing'];
		if ( $preview['unmatched'] ) {
			$warnings[] = sprintf(
				/* translators: %d: number of items. */
				_n( '%d item could not be matched to content on this site and will become a custom link. You can choose what it links to below.', '%d items could not be matched to content on this site and will become custom links. You can choose what they link to below.', $preview['unmatched'], 'menunest-smart-menu-manager' ),
				$preview['unmatched']
			);
		}
		foreach ( $preview['locations'] as $location ) {
			$warnings[] = $location['available']
				/* translators: %s: theme location name. */
				? sprintf( __( 'The menu was shown in "%s". That location exists in your theme, but imported menus are not assigned automatically. Assign it under Appearance > Menus > Manage Locations when you are ready.', 'menunest-smart-menu-manager' ), $location['label'] )
				/* translators: %s: theme location slug. */
				: sprintf( __( 'The menu was shown in a theme location called "%s", which your current theme does not have.', 'menunest-smart-menu-manager' ), $location['slug'] );
		}
		if ( $warnings ) :
			?>
			<div class="notice notice-warning inline menunest-warnings">
				<p><strong><?php esc_html_e( 'Please check', 'menunest-smart-menu-manager' ); ?></strong></p>
				<ul>
					<?php foreach ( $warnings as $warning ) : ?>
						<li><?php echo esc_html( $warning ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<form id="menunest-import-form" data-token="<?php echo esc_attr( $token ); ?>">
			<p class="menunest-field">
				<label for="menunest-import-name"><strong><?php esc_html_e( 'Name for the new menu', 'menunest-smart-menu-manager' ); ?></strong></label><br>
				<input type="text" id="menunest-import-name" class="regular-text" value="<?php echo esc_attr( MenuNest_Builder::unique_menu_name( $data['menu']['name'] ) ); ?>">
			</p>
			<?php if ( $has_meta ) : ?>
				<p><label><input type="checkbox" id="menunest-import-meta" checked> <?php esc_html_e( 'Import extra item settings from the theme and plugins (icons, mega menu options)', 'menunest-smart-menu-manager' ); ?></label></p>
			<?php endif; ?>

			<table class="widefat striped menunest-table menunest-items">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Item', 'menunest-smart-menu-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Was', 'menunest-smart-menu-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Result on this site', 'menunest-smart-menu-manager' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Change', 'menunest-smart-menu-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $preview['rows'] as $row ) :
						$item     = $row['item'];
						$resolved = $row['resolved'];
						$status   = $resolved['status'];
						?>
						<tr data-item="<?php echo esc_attr( (string) $item['id'] ); ?>" class="menunest-status-<?php echo esc_attr( $status ); ?>">
							<td style="padding-left: <?php echo esc_attr( (string) ( 10 + 20 * $row['depth'] ) ); ?>px">
								<?php echo $row['depth'] ? '<span class="menunest-branch" aria-hidden="true"></span>' : ''; ?>
								<strong><?php echo esc_html( $item['label'] ? $item['label'] : $item['title'] ); ?></strong>
							</td>
							<td><?php echo esc_html( 'custom' === $item['type'] ? __( 'Custom link', 'menunest-smart-menu-manager' ) : $item['object'] ); ?></td>
							<td>
								<span class="menunest-badge <?php echo esc_attr( MenuNest_Builder::STATUS_CONVERTED === $status ? 'is-warn' : 'is-ok' ); ?>"><?php echo esc_html( $labels[ $status ] ); ?></span>
								<span class="menunest-target"><?php echo esc_html( $resolved['target'] ); ?></span>
								<?php if ( $resolved['warning'] ) : ?>
									<br><span class="menunest-muted"><?php echo esc_html( $resolved['warning'] ); ?></span>
								<?php endif; ?>
							</td>
							<td class="menunest-override">
								<label class="screen-reader-text" for="menunest-ov-<?php echo esc_attr( (string) $item['id'] ); ?>"><?php esc_html_e( 'What this item should link to', 'menunest-smart-menu-manager' ); ?></label>
								<select id="menunest-ov-<?php echo esc_attr( (string) $item['id'] ); ?>" class="menunest-ov-mode">
									<option value="auto"><?php esc_html_e( 'Keep as shown', 'menunest-smart-menu-manager' ); ?></option>
									<?php if ( 'custom' !== $item['type'] ) : ?>
										<option value="custom"><?php esc_html_e( 'Use a custom link', 'menunest-smart-menu-manager' ); ?></option>
									<?php endif; ?>
									<option value="pick"><?php esc_html_e( 'Link to content on this site...', 'menunest-smart-menu-manager' ); ?></option>
								</select>
								<span class="menunest-pick" hidden>
									<input type="search" class="menunest-pick-search" placeholder="<?php esc_attr_e( 'Search pages, posts, categories', 'menunest-smart-menu-manager' ); ?>" aria-label="<?php esc_attr_e( 'Search pages, posts, categories', 'menunest-smart-menu-manager' ); ?>">
									<select class="menunest-pick-result" aria-label="<?php esc_attr_e( 'Choose content', 'menunest-smart-menu-manager' ); ?>"></select>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div class="menunest-import-actions">
				<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Import as new menu', 'menunest-smart-menu-manager' ); ?></button>
				<a class="button button-hero" href="<?php echo esc_url( MenuNest_Plugin::admin_url( 'transfer' ) ); ?>"><?php esc_html_e( 'Cancel', 'menunest-smart-menu-manager' ); ?></a>
			</div>

			<div class="menunest-progress" hidden>
				<progress max="100" value="0"></progress>
				<p class="menunest-progress-text" aria-live="polite"></p>
			</div>
			<div class="menunest-result" aria-live="polite"></div>
		</form>
		<?php
	}

	/**
	 * History tab.
	 */
	private static function render_history(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pagination only.
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$result = MenuNest_History::query( $page, 50 );
		$labels = MenuNest_History::action_labels();
		$pages  = (int) ceil( $result['total'] / 50 );
		?>
		<div class="menunest-toolbar">
			<p class="description"><?php esc_html_e( 'Every import, export, backup, restore and duplicate is recorded here with who did it and when.', 'menunest-smart-menu-manager' ); ?></p>
			<div>
				<a class="button" href="<?php echo esc_url( self::action_url( 'menunest_history_download', array( 'format' => 'json' ) ) ); ?>"><?php esc_html_e( 'Download JSON', 'menunest-smart-menu-manager' ); ?></a>
				<a class="button" href="<?php echo esc_url( self::action_url( 'menunest_history_download', array( 'format' => 'txt' ) ) ); ?>"><?php esc_html_e( 'Download text', 'menunest-smart-menu-manager' ); ?></a>
				<?php if ( $result['total'] ) : ?>
					<a class="button menunest-button-danger" href="<?php echo esc_url( self::action_url( 'menunest_history_clear' ) ); ?>"
						data-menunest-confirm="<?php esc_attr_e( 'Clear the whole history? This cannot be undone. Download a copy first if you need it.', 'menunest-smart-menu-manager' ); ?>">
						<?php esc_html_e( 'Clear history', 'menunest-smart-menu-manager' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( ! $result['entries'] ) : ?>
			<div class="menunest-empty">
				<span class="dashicons dashicons-clock" aria-hidden="true"></span>
				<p><?php esc_html_e( 'Nothing recorded yet.', 'menunest-smart-menu-manager' ); ?></p>
			</div>
			<?php
			return;
		endif;
		?>
		<table class="widefat striped menunest-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'When', 'menunest-smart-menu-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Who', 'menunest-smart-menu-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Action', 'menunest-smart-menu-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Menu', 'menunest-smart-menu-manager' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What happened', 'menunest-smart-menu-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $result['entries'] as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( self::format_date( (int) strtotime( $entry['date'] ) ) ); ?></td>
						<td><?php echo esc_html( $entry['user'] ); ?></td>
						<td><span class="menunest-badge"><?php echo esc_html( $labels[ $entry['action'] ] ?? $entry['action'] ); ?></span></td>
						<td><?php echo esc_html( $entry['menu'] ); ?></td>
						<td><?php echo esc_html( $entry['summary'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
	}

	/**
	 * Settings tab.
	 */
	private static function render_settings(): void {
		$option = MenuNest_Settings::OPTION;
		?>
		<form method="post" action="options.php" class="menunest-card">
			<?php settings_fields( MenuNest_Settings::GROUP ); ?>
			<h2><?php esc_html_e( 'Backup settings', 'menunest-smart-menu-manager' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Automatic backups', 'menunest-smart-menu-manager' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[auto_backup]" value="1" <?php checked( MenuNest_Settings::get( 'auto_backup' ) ); ?>> <?php esc_html_e( 'Back up a menu every time it is saved', 'menunest-smart-menu-manager' ); ?></label>
						<p class="description"><?php esc_html_e( 'Recommended. A backup is skipped when nothing changed since the last one.', 'menunest-smart-menu-manager' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="menunest-max-backups"><?php esc_html_e( 'Backups to keep', 'menunest-smart-menu-manager' ); ?></label></th>
					<td>
						<input type="number" min="1" max="100" id="menunest-max-backups" name="<?php echo esc_attr( $option ); ?>[max_backups]" value="<?php echo esc_attr( (string) MenuNest_Settings::get( 'max_backups' ) ); ?>" class="small-text">
						<?php esc_html_e( 'per menu', 'menunest-smart-menu-manager' ); ?>
						<p class="description"><?php esc_html_e( 'When there are more, the oldest backups of that menu are deleted.', 'menunest-smart-menu-manager' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'History', 'menunest-smart-menu-manager' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="menunest-max-log"><?php esc_html_e( 'Entries to keep', 'menunest-smart-menu-manager' ); ?></label></th>
					<td><input type="number" min="50" max="5000" id="menunest-max-log" name="<?php echo esc_attr( $option ); ?>[max_log_entries]" value="<?php echo esc_attr( (string) MenuNest_Settings::get( 'max_log_entries' ) ); ?>" class="small-text"></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'When the plugin is deleted', 'menunest-smart-menu-manager' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Remove data', 'menunest-smart-menu-manager' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[delete_on_remove]" value="1" <?php checked( MenuNest_Settings::get( 'delete_on_remove' ) ); ?>> <?php esc_html_e( 'Delete all backups, history and settings when MenuNest is deleted', 'menunest-smart-menu-manager' ); ?></label>
						<p class="description"><?php esc_html_e( 'Your menus themselves are never touched. Leave this off if you might reinstall MenuNest later.', 'menunest-smart-menu-manager' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}
}
