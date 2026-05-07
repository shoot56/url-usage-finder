<?php
/**
 * Replacer for URL usage finder.
 *
 * @package URL_Usage_Finder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class URL_Usage_Finder_Replacer {

	/**
	 * Replace URL in selected result rows.
	 *
	 * @param string                              $old_url Old URL.
	 * @param string                              $new_url New URL.
	 * @param array<int, array<string, mixed>>    $rows Selected rows.
	 * @return array<string, mixed>
	 */
	public function replace_selected( $old_url, $new_url, $rows ) {
		$summary = array(
			'updated'      => 0,
			'replacements' => 0,
			'skipped'      => 0,
			'errors'       => array(),
		);

		foreach ( $rows as $row ) {
			$updated = $this->replace_single( $old_url, $new_url, $row );
			if ( is_int( $updated ) && $updated > 0 ) {
				++$summary['updated'];
				$summary['replacements'] += $updated;
			} elseif ( false === $updated ) {
				++$summary['skipped'];
			} else {
				$summary['errors'][] = (string) $updated;
			}
		}

		return $summary;
	}

	/**
	 * Build dry-run preview for selected rows without writing to DB.
	 *
	 * @param string                           $old_url Old URL.
	 * @param string                           $new_url New URL.
	 * @param array<int, array<string, mixed>> $rows Selected rows.
	 * @return array<string, mixed>
	 */
	public function preview_selected( $old_url, $new_url, $rows ) {
		$summary = array(
			'changed'      => 0,
			'replacements' => 0,
			'skipped'      => 0,
			'errors'       => array(),
			'items'        => array(),
		);

		foreach ( $rows as $row ) {
			$preview = $this->preview_single( $old_url, $new_url, $row );
			if ( isset( $preview['error'] ) ) {
				$summary['errors'][] = (string) $preview['error'];
				continue;
			}

			if ( empty( $preview['changed'] ) ) {
				++$summary['skipped'];
				continue;
			}

			++$summary['changed'];
			$summary['replacements'] += isset( $preview['replacements'] ) ? (int) $preview['replacements'] : 0;
			$summary['items'][] = $preview;
		}

		return $summary;
	}

	/**
	 * @param string                   $old_url Old URL.
	 * @param string                   $new_url New URL.
	 * @param array<string, mixed>     $row Result row.
	 * @return bool|string
	 */
	private function replace_single( $old_url, $new_url, $row ) {
		$source = isset( $row['source'] ) ? (string) $row['source'] : '';

		if ( 'post_content' === $source || 'post_excerpt' === $source ) {
			return $this->replace_in_post_field( $old_url, $new_url, $row );
		}

		if ( 'post_meta' === $source || 'menus' === $source ) {
			return $this->replace_in_post_meta( $old_url, $new_url, $row );
		}

		if ( 'options' === $source ) {
			return $this->replace_in_option( $old_url, $new_url, $row );
		}

		return false;
	}

	/**
	 * @param string               $old_url Old URL.
	 * @param string               $new_url New URL.
	 * @param array<string, mixed> $row Result row.
	 * @return array<string, mixed>
	 */
	private function preview_single( $old_url, $new_url, $row ) {
		$source  = isset( $row['source'] ) ? (string) $row['source'] : '';
		$current = '';

		if ( 'post_content' === $source || 'post_excerpt' === $source ) {
			$post_id = isset( $row['object_id'] ) ? (int) $row['object_id'] : 0;
			$field   = isset( $row['field'] ) ? (string) $row['field'] : 'post_content';
			$post    = get_post( $post_id );
			if ( ! $post || ! isset( $post->{$field} ) ) {
				return array( 'error' => 'Preview failed: post not found' );
			}

			$current = (string) $post->{$field};
		} elseif ( 'post_meta' === $source || 'menus' === $source ) {
			$post_id  = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
			$meta_key = isset( $row['field'] ) ? (string) $row['field'] : '';
			if ( $post_id <= 0 || '' === $meta_key ) {
				return array( 'error' => 'Preview failed: invalid post meta target' );
			}
			$current = (string) wp_json_encode( get_post_meta( $post_id, $meta_key, true ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		} elseif ( 'options' === $source ) {
			$option_name = isset( $row['object_label'] ) ? (string) $row['object_label'] : '';
			if ( '' === $option_name ) {
				return array( 'error' => 'Preview failed: invalid option name' );
			}
			$current = (string) wp_json_encode( get_option( $option_name, null ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		if ( '' === $current ) {
			return array( 'changed' => false );
		}

		$matched_old_urls  = $this->get_matched_urls( $old_url, $row );
		$replacement_count = 0;
		$next              = $this->replace_string_matches( $current, $matched_old_urls, $new_url, $replacement_count );
		$first_old_url     = isset( $matched_old_urls[0] ) ? (string) $matched_old_urls[0] : $old_url;

		return array(
			'changed'      => $next !== $current,
			'replacements' => $replacement_count,
			'source'       => isset( $row['source'] ) ? (string) $row['source'] : '',
			'object_label' => isset( $row['object_label'] ) ? (string) $row['object_label'] : '',
			'field'        => isset( $row['field'] ) ? (string) $row['field'] : '',
			'before'       => $this->context_for_preview( $current, $first_old_url ),
			'after'        => $this->context_for_preview( $next, $new_url ),
		);
	}

	private function context_for_preview( $content, $needle ) {
		if ( '' === $content || '' === $needle ) {
			return '';
		}

		$position = strpos( $content, $needle );
		if ( false === $position ) {
			return mb_substr( wp_strip_all_tags( $content ), 0, 220 );
		}

		$start = max( 0, $position - 80 );
		$len   = strlen( $needle ) + 160;

		return trim( wp_strip_all_tags( substr( $content, $start, $len ) ) );
	}

	private function replace_in_post_field( $old_url, $new_url, $row ) {
		$post_id = isset( $row['object_id'] ) ? (int) $row['object_id'] : 0;
		$field   = isset( $row['field'] ) ? (string) $row['field'] : 'post_content';
		if ( $post_id <= 0 || ! in_array( $field, array( 'post_content', 'post_excerpt' ), true ) ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return 'Post not found: ' . $post_id;
		}

		$replacement_count = 0;
		$current           = (string) $post->{$field};
		$next              = $this->replace_string_matches( $current, $this->get_matched_urls( $old_url, $row ), $new_url, $replacement_count );
		if ( $next === $current ) {
			return false;
		}

		$update = wp_update_post(
			array(
				'ID'    => $post_id,
				$field  => $next,
			),
			true
		);

		if ( is_wp_error( $update ) ) {
			return $update->get_error_message();
		}

		return $replacement_count;
	}

	private function replace_in_post_meta( $old_url, $new_url, $row ) {
		$post_id  = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
		$meta_key = isset( $row['field'] ) ? (string) $row['field'] : '';
		if ( $post_id <= 0 || '' === $meta_key ) {
			return false;
		}

		$replacement_count = 0;
		$current           = get_post_meta( $post_id, $meta_key, true );
		$next              = $this->recursive_replace( $current, $this->get_matched_urls( $old_url, $row ), $new_url, $replacement_count );

		if ( $next === $current ) {
			return false;
		}

		$updated = update_post_meta( $post_id, $meta_key, $next );
		return false !== $updated ? $replacement_count : false;
	}

	private function replace_in_option( $old_url, $new_url, $row ) {
		$option_name = isset( $row['object_label'] ) ? (string) $row['object_label'] : '';
		if ( '' === $option_name ) {
			return false;
		}

		$replacement_count = 0;
		$current           = get_option( $option_name, null );
		$next              = $this->recursive_replace( $current, $this->get_matched_urls( $old_url, $row ), $new_url, $replacement_count );

		if ( $next === $current ) {
			return false;
		}

		$updated = update_option( $option_name, $next, false );
		return false !== $updated ? $replacement_count : false;
	}

	/**
	 * Recursively replace URL in string/array/object.
	 *
	 * @param mixed  $value Value.
	 * @param array  $old_urls Old URL variants.
	 * @param string $new_url New URL.
	 * @param int    $replacement_count Replacement counter.
	 * @return mixed
	 */
	private function recursive_replace( $value, $old_urls, $new_url, &$replacement_count ) {
		if ( is_string( $value ) ) {
			return $this->replace_string_matches( $value, $old_urls, $new_url, $replacement_count );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->recursive_replace( $item, $old_urls, $new_url, $replacement_count );
			}

			return $value;
		}

		if ( is_object( $value ) ) {
			$vars = get_object_vars( $value );
			foreach ( $vars as $key => $item ) {
				$value->{$key} = $this->recursive_replace( $item, $old_urls, $new_url, $replacement_count );
			}
		}

		return $value;
	}

	private function get_matched_urls( $old_url, $row ) {
		if ( ! empty( $row['matched_urls'] ) && is_array( $row['matched_urls'] ) ) {
			$urls = array_values( array_unique( array_filter( array_map( 'strval', $row['matched_urls'] ), 'strlen' ) ) );
			if ( ! empty( $urls ) ) {
				return $this->sort_urls_by_length( $urls );
			}
		}

		if ( ! empty( $row['matched_url'] ) ) {
			return array( (string) $row['matched_url'] );
		}

		return array( (string) $old_url );
	}

	private function sort_urls_by_length( $urls ) {
		usort(
			$urls,
			static function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		return $urls;
	}

	private function replace_string_matches( $content, $old_urls, $new_url, &$replacement_count ) {
		$old_urls = $this->sort_urls_by_length( array_values( array_unique( array_filter( (array) $old_urls, 'strlen' ) ) ) );
		if ( '' === $content || empty( $old_urls ) ) {
			return $content;
		}

		$result = '';
		$length = strlen( $content );

		for ( $i = 0; $i < $length; ) {
			$matched_url = '';
			foreach ( $old_urls as $old_url ) {
				if ( $i === strpos( $content, $old_url, $i ) ) {
					$matched_url = $old_url;
					break;
				}
			}

			if ( '' !== $matched_url ) {
				$result .= $new_url;
				$i      += strlen( $matched_url );
				++$replacement_count;
				continue;
			}

			$result .= $content[ $i ];
			++$i;
		}

		return $result;
	}
}
