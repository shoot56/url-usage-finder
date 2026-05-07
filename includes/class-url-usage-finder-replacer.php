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
			$result = $this->process_row( $old_url, $new_url, $row, true );
			if ( ! empty( $result['error'] ) ) {
				$summary['errors'][] = (string) $result['error'];
				continue;
			}

			if ( empty( $result['changed'] ) ) {
				++$summary['skipped'];
				continue;
			}

			$updated = isset( $result['replacements'] ) ? (int) $result['replacements'] : 0;
			if ( $updated > 0 ) {
				++$summary['updated'];
				$summary['replacements'] += $updated;
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
			$preview = $this->process_row( $old_url, $new_url, $row, false );
			if ( ! empty( $preview['error'] ) ) {
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

	private function process_row( $old_url, $new_url, $row, $apply ) {
		$source = isset( $row['source'] ) ? (string) $row['source'] : '';

		if ( 'post_content' === $source || 'post_excerpt' === $source ) {
			return $this->process_post_field( $old_url, $new_url, $row, $apply );
		}

		if ( 'post_meta' === $source || 'menus' === $source ) {
			return $this->process_post_meta( $old_url, $new_url, $row, $apply );
		}

		if ( 'options' === $source ) {
			return $this->process_option( $old_url, $new_url, $row, $apply );
		}

		return array(
			'changed' => false,
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

	private function process_post_field( $old_url, $new_url, $row, $apply ) {
		$post_id = isset( $row['object_id'] ) ? (int) $row['object_id'] : 0;
		$field   = isset( $row['field'] ) ? (string) $row['field'] : 'post_content';
		if ( $post_id <= 0 || ! in_array( $field, array( 'post_content', 'post_excerpt' ), true ) ) {
			return array( 'changed' => false );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array( 'error' => 'Post not found: ' . $post_id );
		}

		$needles           = $this->get_matched_urls( $old_url, $row );
		$replacement_count = 0;
		$current           = (string) $post->{$field};
		$next              = URL_Usage_Finder_Matcher::replace_matches( $current, $needles, $new_url, $replacement_count );

		$result = $this->build_result_payload(
			$row,
			$current,
			$next,
			$needles,
			$new_url,
			$replacement_count
		);
		if ( empty( $result['changed'] ) || ! $apply ) {
			return $result;
		}

		$update = wp_update_post(
			array(
				'ID'   => $post_id,
				$field => $next,
			),
			true
		);

		if ( is_wp_error( $update ) ) {
			$result['error'] = $update->get_error_message();
			$result['changed'] = false;
		}

		return $result;
	}

	private function process_post_meta( $old_url, $new_url, $row, $apply ) {
		$post_id  = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
		$meta_key = isset( $row['field'] ) ? (string) $row['field'] : '';
		if ( $post_id <= 0 || '' === $meta_key ) {
			return array( 'changed' => false );
		}

		$needles           = $this->get_matched_urls( $old_url, $row );
		$replacement_count = 0;
		$current           = get_post_meta( $post_id, $meta_key, true );
		$next              = $this->recursive_replace( $current, $needles, $new_url, $replacement_count );

		$result = $this->build_result_payload(
			$row,
			$this->stringify_value( $current ),
			$this->stringify_value( $next ),
			$needles,
			$new_url,
			$replacement_count
		);
		if ( empty( $result['changed'] ) || ! $apply ) {
			return $result;
		}

		$updated = update_post_meta( $post_id, $meta_key, $next );
		if ( false === $updated ) {
			$result['error'] = 'Failed to update post meta.';
			$result['changed'] = false;
		}

		return $result;
	}

	private function process_option( $old_url, $new_url, $row, $apply ) {
		$option_name = isset( $row['object_label'] ) ? (string) $row['object_label'] : '';
		if ( '' === $option_name ) {
			return array( 'changed' => false );
		}

		$needles           = $this->get_matched_urls( $old_url, $row );
		$replacement_count = 0;
		$current           = get_option( $option_name, null );
		$next              = $this->recursive_replace( $current, $needles, $new_url, $replacement_count );

		$result = $this->build_result_payload(
			$row,
			$this->stringify_value( $current ),
			$this->stringify_value( $next ),
			$needles,
			$new_url,
			$replacement_count
		);
		if ( empty( $result['changed'] ) || ! $apply ) {
			return $result;
		}

		$updated = update_option( $option_name, $next, false );
		if ( false === $updated ) {
			$result['error'] = 'Failed to update option.';
			$result['changed'] = false;
		}

		return $result;
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
			return URL_Usage_Finder_Matcher::replace_matches( $value, $old_urls, $new_url, $replacement_count );
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

	private function build_result_payload( $row, $before_content, $after_content, $needles, $new_url, $replacement_count ) {
		$first_old_url = isset( $needles[0] ) ? (string) $needles[0] : '';

		return array(
			'changed'      => $before_content !== $after_content,
			'replacements' => (int) $replacement_count,
			'source'       => isset( $row['source'] ) ? (string) $row['source'] : '',
			'object_label' => isset( $row['object_label'] ) ? (string) $row['object_label'] : '',
			'field'        => isset( $row['field'] ) ? (string) $row['field'] : '',
			'before'       => $this->context_for_preview( (string) $before_content, $first_old_url ),
			'after'        => $this->context_for_preview( (string) $after_content, $new_url ),
		);
	}

	private function stringify_value( $value ) {
		if ( is_scalar( $value ) || null === $value ) {
			return (string) $value;
		}

		return (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
}
