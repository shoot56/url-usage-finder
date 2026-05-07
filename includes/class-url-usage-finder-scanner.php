<?php
/**
 * Scanner for URL usage across WordPress data stores.
 *
 * @package URL_Usage_Finder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class URL_Usage_Finder_Scanner {

	/**
	 * Search for URL in selected sources.
	 *
	 * @param string $needle Target URL.
	 * @param array  $sources Source flags.
	 * @return array<int, array<string, mixed>>
	 */
	public function search( $needle, $sources ) {
		$results = array();

		if ( ! is_string( $needle ) || '' === trim( $needle ) ) {
			return $results;
		}

		$needle = trim( $needle );

		if ( ! empty( $sources['post_content'] ) ) {
			$results = array_merge( $results, $this->search_post_content( $needle ) );
		}

		if ( ! empty( $sources['post_excerpt'] ) ) {
			$results = array_merge( $results, $this->search_post_excerpt( $needle ) );
		}

		if ( ! empty( $sources['post_meta'] ) ) {
			$results = array_merge( $results, $this->search_post_meta( $needle ) );
		}

		if ( ! empty( $sources['menus'] ) ) {
			$results = array_merge( $results, $this->search_menus( $needle ) );
		}

		if ( ! empty( $sources['options'] ) ) {
			$results = array_merge( $results, $this->search_options( $needle ) );
		}

		return $results;
	}

	private function search_post_content( $needle ) {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $needle ) . '%';
		$sql  = $wpdb->prepare(
			"SELECT ID, post_title, post_type, post_content
			FROM {$wpdb->posts}
			WHERE post_status NOT IN ('auto-draft','trash')
			  AND post_content LIKE %s",
			$like
		);

		$rows    = $wpdb->get_results( $sql, ARRAY_A );
		$results = array();

		foreach ( $rows as $row ) {
			$results[] = array(
				'source'       => 'post_content',
				'object_type'  => 'post',
				'object_id'    => (int) $row['ID'],
				'object_label' => $this->build_post_label( $row ),
				'field'        => 'post_content',
				'context'      => $this->extract_context( $row['post_content'], $needle ),
				'element_hint' => $this->detect_element_hint( $row['post_content'], $needle ),
				'edit_link'    => get_edit_post_link( (int) $row['ID'], 'raw' ),
			);
		}

		return $results;
	}

	private function search_post_excerpt( $needle ) {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $needle ) . '%';
		$sql  = $wpdb->prepare(
			"SELECT ID, post_title, post_type, post_excerpt
			FROM {$wpdb->posts}
			WHERE post_status NOT IN ('auto-draft','trash')
			  AND post_excerpt LIKE %s",
			$like
		);

		$rows    = $wpdb->get_results( $sql, ARRAY_A );
		$results = array();

		foreach ( $rows as $row ) {
			$results[] = array(
				'source'       => 'post_excerpt',
				'object_type'  => 'post',
				'object_id'    => (int) $row['ID'],
				'object_label' => $this->build_post_label( $row ),
				'field'        => 'post_excerpt',
				'context'      => $this->extract_context( $row['post_excerpt'], $needle ),
				'element_hint' => 'raw_text',
				'edit_link'    => get_edit_post_link( (int) $row['ID'], 'raw' ),
			);
		}

		return $results;
	}

	private function search_post_meta( $needle ) {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $needle ) . '%';
		$sql  = $wpdb->prepare(
			"SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value, p.post_title, p.post_type
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE p.post_status NOT IN ('auto-draft','trash')
			  AND pm.meta_value LIKE %s",
			$like
		);

		$rows    = $wpdb->get_results( $sql, ARRAY_A );
		$results = array();

		foreach ( $rows as $row ) {
			$meta_value = $this->stringify_value( maybe_unserialize( $row['meta_value'] ) );
			$results[]  = array(
				'source'       => 'post_meta',
				'object_type'  => 'post_meta',
				'object_id'    => (int) $row['meta_id'],
				'object_label' => $this->build_post_label( $row ),
				'post_id'      => (int) $row['post_id'],
				'field'        => (string) $row['meta_key'],
				'context'      => $this->extract_context( $meta_value, $needle ),
				'element_hint' => $this->detect_element_hint( $meta_value, $needle ),
				'edit_link'    => get_edit_post_link( (int) $row['post_id'], 'raw' ),
			);
		}

		return $results;
	}

	private function search_menus( $needle ) {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $needle ) . '%';
		$sql  = $wpdb->prepare(
			"SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value, p.post_title
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE p.post_type = 'nav_menu_item'
			  AND p.post_status NOT IN ('trash')
			  AND pm.meta_value LIKE %s",
			$like
		);

		$rows    = $wpdb->get_results( $sql, ARRAY_A );
		$results = array();

		foreach ( $rows as $row ) {
			$meta_value = $this->stringify_value( maybe_unserialize( $row['meta_value'] ) );
			$results[]  = array(
				'source'       => 'menus',
				'object_type'  => 'menu_item',
				'object_id'    => (int) $row['meta_id'],
				'object_label' => sprintf( 'Menu item #%d', (int) $row['post_id'] ),
				'post_id'      => (int) $row['post_id'],
				'field'        => (string) $row['meta_key'],
				'context'      => $this->extract_context( $meta_value, $needle ),
				'element_hint' => $this->detect_element_hint( $meta_value, $needle ),
				'edit_link'    => admin_url( 'nav-menus.php' ),
			);
		}

		return $results;
	}

	private function search_options( $needle ) {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $needle ) . '%';
		$sql  = $wpdb->prepare(
			"SELECT option_id, option_name, option_value
			FROM {$wpdb->options}
			WHERE option_name NOT LIKE %s
			  AND option_name NOT LIKE %s
			  AND option_value LIKE %s",
			'_transient_%',
			'_site_transient_%',
			$like
		);

		$rows    = $wpdb->get_results( $sql, ARRAY_A );
		$results = array();

		foreach ( $rows as $row ) {
			$option_value = $this->stringify_value( maybe_unserialize( $row['option_value'] ) );
			$results[]    = array(
				'source'       => 'options',
				'object_type'  => 'option',
				'object_id'    => (int) $row['option_id'],
				'object_label' => (string) $row['option_name'],
				'field'        => 'option_value',
				'context'      => $this->extract_context( $option_value, $needle ),
				'element_hint' => $this->detect_element_hint( $option_value, $needle ),
				'edit_link'    => null,
			);
		}

		return $results;
	}

	private function build_post_label( $row ) {
		$post_id = 0;
		if ( isset( $row['ID'] ) ) {
			$post_id = (int) $row['ID'];
		} elseif ( isset( $row['post_id'] ) ) {
			$post_id = (int) $row['post_id'];
		}

		$title = ! empty( $row['post_title'] ) ? $row['post_title'] : sprintf( 'Post #%d', $post_id );
		$type  = ! empty( $row['post_type'] ) ? $row['post_type'] : 'post';

		return sprintf( '%s (%s)', $title, $type );
	}

	private function extract_context( $content, $needle ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return '';
		}

		$position = strpos( $content, $needle );
		if ( false === $position ) {
			return mb_substr( wp_strip_all_tags( $content ), 0, 220 );
		}

		$start = max( 0, $position - 80 );
		$len   = strlen( $needle ) + 160;
		$raw   = substr( $content, $start, $len );

		return trim( wp_strip_all_tags( $raw ) );
	}

	private function detect_element_hint( $content, $needle ) {
		if ( ! is_string( $content ) ) {
			return 'raw_text';
		}

		$position = strpos( $content, $needle );
		if ( false === $position ) {
			return 'raw_text';
		}

		$start  = max( 0, $position - 120 );
		$length = strlen( $needle ) + 240;
		$window = substr( $content, $start, $length );
		$lower  = strtolower( $window );

		if ( false !== strpos( $lower, '<img' ) || false !== strpos( $lower, ' src=' ) ) {
			return 'image';
		}

		if ( false !== strpos( $lower, '<a ' ) || false !== strpos( $lower, ' href=' ) ) {
			if ( false !== strpos( $lower, 'wp-block-button' ) || false !== strpos( $lower, 'button' ) ) {
				return 'button';
			}

			return 'link';
		}

		return 'raw_text';
	}

	private function stringify_value( $value ) {
		if ( is_scalar( $value ) || null === $value ) {
			return (string) $value;
		}

		return wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
}
