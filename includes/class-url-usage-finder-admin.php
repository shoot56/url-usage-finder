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

		$url     = isset( $_POST['uuf_old_url'] ) ? esc_url_raw( wp_unslash( $_POST['uuf_old_url'] ) ) : '';
		$sources = isset( $_POST['uuf_sources'] ) ? (array) wp_unslash( $_POST['uuf_sources'] ) : array();

		$source_map = array(
			'post_content' => in_array( 'post_content', $sources, true ),
			'post_excerpt' => in_array( 'post_excerpt', $sources, true ),
			'post_meta'    => in_array( 'post_meta', $sources, true ),
			'menus'        => in_array( 'menus', $sources, true ),
			'options'      => in_array( 'options', $sources, true ),
		);

		$scanner = new URL_Usage_Finder_Scanner();
		$results = $scanner->search( $url, $source_map );

		$user_id = get_current_user_id();
		set_transient(
			self::TRANSIENT_KEY . $user_id,
			array(
				'needle'  => $url,
				'sources' => $sources,
				'results' => $results,
				'ts'      => time(),
			),
			10 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::MENU_SLUG,
					'search' => 'done',
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

		$old_url = isset( $_POST['uuf_old_url'] ) ? esc_url_raw( wp_unslash( $_POST['uuf_old_url'] ) ) : '';
		$new_url = isset( $_POST['uuf_new_url'] ) ? esc_url_raw( wp_unslash( $_POST['uuf_new_url'] ) ) : '';
		$chosen  = isset( $_POST['uuf_selected'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['uuf_selected'] ) ) : array();

		$user_id = get_current_user_id();
		$data    = get_transient( self::TRANSIENT_KEY . $user_id );
		$results = isset( $data['results'] ) && is_array( $data['results'] ) ? $data['results'] : array();

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

		set_transient(
			self::TRANSIENT_KEY . $user_id,
			array(
				'needle'      => $old_url,
				'new_url'     => $new_url,
				'sources'     => isset( $data['sources'] ) ? (array) $data['sources'] : array(),
				'results'     => $results,
				'replace_log' => $summary,
				'ts'          => time(),
			),
			10 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::MENU_SLUG,
					'replace' => 'done',
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

		$old_url = isset( $_POST['uuf_old_url'] ) ? esc_url_raw( wp_unslash( $_POST['uuf_old_url'] ) ) : '';
		$new_url = isset( $_POST['uuf_new_url'] ) ? esc_url_raw( wp_unslash( $_POST['uuf_new_url'] ) ) : '';
		$chosen  = isset( $_POST['uuf_selected'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['uuf_selected'] ) ) : array();

		$user_id = get_current_user_id();
		$data    = get_transient( self::TRANSIENT_KEY . $user_id );
		$results = isset( $data['results'] ) && is_array( $data['results'] ) ? $data['results'] : array();

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

		set_transient(
			self::TRANSIENT_KEY . $user_id,
			array(
				'needle'      => $old_url,
				'new_url'     => $new_url,
				'sources'     => isset( $data['sources'] ) ? (array) $data['sources'] : array(),
				'results'     => $results,
				'preview_log' => $preview,
				'ts'          => time(),
			),
			10 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::MENU_SLUG,
					'preview' => 'done',
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

		$user_id = get_current_user_id();
		$data    = get_transient( self::TRANSIENT_KEY . $user_id );
		$results = isset( $data['results'] ) ? (array) $data['results'] : array();

		$filename = 'url-usage-finder-' . gmdate( 'Ymd-His' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			exit;
		}

		fputcsv( $output, array( 'source', 'object', 'field', 'element', 'context', 'edit_link' ) );

		foreach ( $results as $row ) {
			fputcsv(
				$output,
				array(
					isset( $row['source'] ) ? (string) $row['source'] : '',
					isset( $row['object_label'] ) ? (string) $row['object_label'] : '',
					isset( $row['field'] ) ? (string) $row['field'] : '',
					isset( $row['element_hint'] ) ? (string) $row['element_hint'] : '',
					isset( $row['context'] ) ? (string) $row['context'] : '',
					isset( $row['edit_link'] ) ? (string) $row['edit_link'] : '',
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

		$user_id = get_current_user_id();
		$data    = get_transient( self::TRANSIENT_KEY . $user_id );
		$data    = is_array( $data ) ? $data : array();

		$needle  = isset( $data['needle'] ) ? (string) $data['needle'] : '';
		$sources = isset( $data['sources'] ) ? (array) $data['sources'] : array( 'post_content', 'post_meta', 'menus', 'options' );
		$results = isset( $data['results'] ) ? (array) $data['results'] : array();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'URL Usage Finder', 'url-usage-finder' ); ?></h1>
			<p><?php echo esc_html__( 'Find where a URL is used, then replace selected usages safely.', 'url-usage-finder' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SEARCH ); ?>" />
				<?php wp_nonce_field( self::ACTION_SEARCH ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="uuf_old_url"><?php echo esc_html__( 'Old URL', 'url-usage-finder' ); ?></label></th>
						<td><input id="uuf_old_url" name="uuf_old_url" class="regular-text code" type="url" required value="<?php echo esc_attr( $needle ); ?>" /></td>
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

			<?php self::render_replace_notice( $data ); ?>
			<?php self::render_preview_notice( $data ); ?>
			<?php self::render_results( $needle, $results ); ?>
		</div>
		<?php
	}

	private static function render_replace_notice( $data ) {
		if ( empty( $_GET['replace'] ) || empty( $data['replace_log'] ) || ! is_array( $data['replace_log'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$log = $data['replace_log'];
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				printf(
					/* translators: 1: updated count 2: skipped count */
					esc_html__( 'Replacement completed. Updated: %1$d. Skipped: %2$d.', 'url-usage-finder' ),
					isset( $log['updated'] ) ? (int) $log['updated'] : 0,
					isset( $log['skipped'] ) ? (int) $log['skipped'] : 0
				);
				?>
			</p>
		</div>
		<?php
	}

	private static function render_results( $needle, $results ) {
		if ( empty( $results ) ) {
			return;
		}

		$current_page  = isset( $_GET['uuf_paged'] ) ? max( 1, (int) $_GET['uuf_paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total_results = count( $results );
		$total_pages   = (int) ceil( $total_results / self::RESULTS_PER_PAGE );
		$offset        = ( $current_page - 1 ) * self::RESULTS_PER_PAGE;
		$page_rows     = array_slice( $results, $offset, self::RESULTS_PER_PAGE, true );
		?>
		<hr />
		<h2><?php echo esc_html__( 'Search Results', 'url-usage-finder' ); ?></h2>
		<p><?php printf( esc_html__( 'Found %d result(s). Select rows to preview or replace.', 'url-usage-finder' ), $total_results ); ?></p>
		<?php self::render_export_button(); ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_PREVIEW ); ?>" class="uuf-action-field" />
			<?php wp_nonce_field( self::ACTION_PREVIEW ); ?>
			<input type="hidden" name="uuf_old_url" value="<?php echo esc_attr( $needle ); ?>" />

			<table class="widefat striped">
				<thead>
				<tr>
					<th style="width:40px"><input type="checkbox" onclick="jQuery('.uuf-row-check').prop('checked', this.checked)" /></th>
					<th><?php echo esc_html__( 'Source', 'url-usage-finder' ); ?></th>
					<th><?php echo esc_html__( 'Object', 'url-usage-finder' ); ?></th>
					<th><?php echo esc_html__( 'Field', 'url-usage-finder' ); ?></th>
					<th><?php echo esc_html__( 'Element', 'url-usage-finder' ); ?></th>
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
						<td><?php echo esc_html( isset( $row['field'] ) ? (string) $row['field'] : '' ); ?></td>
						<td><?php echo esc_html( isset( $row['element_hint'] ) ? (string) $row['element_hint'] : '' ); ?></td>
						<td><code><?php echo esc_html( isset( $row['context'] ) ? (string) $row['context'] : '' ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h3><?php echo esc_html__( 'Replace selected rows', 'url-usage-finder' ); ?></h3>
			<p>
				<label for="uuf_new_url"><?php echo esc_html__( 'New URL', 'url-usage-finder' ); ?></label><br />
				<input id="uuf_new_url" name="uuf_new_url" class="regular-text code" type="url" required value="<?php echo isset( $_GET['preview'] ) && ! empty( $needle ) ? esc_attr( self::get_last_new_url() ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>" />
			</p>

			<p class="submit">
				<button type="submit" class="button"><?php echo esc_html__( 'Preview Selected (Dry Run)', 'url-usage-finder' ); ?></button>
				<button type="button" class="button button-primary uuf-replace-submit"><?php echo esc_html__( 'Replace Selected', 'url-usage-finder' ); ?></button>
			</p>
		</form>
		<?php self::render_pagination( $current_page, $total_pages ); ?>
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

	private static function render_export_button() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_EXPORT ); ?>" />
			<?php wp_nonce_field( self::ACTION_EXPORT ); ?>
			<button type="submit" class="button"><?php echo esc_html__( 'Export CSV', 'url-usage-finder' ); ?></button>
		</form>
		<?php
	}

	private static function render_pagination( $current_page, $total_pages ) {
		if ( $total_pages <= 1 ) {
			return;
		}

		$base_url = add_query_arg(
			array(
				'page'      => self::MENU_SLUG,
				'uuf_paged' => '%#%',
			),
			admin_url( 'tools.php' )
		);

		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'      => $base_url,
					'format'    => '',
					'current'   => $current_page,
					'total'     => $total_pages,
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
				)
			)
		);
		echo '</div></div>';
	}

	private static function get_last_new_url() {
		$user_id = get_current_user_id();
		$data    = get_transient( self::TRANSIENT_KEY . $user_id );

		return isset( $data['new_url'] ) ? (string) $data['new_url'] : '';
	}

	private static function render_preview_notice( $data ) {
		if ( empty( $_GET['preview'] ) || empty( $data['preview_log'] ) || ! is_array( $data['preview_log'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$preview = $data['preview_log'];
		?>
		<div class="notice notice-info">
			<p>
				<?php
				printf(
					/* translators: 1: changed count 2: skipped count */
					esc_html__( 'Dry-run completed. Changed: %1$d. Skipped: %2$d.', 'url-usage-finder' ),
					isset( $preview['changed'] ) ? (int) $preview['changed'] : 0,
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
					<td><code><?php echo esc_html( isset( $item['before'] ) ? (string) $item['before'] : '' ); ?></code></td>
					<td><code><?php echo esc_html( isset( $item['after'] ) ? (string) $item['after'] : '' ); ?></code></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
