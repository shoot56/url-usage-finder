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
	 * Last search debug details.
	 *
	 * @var array<string, mixed>
	 */
	private $debug_info = array();

	/**
	 * Search for URL in selected sources.
	 *
	 * @param string $needle Target URL.
	 * @param array  $sources Source flags.
	 * @return array<int, array<string, mixed>>
	 */
	public function search( $needle, $sources ) {
		$results          = array();
		$this->debug_info = array(
			'input'   => is_string( $needle ) ? trim( $needle ) : '',
			'needles' => array(),
			'sources' => array(),
			'total'   => 0,
		);

		if ( ! is_string( $needle ) || '' === trim( $needle ) ) {
			return $results;
		}

		$needle  = trim( $needle );
		$needles = $this->build_search_needles( $needle );
		$this->debug_info['needles'] = $needles;

		if ( empty( $needles ) ) {
			return $results;
		}

		if ( ! empty( $sources['post_content'] ) ) {
			$results = array_merge( $results, $this->search_post_content( $needles ) );
		}

		if ( ! empty( $sources['post_excerpt'] ) ) {
			$results = array_merge( $results, $this->search_post_excerpt( $needles ) );
		}

		if ( ! empty( $sources['post_meta'] ) ) {
			$results = array_merge( $results, $this->search_post_meta( $needles ) );
		}

		if ( ! empty( $sources['menus'] ) ) {
			$results = array_merge( $results, $this->search_menus( $needles ) );
		}

		if ( ! empty( $sources['options'] ) ) {
			$results = array_merge( $results, $this->search_options( $needles ) );
		}

		$this->debug_info['total'] = count( $results );

		return $results;
	}

	public function get_debug_info() {
		return $this->debug_info;
	}

	private function search_post_content( $needles ) {
		global $wpdb;

		$like_sql = $this->build_like_sql( 'post_content', $needles );
		$sql      = $this->prepare_sql(
			"SELECT ID, post_title, post_type, post_content
			FROM {$wpdb->posts}
			WHERE post_status NOT IN ('auto-draft','trash')
			  AND post_type <> 'revision'
			  AND ({$like_sql['where']})",
			$like_sql['values']
		);

		$rows    = $wpdb->get_results( $sql, ARRAY_A );
		$results = array();
		$this->record_source_debug( 'post_content', $rows );

		foreach ( $rows as $row ) {
			$matches        = $this->find_matches( $row['post_content'], $needles );
			if ( empty( $matches ) ) {
				continue;
			}
			$matched_needle = $this->get_first_matched_url( $matches );
			$results[] = array(
				'source'       => 'post_content',
				'object_type'  => 'post',
				'object_id'    => (int) $row['ID'],
				'object_label' => $this->build_post_label( $row ),
				'field'        => 'post_content',
				'context'      => $this->extract_context( $row['post_content'], $matched_needle ),
				'element_hint' => $this->detect_element_hint( $row['post_content'], $matched_needle ),
				'matched_url'  => $matched_needle,
				'matched_urls' => $this->get_unique_matched_urls( $matches ),
				'occurrences'  => count( $matches ),
				'edit_link'    => get_edit_post_link( (int) $row['ID'], 'raw' ),
				'view_link'    => get_permalink( (int) $row['ID'] ),
			);
		}

		return $results;
	}

	private function search_post_excerpt( $needles ) {
		global $wpdb;

		$like_sql = $this->build_like_sql( 'post_excerpt', $needles );
		$sql      = $this->prepare_sql(
			"SELECT ID, post_title, post_type, post_excerpt
			FROM {$wpdb->posts}
			WHERE post_status NOT IN ('auto-draft','trash')
			  AND post_type <> 'revision'
			  AND ({$like_sql['where']})",
			$like_sql['values']
		);

		$rows    = $wpdb->get_results( $sql, ARRAY_A );
		$results = array();
		$this->record_source_debug( 'post_excerpt', $rows );

		foreach ( $rows as $row ) {
			$matches        = $this->find_matches( $row['post_excerpt'], $needles );
			if ( empty( $matches ) ) {
				continue;
			}
			$matched_needle = $this->get_first_matched_url( $matches );
			$results[] = array(
				'source'       => 'post_excerpt',
				'object_type'  => 'post',
				'object_id'    => (int) $row['ID'],
				'object_label' => $this->build_post_label( $row ),
				'field'        => 'post_excerpt',
				'context'      => $this->extract_context( $row['post_excerpt'], $matched_needle ),
				'element_hint' => 'raw_text',
				'matched_url'  => $matched_needle,
				'matched_urls' => $this->get_unique_matched_urls( $matches ),
				'occurrences'  => count( $matches ),
				'edit_link'    => get_edit_post_link( (int) $row['ID'], 'raw' ),
				'view_link'    => get_permalink( (int) $row['ID'] ),
			);
		}

		return $results;
	}

	private function search_post_meta( $needles ) {
		global $wpdb;

		$like_sql = $this->build_like_sql( 'pm.meta_value', $needles );
		$sql      = $this->prepare_sql(
			"SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value, p.post_title, p.post_type
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE p.post_status NOT IN ('auto-draft','trash')
			  AND p.post_type <> 'revision'
			  AND ({$like_sql['where']})",
			$like_sql['values']
		);

		$rows    = $wpdb->get_results( $sql, ARRAY_A );
		$results = array();
		$this->record_source_debug( 'post_meta', $rows );

		foreach ( $rows as $row ) {
			$meta_value     = $this->stringify_value( maybe_unserialize( $row['meta_value'] ) );
			$matches        = $this->find_matches( $meta_value, $needles );
			if ( empty( $matches ) ) {
				continue;
			}
			$matched_needle = $this->get_first_matched_url( $matches );
			$results[]      = array(
				'source'       => 'post_meta',
				'object_type'  => 'post_meta',
				'object_id'    => (int) $row['meta_id'],
				'object_label' => $this->build_post_label( $row ),
				'post_id'      => (int) $row['post_id'],
				'field'        => (string) $row['meta_key'],
				'context'      => $this->extract_context( $meta_value, $matched_needle ),
				'element_hint' => $this->detect_element_hint( $meta_value, $matched_needle ),
				'matched_url'  => $matched_needle,
				'matched_urls' => $this->get_unique_matched_urls( $matches ),
				'occurrences'  => count( $matches ),
				'edit_link'    => get_edit_post_link( (int) $row['post_id'], 'raw' ),
				'view_link'    => get_permalink( (int) $row['post_id'] ),
			);
		}

		return $results;
	}

	private function search_menus( $needles ) {
		global $wpdb;

		$like_sql = $this->build_like_sql( 'pm.meta_value', $needles );
		$sql      = $this->prepare_sql(
			"SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value, p.post_title
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE p.post_type = 'nav_menu_item'
			  AND p.post_status NOT IN ('trash')
			  AND ({$like_sql['where']})",
			$like_sql['values']
		);

		$rows    = $wpdb->get_results( $sql, ARRAY_A );
		$results = array();
		$this->record_source_debug( 'menus', $rows );

		foreach ( $rows as $row ) {
			$meta_value     = $this->stringify_value( maybe_unserialize( $row['meta_value'] ) );
			$matches        = $this->find_matches( $meta_value, $needles );
			if ( empty( $matches ) ) {
				continue;
			}
			$matched_needle = $this->get_first_matched_url( $matches );
			$results[]      = array(
				'source'       => 'menus',
				'object_type'  => 'menu_item',
				'object_id'    => (int) $row['meta_id'],
				'object_label' => sprintf( 'Menu item #%d', (int) $row['post_id'] ),
				'post_id'      => (int) $row['post_id'],
				'field'        => (string) $row['meta_key'],
				'context'      => $this->extract_context( $meta_value, $matched_needle ),
				'element_hint' => $this->detect_element_hint( $meta_value, $matched_needle ),
				'matched_url'  => $matched_needle,
				'matched_urls' => $this->get_unique_matched_urls( $matches ),
				'occurrences'  => count( $matches ),
				'edit_link'    => admin_url( 'nav-menus.php' ),
				'view_link'    => null,
			);
		}

		return $results;
	}

	private function search_options( $needles ) {
		global $wpdb;

		$like_sql = $this->build_like_sql( 'option_value', $needles );
		$sql      = $this->prepare_sql(
			"SELECT option_id, option_name, option_value
			FROM {$wpdb->options}
			WHERE option_name NOT LIKE %s
			  AND option_name NOT LIKE %s
			  AND ({$like_sql['where']})",
			array_merge(
				array( '_transient_%', '_site_transient_%' ),
				$like_sql['values']
			)
		);

		$rows    = $wpdb->get_results( $sql, ARRAY_A );
		$results = array();
		$this->record_source_debug( 'options', $rows );

		foreach ( $rows as $row ) {
			$option_value   = $this->stringify_value( maybe_unserialize( $row['option_value'] ) );
			$matches        = $this->find_matches( $option_value, $needles );
			if ( empty( $matches ) ) {
				continue;
			}
			$matched_needle = $this->get_first_matched_url( $matches );
			$results[]      = array(
				'source'       => 'options',
				'object_type'  => 'option',
				'object_id'    => (int) $row['option_id'],
				'object_label' => (string) $row['option_name'],
				'field'        => 'option_value',
				'context'      => $this->extract_context( $option_value, $matched_needle ),
				'element_hint' => $this->detect_element_hint( $option_value, $matched_needle ),
				'matched_url'  => $matched_needle,
				'matched_urls' => $this->get_unique_matched_urls( $matches ),
				'occurrences'  => count( $matches ),
				'edit_link'    => null,
				'view_link'    => null,
			);
		}

		return $results;
	}

	private function record_source_debug( $source, $rows ) {
		global $wpdb;

		$this->debug_info['sources'][ $source ] = array(
			'rows'       => is_array( $rows ) ? count( $rows ) : 0,
			'last_error' => isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '',
		);
	}

	private function build_like_sql( $column, $needles ) {
		global $wpdb;

		$where  = array();
		$values = array();

		foreach ( $needles as $needle ) {
			$where[]  = $column . ' LIKE %s';
			$values[] = '%' . $wpdb->esc_like( $needle ) . '%';
		}

		return array(
			'where'  => implode( ' OR ', $where ),
			'values' => $values,
		);
	}

	private function prepare_sql( $query, $values ) {
		global $wpdb;

		return call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $query ), $values ) );
	}

	private function build_search_needles( $needle ) {
		$needle = trim( html_entity_decode( (string) $needle, ENT_QUOTES, get_bloginfo( 'charset' ) ) );
		if ( '' === $needle ) {
			return array();
		}

		$variants = array( $needle );
		$decoded  = rawurldecode( $needle );
		if ( $decoded !== $needle ) {
			$variants[] = $decoded;
		}

		$parts = $this->parse_url_input( $needle );
		if ( ! empty( $parts ) ) {
			$host = isset( $parts['host'] ) ? (string) $parts['host'] : '';
			$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';

			if ( isset( $parts['port'] ) ) {
				$host .= ':' . (int) $parts['port'];
			}

			$suffix = $path;
			if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
				$suffix .= '?' . $parts['query'];
			}
			if ( isset( $parts['fragment'] ) && '' !== $parts['fragment'] ) {
				$suffix .= '#' . $parts['fragment'];
			}

			if ( '' !== $host ) {
				$variants[] = 'https://' . $host . $suffix;
				$variants[] = 'http://' . $host . $suffix;
				$variants[] = '//' . $host . $suffix;
				$variants[] = $host . $suffix;
			}

			if ( '' !== $suffix && '/' !== $suffix ) {
				$variants[] = $suffix;
			}
		}

		$variants = $this->add_trailing_slash_variants( $variants );
		$variants = $this->add_url_encoded_variants( $variants );
		$variants = $this->add_json_escaped_variants( $variants );

		return array_values( array_unique( array_filter( $variants, 'strlen' ) ) );
	}

	private function parse_url_input( $needle ) {
		$value = trim( (string) $needle );
		if ( '' === $value ) {
			return array();
		}

		if ( 0 === strpos( $value, '//' ) ) {
			$value = 'https:' . $value;
		} elseif ( ! preg_match( '#^[a-z][a-z0-9+\-.]*://#i', $value ) && 0 !== strpos( $value, '/' ) && preg_match( '#^[^/?#]+\.[^/?#]+#', $value ) ) {
			$value = 'https://' . $value;
		}

		$parts = wp_parse_url( $value );
		return is_array( $parts ) ? $parts : array();
	}

	private function add_trailing_slash_variants( $variants ) {
		$expanded = array();

		foreach ( $variants as $variant ) {
			$expanded[] = $variant;

			$split_at = strcspn( $variant, '?#' );
			$base     = substr( $variant, 0, $split_at );
			$suffix   = substr( $variant, $split_at );

			if ( '' === $base || '/' === $base ) {
				continue;
			}

			$expanded[] = untrailingslashit( $base ) . $suffix;
			$expanded[] = trailingslashit( untrailingslashit( $base ) ) . $suffix;
		}

		return $expanded;
	}

	private function add_url_encoded_variants( $variants ) {
		$expanded = $variants;

		foreach ( $variants as $variant ) {
			$decoded = rawurldecode( $variant );
			if ( $decoded !== $variant ) {
				$expanded[] = $decoded;
			}

			$encoded = $this->encode_url_path_segments( $decoded );
			if ( $encoded !== $decoded ) {
				$expanded[] = $encoded;
				$expanded[] = $this->lowercase_percent_encoding( $encoded );
			}
		}

		return $expanded;
	}

	private function lowercase_percent_encoding( $url ) {
		return preg_replace_callback(
			'/%[0-9A-F]{2}/',
			static function ( $matches ) {
				return strtolower( $matches[0] );
			},
			(string) $url
		);
	}

	private function encode_url_path_segments( $url ) {
		$parts = $this->split_url_path_for_encoding( $url );
		if ( empty( $parts['path'] ) ) {
			return $url;
		}

		$encoded_path = implode(
			'/',
			array_map(
				static function ( $segment ) {
					return rawurlencode( rawurldecode( $segment ) );
				},
				explode( '/', $parts['path'] )
			)
		);

		return $parts['prefix'] . $encoded_path . $parts['suffix'];
	}

	private function split_url_path_for_encoding( $url ) {
		$prefix    = '';
		$path      = (string) $url;
		$suffix    = '';
		$query_pos = strcspn( $path, '?#' );

		if ( $query_pos < strlen( $path ) ) {
			$suffix = substr( $path, $query_pos );
			$path   = substr( $path, 0, $query_pos );
		}

		if ( preg_match( '#^([a-z][a-z0-9+\-.]*://[^/]*)(/.*)?$#i', $path, $matches ) ) {
			$prefix = $matches[1];
			$path   = isset( $matches[2] ) ? $matches[2] : '';
		} elseif ( preg_match( '#^(//[^/]*)(/.*)?$#', $path, $matches ) ) {
			$prefix = $matches[1];
			$path   = isset( $matches[2] ) ? $matches[2] : '';
		} elseif ( preg_match( '~^([^/?#]+\.[^/?#]*)(/.*)$~', $path, $matches ) ) {
			$prefix = $matches[1];
			$path   = $matches[2];
		}

		return array(
			'prefix' => $prefix,
			'path'   => $path,
			'suffix' => $suffix,
		);
	}

	private function add_json_escaped_variants( $variants ) {
		$expanded = $variants;

		foreach ( $variants as $variant ) {
			if ( false !== strpos( $variant, '/' ) ) {
				$expanded[] = str_replace( '/', '\/', $variant );
			}
		}

		return $expanded;
	}

	private function find_matches( $content, $needles ) {
		return URL_Usage_Finder_Matcher::find_matches( $content, $needles );
	}

	private function get_first_matched_url( $matches ) {
		return isset( $matches[0]['needle'] ) ? (string) $matches[0]['needle'] : '';
	}

	private function get_unique_matched_urls( $matches ) {
		$urls = array();

		foreach ( $matches as $match ) {
			if ( ! empty( $match['needle'] ) ) {
				$urls[] = (string) $match['needle'];
			}
		}

		return array_values( array_unique( $urls ) );
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
			return $this->normalize_context( substr( $content, 0, 220 ) );
		}

		$start = max( 0, $position - 80 );
		$len   = strlen( $needle ) + 160;
		$raw   = substr( $content, $start, $len );
		$prefix = $start > 0 ? '...' : '';
		$suffix = ( $start + $len ) < strlen( $content ) ? '...' : '';

		return $prefix . $this->normalize_context( $raw ) . $suffix;
	}

	private function normalize_context( $context ) {
		$context = html_entity_decode( (string) $context, ENT_QUOTES, get_bloginfo( 'charset' ) );
		$context = preg_replace( '/\s+/', ' ', $context );

		return trim( (string) $context );
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
