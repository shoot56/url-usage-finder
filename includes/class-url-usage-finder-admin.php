<?php
/**
 * Admin UI for URL Usage Finder.
 *
 * @package URL_Usage_Finder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class URL_Usage_Finder_Admin {

	const MENU_SLUG      = 'url-usage-finder';
	const TRANSIENT_KEY  = 'uuf_last_results_';
	const USER_META_KEY  = 'uuf_last_results';
	const OPTION_PREFIX  = 'uuf_search_state_';
	const ACTION_SEARCH  = 'uuf_search';
	const ACTION_REPLACE = 'uuf_replace';
	const ACTION_EXPORT  = 'uuf_export_csv';
	const ACTION_PREVIEW = 'uuf_preview_replace';
	const RESULTS_PER_PAGE = 50;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_post_' . self::ACTION_SEARCH, array( __CLASS__, 'handle_search' ) );
		add_action( 'admin_post_' . self::ACTION_REPLACE, array( __CLASS__, 'handle_replace' ) );
		add_action( 'admin_post_' . self::ACTION_EXPORT, array( __CLASS__, 'handle_export_csv' ) );
		add_action( 'admin_post_' . self::ACTION_PREVIEW, array( __CLASS__, 'handle_preview' ) );
	}

	public static function register_page() {
		add_management_page(
			__( 'URL Usage Finder', 'url-usage-finder' ),
			__( 'URL Usage Finder', 'url-usage-finder' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_search() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this action.', 'url-usage-finder' ) );
		}

		check_admin_referer( self::ACTION_SEARCH );

		$url     = isset( $_POST['uuf_old_url'] ) ? self::sanitize_url_input( wp_unslash( $_POST['uuf_old_url'] ) ) : '';
		$sources = isset( $_POST['uuf_sources'] ) ? self::sanitize_sources( (array) wp_unslash( $_POST['uuf_sources'] ) ) : array();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::MENU_SLUG,
					'search'      => 'done',
					'uuf_old_url' => $url,
					'uuf_sources' => $sources,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	public static function handle_replace() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this action.', 'url-usage-finder' ) );
		}

		check_admin_referer( self::ACTION_REPLACE );

		$old_url = isset( $_POST['uuf_old_url'] ) ? self::sanitize_url_input( wp_unslash( $_POST['uuf_old_url'] ) ) : '';
		$new_url = isset( $_POST['uuf_new_url'] ) ? self::sanitize_url_input( wp_unslash( $_POST['uuf_new_url'] ) ) : '';
		$chosen  = isset( $_POST['uuf_selected'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['uuf_selected'] ) ) : array();
		$sources = isset( $_POST['uuf_sources'] ) ? self::sanitize_sources( (array) wp_unslash( $_POST['uuf_sources'] ) ) : self::get_default_sources();

		$data    = self::get_search_state();
		$search  = self::run_search( $old_url, $sources );
		$results = $search['results'];

		$rows = array();
		foreach ( $chosen as $index ) {
			if ( isset( $results[ $index ] ) ) {
				$rows[] = $results[ $index ];
			}
		}

		$summary = array(
			'updated' => 0,
			'skipped' => 0,
			'errors'  => array(),
		);

		if ( '' !== $old_url && '' !== $new_url && ! empty( $rows ) ) {
			$replacer = new URL_Usage_Finder_Replacer();
			$summary  = $replacer->replace_selected( $old_url, $new_url, $rows );
		}

		self::save_search_state(
			array_merge(
				$data,
				array(
				'needle'      => $old_url,
				'new_url'     => $new_url,
				'sources'     => $sources,
				'results'     => $results,
				'replace_log' => $summary,
				'ts'          => time(),
				)
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::MENU_SLUG,
					'search'      => 'done',
					'replace'     => 'done',
					'uuf_old_url' => $old_url,
					'uuf_sources' => $sources,
					'uuf_updated' => isset( $summary['updated'] ) ? (int) $summary['updated'] : 0,
					'uuf_replaced' => isset( $summary['replacements'] ) ? (int) $summary['replacements'] : 0,
					'uuf_skipped' => isset( $summary['skipped'] ) ? (int) $summary['skipped'] : 0,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	public static function handle_preview() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this action.', 'url-usage-finder' ) );
		}

		check_admin_referer( self::ACTION_PREVIEW );

		$old_url = isset( $_POST['uuf_old_url'] ) ? self::sanitize_url_input( wp_unslash( $_POST['uuf_old_url'] ) ) : '';
		$new_url = isset( $_POST['uuf_new_url'] ) ? self::sanitize_url_input( wp_unslash( $_POST['uuf_new_url'] ) ) : '';
		$chosen  = isset( $_POST['uuf_selected'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['uuf_selected'] ) ) : array();
		$sources = isset( $_POST['uuf_sources'] ) ? self::sanitize_sources( (array) wp_unslash( $_POST['uuf_sources'] ) ) : self::get_default_sources();

		$data    = self::get_search_state();
		$search  = self::run_search( $old_url, $sources );
		$results = $search['results'];

		$rows = array();
		foreach ( $chosen as $index ) {
			if ( isset( $results[ $index ] ) ) {
				$rows[] = $results[ $index ];
			}
		}

		$preview = array(
			'changed' => 0,
			'skipped' => 0,
			'errors'  => array(),
			'items'   => array(),
		);
		if ( '' !== $old_url && '' !== $new_url && ! empty( $rows ) ) {
			$replacer = new URL_Usage_Finder_Replacer();
			$preview  = $replacer->preview_selected( $old_url, $new_url, $rows );
		}

		self::save_search_state(
			array_merge(
				$data,
				array(
				'needle'      => $old_url,
				'new_url'     => $new_url,
				'sources'     => $sources,
				'results'     => $results,
				'preview_log' => $preview,
				'ts'          => time(),
				)
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::MENU_SLUG,
					'search'      => 'done',
					'preview'     => 'done',
					'uuf_old_url' => $old_url,
					'uuf_sources' => $sources,
					'uuf_changed' => isset( $preview['changed'] ) ? (int) $preview['changed'] : 0,
					'uuf_replacements' => isset( $preview['replacements'] ) ? (int) $preview['replacements'] : 0,
					'uuf_skipped' => isset( $preview['skipped'] ) ? (int) $preview['skipped'] : 0,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	public static function handle_export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this action.', 'url-usage-finder' ) );
		}

		check_admin_referer( self::ACTION_EXPORT );

		$old_url = isset( $_POST['uuf_old_url'] ) ? self::sanitize_url_input( wp_unslash( $_POST['uuf_old_url'] ) ) : '';
		$sources = isset( $_POST['uuf_sources'] ) ? self::sanitize_sources( (array) wp_unslash( $_POST['uuf_sources'] ) ) : self::get_default_sources();
		$search  = self::run_search( $old_url, $sources );
		$results = $search['results'];

		$filename = 'url-usage-finder-' . gmdate( 'Ymd-His' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			exit;
		}

		fputcsv( $output, array( 'source', 'object', 'field', 'element', 'occurrences', 'context', 'edit_link', 'view_link' ) );

		foreach ( $results as $row ) {
			fputcsv(
				$output,
				array(
					isset( $row['source'] ) ? (string) $row['source'] : '',
					isset( $row['object_label'] ) ? (string) $row['object_label'] : '',
					isset( $row['field'] ) ? (string) $row['field'] : '',
					isset( $row['element_hint'] ) ? (string) $row['element_hint'] : '',
					isset( $row['occurrences'] ) ? (int) $row['occurrences'] : 0,
					isset( $row['context'] ) ? (string) $row['context'] : '',
					isset( $row['edit_link'] ) ? (string) $row['edit_link'] : '',
					isset( $row['view_link'] ) ? (string) $row['view_link'] : '',
				)
			);
		}

		fclose( $output );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$data      = self::get_search_state();
		$data      = is_array( $data ) ? $data : array();
		$is_search = isset( $_GET['search'] ) && 'done' === $_GET['search']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $is_search ) {
			$needle  = isset( $_GET['uuf_old_url'] ) ? self::sanitize_url_input( wp_unslash( $_GET['uuf_old_url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$sources = isset( $_GET['uuf_sources'] ) ? self::sanitize_sources( (array) wp_unslash( $_GET['uuf_sources'] ) ) : self::get_default_sources(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$search  = self::run_search( $needle, $sources );
			$results = $search['results'];
			$debug   = $search['debug'];
		} else {
			$needle  = isset( $data['needle'] ) ? (string) $data['needle'] : '';
			$sources = isset( $data['sources'] ) ? (array) $data['sources'] : self::get_default_sources();
			$results = isset( $data['results'] ) ? (array) $data['results'] : array();
			$debug   = isset( $data['debug'] ) && is_array( $data['debug'] ) ? $data['debug'] : array();
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'URL Usage Finder', 'url-usage-finder' ); ?></h1>
			<p><?php echo esc_html__( 'Find where a URL is used, then replace selected usages safely.', 'url-usage-finder' ); ?></p>

			<?php self::render_search_notice( $needle, $results ); ?>
			<?php self::render_replace_notice( $data ); ?>
			<?php self::render_preview_notice( $data ); ?>
			<?php self::render_search_debug( $debug ); ?>

			<form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
				<input type="hidden" name="search" value="done" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="uuf_old_url"><?php echo esc_html__( 'Old URL', 'url-usage-finder' ); ?></label></th>
						<td><input id="uuf_old_url" name="uuf_old_url" class="regular-text code" type="text" required value="<?php echo esc_attr( $needle ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Search in', 'url-usage-finder' ); ?></th>
						<td>
							<label><input type="checkbox" name="uuf_sources[]" value="post_content" <?php checked( in_array( 'post_content', $sources, true ) ); ?> /> <?php echo esc_html__( 'Post content', 'url-usage-finder' ); ?></label><br />
							<label><input type="checkbox" name="uuf_sources[]" value="post_excerpt" <?php checked( in_array( 'post_excerpt', $sources, true ) ); ?> /> <?php echo esc_html__( 'Post excerpt', 'url-usage-finder' ); ?></label><br />
							<label><input type="checkbox" name="uuf_sources[]" value="post_meta" <?php checked( in_array( 'post_meta', $sources, true ) ); ?> /> <?php echo esc_html__( 'Post meta (including ACF)', 'url-usage-finder' ); ?></label><br />
							<label><input type="checkbox" name="uuf_sources[]" value="menus" <?php checked( in_array( 'menus', $sources, true ) ); ?> /> <?php echo esc_html__( 'Menus', 'url-usage-finder' ); ?></label><br />
							<label><input type="checkbox" name="uuf_sources[]" value="options" <?php checked( in_array( 'options', $sources, true ) ); ?> /> <?php echo esc_html__( 'Site options', 'url-usage-finder' ); ?></label>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php echo esc_html__( 'Find URL Usage', 'url-usage-finder' ); ?></button>
				</p>
			</form>

			<?php self::render_results( $needle, $results, $sources ); ?>
		</div>
		<?php
	}

	private static function render_replace_notice( $data ) {
		if ( empty( $_GET['replace'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$log = isset( $data['replace_log'] ) && is_array( $data['replace_log'] ) ? $data['replace_log'] : array();
		if ( isset( $_GET['uuf_updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$log['updated'] = (int) $_GET['uuf_updated']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( isset( $_GET['uuf_replaced'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$log['replacements'] = (int) $_GET['uuf_replaced']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( isset( $_GET['uuf_skipped'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$log['skipped'] = (int) $_GET['uuf_skipped']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				printf(
					/* translators: 1: updated row count 2: replacement count 3: skipped row count */
					esc_html__( 'Replacement completed. Updated rows: %1$d. Replaced URL occurrences: %2$d. Skipped rows: %3$d.', 'url-usage-finder' ),
					isset( $log['updated'] ) ? (int) $log['updated'] : 0,
					isset( $log['replacements'] ) ? (int) $log['replacements'] : 0,
					isset( $log['skipped'] ) ? (int) $log['skipped'] : 0
				);
				?>
			</p>
		</div>
		<?php
	}

	private static function render_search_notice( $needle, $results ) {
		if ( empty( $_GET['search'] ) || 'done' !== $_GET['search'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( '' === $needle ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p><?php echo esc_html__( 'Please enter a URL or path to search for.', 'url-usage-finder' ); ?></p>
			</div>
			<?php
			return;
		}

		if ( ! empty( $results ) ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					printf(
						/* translators: 1: result count 2: searched URL */
						esc_html__( 'Search completed. Found %1$d URL usage(s) for "%2$s".', 'url-usage-finder' ),
						count( $results ),
						esc_html( $needle )
					);
					?>
				</p>
			</div>
			<?php
			return;
		}
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %s: searched URL */
					esc_html__( 'No URL usages found for "%s". The search also checks common variants with http/https, without protocol, and by relative path.', 'url-usage-finder' ),
					esc_html( $needle )
				);
				?>
			</p>
		</div>
		<?php
	}

	private static function render_search_debug( $debug ) {
		if ( ! self::is_debug_enabled() ) {
			return;
		}

		if ( empty( $_GET['search'] ) || 'done' !== $_GET['search'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$storage_mode  = isset( $debug['storage_mode'] ) ? (string) $debug['storage_mode'] : '';
		?>
		<details style="margin: 12px 0 18px;">
			<summary><?php echo esc_html__( 'Search Debug', 'url-usage-finder' ); ?></summary>
			<div class="notice notice-info" style="margin: 8px 0 0; padding: 8px 12px;">
				<?php if ( empty( $debug ) ) : ?>
					<p><?php echo esc_html__( 'No debug payload was generated. Enter a URL and run the search again.', 'url-usage-finder' ); ?></p>
				<?php else : ?>
					<?php if ( '' !== $storage_mode ) : ?>
						<p>
							<strong><?php echo esc_html__( 'Loaded from:', 'url-usage-finder' ); ?></strong>
							<code><?php echo esc_html( $storage_mode ); ?></code>
						</p>
					<?php endif; ?>
					<p>
						<strong><?php echo esc_html__( 'Input:', 'url-usage-finder' ); ?></strong>
						<code><?php echo esc_html( isset( $debug['input'] ) ? (string) $debug['input'] : '' ); ?></code>
					</p>
					<p>
						<strong><?php echo esc_html__( 'Total results:', 'url-usage-finder' ); ?></strong>
						<?php echo esc_html( isset( $debug['total'] ) ? (string) (int) $debug['total'] : '0' ); ?>
					</p>
					<?php if ( ! empty( $debug['needles'] ) && is_array( $debug['needles'] ) ) : ?>
						<p><strong><?php echo esc_html__( 'URL variants searched:', 'url-usage-finder' ); ?></strong></p>
						<ul style="list-style: disc; margin-left: 20px;">
							<?php foreach ( $debug['needles'] as $needle ) : ?>
								<li><code><?php echo esc_html( (string) $needle ); ?></code></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<?php if ( ! empty( $debug['sources'] ) && is_array( $debug['sources'] ) ) : ?>
						<p><strong><?php echo esc_html__( 'Source query counts:', 'url-usage-finder' ); ?></strong></p>
						<ul style="list-style: disc; margin-left: 20px;">
							<?php foreach ( $debug['sources'] as $source => $source_debug ) : ?>
								<li>
									<code><?php echo esc_html( (string) $source ); ?></code>:
									<?php echo esc_html( isset( $source_debug['rows'] ) ? (string) (int) $source_debug['rows'] : '0' ); ?>
									<?php if ( ! empty( $source_debug['last_error'] ) ) : ?>
										<br />
										<strong><?php echo esc_html__( 'SQL error:', 'url-usage-finder' ); ?></strong>
										<code><?php echo esc_html( (string) $source_debug['last_error'] ); ?></code>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</details>
		<?php
	}

	private static function is_debug_enabled() {
		return defined( 'UUF_DEBUG' ) && UUF_DEBUG;
	}

	private static function render_results( $needle, $results, $sources ) {
		if ( empty( $results ) ) {
			return;
		}

		$current_page  = isset( $_GET['uuf_paged'] ) ? max( 1, (int) $_GET['uuf_paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total_results = count( $results );
		$total_pages   = (int) ceil( $total_results / self::RESULTS_PER_PAGE );
		$current_page  = min( $current_page, $total_pages );
		$offset        = ( $current_page - 1 ) * self::RESULTS_PER_PAGE;
		$page_rows     = array_slice( $results, $offset, self::RESULTS_PER_PAGE, true );
		?>
		<hr />
		<h2><?php echo esc_html__( 'Search Results', 'url-usage-finder' ); ?></h2>
		<p><?php printf( esc_html__( 'Found %d result(s). Select rows to preview or replace.', 'url-usage-finder' ), $total_results ); ?></p>
		<?php self::render_export_button( $needle, $sources ); ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_PREVIEW ); ?>" class="uuf-action-field" />
			<?php wp_nonce_field( self::ACTION_PREVIEW ); ?>
			<input type="hidden" name="uuf_old_url" value="<?php echo esc_attr( $needle ); ?>" />
			<?php foreach ( $sources as $source ) : ?>
				<input type="hidden" name="uuf_sources[]" value="<?php echo esc_attr( $source ); ?>" />
			<?php endforeach; ?>

			<table class="widefat striped">
				<thead>
				<tr>
					<th style="width:40px"><input type="checkbox" onclick="jQuery('.uuf-row-check').prop('checked', this.checked)" /></th>
					<th><?php echo esc_html__( 'Source', 'url-usage-finder' ); ?></th>
					<th><?php echo esc_html__( 'Object', 'url-usage-finder' ); ?></th>
					<th><?php echo esc_html__( 'View', 'url-usage-finder' ); ?></th>
					<th><?php echo esc_html__( 'Field', 'url-usage-finder' ); ?></th>
					<th><?php echo esc_html__( 'Element', 'url-usage-finder' ); ?></th>
					<th><?php echo esc_html__( 'Occurrences', 'url-usage-finder' ); ?></th>
					<th><?php echo esc_html__( 'Context', 'url-usage-finder' ); ?></th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ( $page_rows as $index => $row ) : ?>
					<tr>
						<td><input class="uuf-row-check" type="checkbox" name="uuf_selected[]" value="<?php echo esc_attr( $index ); ?>" /></td>
						<td><?php echo esc_html( isset( $row['source'] ) ? (string) $row['source'] : '' ); ?></td>
						<td>
							<?php if ( ! empty( $row['edit_link'] ) ) : ?>
								<a href="<?php echo esc_url( $row['edit_link'] ); ?>"><?php echo esc_html( isset( $row['object_label'] ) ? (string) $row['object_label'] : '' ); ?></a>
							<?php else : ?>
								<?php echo esc_html( isset( $row['object_label'] ) ? (string) $row['object_label'] : '' ); ?>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( ! empty( $row['view_link'] ) ) : ?>
								<a href="<?php echo esc_url( $row['view_link'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'View', 'url-usage-finder' ); ?></a>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( isset( $row['field'] ) ? (string) $row['field'] : '' ); ?></td>
						<td><?php echo esc_html( isset( $row['element_hint'] ) ? (string) $row['element_hint'] : '' ); ?></td>
						<td><?php echo esc_html( isset( $row['occurrences'] ) ? (string) (int) $row['occurrences'] : '0' ); ?></td>
						<td><code><?php echo esc_html( isset( $row['context'] ) ? (string) $row['context'] : '' ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h3><?php echo esc_html__( 'Replace selected rows', 'url-usage-finder' ); ?></h3>
			<p>
				<label for="uuf_new_url"><?php echo esc_html__( 'New URL', 'url-usage-finder' ); ?></label><br />
				<input id="uuf_new_url" name="uuf_new_url" class="regular-text code" type="text" required value="<?php echo isset( $_GET['preview'] ) && ! empty( $needle ) ? esc_attr( self::get_last_new_url() ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>" />
			</p>

			<p class="submit">
				<button type="submit" class="button"><?php echo esc_html__( 'Preview Selected (Dry Run)', 'url-usage-finder' ); ?></button>
				<button type="button" class="button button-primary uuf-replace-submit"><?php echo esc_html__( 'Replace Selected', 'url-usage-finder' ); ?></button>
			</p>
		</form>
		<?php self::render_pagination( $current_page, $total_pages, $needle, $sources ); ?>
		<script>
			(function($){
				$('.uuf-replace-submit').on('click', function(){
					var $form = $(this).closest('form');
					$form.find('.uuf-action-field').val('<?php echo esc_js( self::ACTION_REPLACE ); ?>');
					$form.find('input[name="_wpnonce"]').val('<?php echo esc_js( wp_create_nonce( self::ACTION_REPLACE ) ); ?>');
					$form.submit();
				});
			})(jQuery);
		</script>
		<?php
	}

	private static function render_export_button( $needle, $sources ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_EXPORT ); ?>" />
			<?php wp_nonce_field( self::ACTION_EXPORT ); ?>
			<input type="hidden" name="uuf_old_url" value="<?php echo esc_attr( $needle ); ?>" />
			<?php foreach ( $sources as $source ) : ?>
				<input type="hidden" name="uuf_sources[]" value="<?php echo esc_attr( $source ); ?>" />
			<?php endforeach; ?>
			<button type="submit" class="button"><?php echo esc_html__( 'Export CSV', 'url-usage-finder' ); ?></button>
		</form>
		<?php
	}

	private static function render_pagination( $current_page, $total_pages, $needle, $sources ) {
		if ( $total_pages <= 1 ) {
			return;
		}

		$big      = 999999999;
		$base_url = str_replace(
			(string) $big,
			'%#%',
			add_query_arg(
				array(
					'page'        => self::MENU_SLUG,
					'search'      => 'done',
					'uuf_old_url' => $needle,
					'uuf_sources' => $sources,
					'uuf_paged'   => $big,
				),
				admin_url( 'tools.php' )
			)
		);

		?>
		<style>
			.uuf-pagination {
				display: flex;
				justify-content: flex-end;
				margin: 16px 0 0;
			}

			.uuf-pagination .tablenav-pages {
				display: flex;
				align-items: center;
				gap: 6px;
				float: none;
				margin: 0;
			}

			.uuf-pagination__summary {
				margin-right: 8px;
				color: #646970;
			}

			.uuf-pagination .page-numbers {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				min-width: 30px;
				height: 30px;
				padding: 0 8px;
				border: 1px solid #c3c4c7;
				border-radius: 3px;
				background: #fff;
				text-decoration: none;
				box-sizing: border-box;
			}

			.uuf-pagination .page-numbers.current {
				border-color: #2271b1;
				background: #2271b1;
				color: #fff;
				font-weight: 600;
			}

			.uuf-pagination .page-numbers.dots {
				border-color: transparent;
				background: transparent;
			}
		</style>
		<div class="tablenav uuf-pagination"><div class="tablenav-pages">
			<span class="uuf-pagination__summary">
				<?php
				printf(
					/* translators: 1: current page 2: total pages */
					esc_html__( 'Page %1$d of %2$d', 'url-usage-finder' ),
					$current_page,
					$total_pages
				);
				?>
			</span>
		<?php
		echo wp_kses_post(
			paginate_links(
				array(
					'base'      => $base_url,
					'format'    => '',
					'current'   => $current_page,
					'total'     => $total_pages,
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
					'type'      => 'plain',
				)
			)
		);
		echo '</div></div>';
	}

	private static function get_last_new_url() {
		$data = self::get_search_state();

		return isset( $data['new_url'] ) ? (string) $data['new_url'] : '';
	}

	private static function run_search( $needle, $sources ) {
		$needle  = self::sanitize_url_input( $needle );
		$sources = self::sanitize_sources( $sources );

		if ( '' === $needle ) {
			return array(
				'results' => array(),
				'debug'   => array(),
			);
		}

		$scanner = new URL_Usage_Finder_Scanner();
		$results = $scanner->search( $needle, self::build_source_map( $sources ) );
		$debug   = $scanner->get_debug_info();

		if ( is_array( $debug ) ) {
			$debug['storage_mode'] = 'live_get';
		}

		return array(
			'results' => $results,
			'debug'   => $debug,
		);
	}

	private static function build_source_map( $sources ) {
		$sources = self::sanitize_sources( $sources );

		return array(
			'post_content' => in_array( 'post_content', $sources, true ),
			'post_excerpt' => in_array( 'post_excerpt', $sources, true ),
			'post_meta'    => in_array( 'post_meta', $sources, true ),
			'menus'        => in_array( 'menus', $sources, true ),
			'options'      => in_array( 'options', $sources, true ),
		);
	}

	private static function sanitize_sources( $sources ) {
		$allowed = array( 'post_content', 'post_excerpt', 'post_meta', 'menus', 'options' );
		$sources = array_map( 'sanitize_key', (array) $sources );

		return array_values( array_intersect( $allowed, $sources ) );
	}

	private static function get_default_sources() {
		return array( 'post_content', 'post_excerpt', 'post_meta', 'menus', 'options' );
	}

	private static function get_search_state() {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return array();
		}

		$state_id = isset( $_GET['uuf_state'] ) ? sanitize_key( wp_unslash( $_GET['uuf_state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $state_id ) {
			$data = get_option( self::OPTION_PREFIX . $state_id, array() );
			if ( is_array( $data ) ) {
				$data['storage_mode'] = 'option';
				if ( isset( $data['debug'] ) && is_array( $data['debug'] ) ) {
					$data['debug']['storage_mode'] = 'option';
				}

				return $data;
			}
		}

		$data = get_user_meta( $user_id, self::USER_META_KEY, true );
		if ( is_array( $data ) ) {
			$data['storage_mode'] = 'user_meta';
			if ( isset( $data['debug'] ) && is_array( $data['debug'] ) ) {
				$data['debug']['storage_mode'] = 'user_meta';
			}

			return $data;
		}

		$data = get_transient( self::TRANSIENT_KEY . $user_id );
		if ( is_array( $data ) ) {
			$data['storage_mode'] = 'transient';
			if ( isset( $data['debug'] ) && is_array( $data['debug'] ) ) {
				$data['debug']['storage_mode'] = 'transient';
			}

			return $data;
		}

		return array();
	}

	private static function save_search_state( $data ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 || ! is_array( $data ) ) {
			return false;
		}

		update_user_meta( $user_id, self::USER_META_KEY, $data );
		set_transient( self::TRANSIENT_KEY . $user_id, $data, 10 * MINUTE_IN_SECONDS );

		if ( ! empty( $data['state_id'] ) ) {
			update_option( self::OPTION_PREFIX . sanitize_key( (string) $data['state_id'] ), $data, false );
		}

		return true;
	}

	private static function sanitize_url_input( $value ) {
		return trim( sanitize_text_field( (string) $value ) );
	}

	private static function render_preview_notice( $data ) {
		if ( empty( $_GET['preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$preview = isset( $data['preview_log'] ) && is_array( $data['preview_log'] ) ? $data['preview_log'] : array();
		if ( isset( $_GET['uuf_changed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$preview['changed'] = (int) $_GET['uuf_changed']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( isset( $_GET['uuf_replacements'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$preview['replacements'] = (int) $_GET['uuf_replacements']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( isset( $_GET['uuf_skipped'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$preview['skipped'] = (int) $_GET['uuf_skipped']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		?>
		<div class="notice notice-info">
			<p>
				<?php
				printf(
					/* translators: 1: changed row count 2: replacement count 3: skipped row count */
					esc_html__( 'Dry-run completed. Changed rows: %1$d. URL occurrences to replace: %2$d. Skipped rows: %3$d.', 'url-usage-finder' ),
					isset( $preview['changed'] ) ? (int) $preview['changed'] : 0,
					isset( $preview['replacements'] ) ? (int) $preview['replacements'] : 0,
					isset( $preview['skipped'] ) ? (int) $preview['skipped'] : 0
				);
				?>
			</p>
		</div>
		<?php

		if ( empty( $preview['items'] ) || ! is_array( $preview['items'] ) ) {
			return;
		}
		?>
		<table class="widefat striped" style="margin:12px 0 24px;">
			<thead>
			<tr>
				<th><?php echo esc_html__( 'Source', 'url-usage-finder' ); ?></th>
				<th><?php echo esc_html__( 'Object', 'url-usage-finder' ); ?></th>
				<th><?php echo esc_html__( 'Field', 'url-usage-finder' ); ?></th>
				<th><?php echo esc_html__( 'Occurrences', 'url-usage-finder' ); ?></th>
				<th><?php echo esc_html__( 'Before', 'url-usage-finder' ); ?></th>
				<th><?php echo esc_html__( 'After', 'url-usage-finder' ); ?></th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $preview['items'] as $item ) : ?>
				<tr>
					<td><?php echo esc_html( isset( $item['source'] ) ? (string) $item['source'] : '' ); ?></td>
					<td><?php echo esc_html( isset( $item['object_label'] ) ? (string) $item['object_label'] : '' ); ?></td>
					<td><?php echo esc_html( isset( $item['field'] ) ? (string) $item['field'] : '' ); ?></td>
					<td><?php echo esc_html( isset( $item['replacements'] ) ? (string) (int) $item['replacements'] : '0' ); ?></td>
					<td><code><?php echo esc_html( isset( $item['before'] ) ? (string) $item['before'] : '' ); ?></code></td>
					<td><code><?php echo esc_html( isset( $item['after'] ) ? (string) $item['after'] : '' ); ?></code></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
