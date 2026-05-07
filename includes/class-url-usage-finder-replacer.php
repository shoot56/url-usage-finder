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
			'updated' => 0,
			'skipped' => 0,
			'errors'  => array(),
		);

		foreach ( $rows as $row ) {
			$updated = $this->replace_single( $old_url, $new_url, $row );
			if ( true === $updated ) {
				++$summary['updated'];
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
			'changed' => 0,
			'skipped' => 0,
			'errors'  => array(),
			'items'   => array(),
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

		$next = str_replace( $old_url, $new_url, $current );

		return array(
			'changed'      => $next !== $current,
			'source'       => isset( $row['source'] ) ? (string) $row['source'] : '',
			'object_label' => isset( $row['object_label'] ) ? (string) $row['object_label'] : '',
			'field'        => isset( $row['field'] ) ? (string) $row['field'] : '',
			'before'       => $this->context_for_preview( $current, $old_url ),
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

		$current = (string) $post->{$field};
		$next    = str_replace( $old_url, $new_url, $current );
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

		return true;
	}

	private function replace_in_post_meta( $old_url, $new_url, $row ) {
		$post_id  = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
		$meta_key = isset( $row['field'] ) ? (string) $row['field'] : '';
		if ( $post_id <= 0 || '' === $meta_key ) {
			return false;
		}

		$current = get_post_meta( $post_id, $meta_key, true );
		$next    = $this->recursive_replace( $current, $old_url, $new_url );

		if ( $next === $current ) {
			return false;
		}

		$updated = update_post_meta( $post_id, $meta_key, $next );
		return false !== $updated;
	}

	private function replace_in_option( $old_url, $new_url, $row ) {
		$option_name = isset( $row['object_label'] ) ? (string) $row['object_label'] : '';
		if ( '' === $option_name ) {
			return false;
		}

		$current = get_option( $option_name, null );
		$next    = $this->recursive_replace( $current, $old_url, $new_url );

		if ( $next === $current ) {
			return false;
		}

		$updated = update_option( $option_name, $next, false );
		return false !== $updated;
	}

	/**
	 * Recursively replace URL in string/array/object.
	 *
	 * @param mixed  $value Value.
	 * @param string $old_url Old URL.
	 * @param string $new_url New URL.
	 * @return mixed
	 */
	private function recursive_replace( $value, $old_url, $new_url ) {
		if ( is_string( $value ) ) {
			return str_replace( $old_url, $new_url, $value );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->recursive_replace( $item, $old_url, $new_url );
			}

			return $value;
		}

		if ( is_object( $value ) ) {
			$vars = get_object_vars( $value );
			foreach ( $vars as $key => $item ) {
				$value->{$key} = $this->recursive_replace( $item, $old_url, $new_url );
			}
		}

		return $value;
	}
}
