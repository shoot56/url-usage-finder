<?php
/**
 * Shared URL matching and replacement helper.
 *
 * @package URL_Usage_Finder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class URL_Usage_Finder_Matcher {

	public static function find_matches( $content, $needles ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return array();
		}

		$needles = self::normalize_needles( $needles );
		if ( empty( $needles ) ) {
			return array();
		}

		$candidates = array();
		foreach ( $needles as $needle ) {
			$offset = 0;
			while ( false !== ( $position = strpos( $content, $needle, $offset ) ) ) {
				if ( self::has_valid_url_boundary( $content, $needle, $position ) ) {
					$candidates[] = array(
						'needle'   => $needle,
						'position' => $position,
						'length'   => strlen( $needle ),
					);
				}
				$offset = $position + 1;
			}
		}

		usort(
			$candidates,
			static function ( $a, $b ) {
				if ( $a['position'] === $b['position'] ) {
					return $b['length'] <=> $a['length'];
				}

				return $a['position'] <=> $b['position'];
			}
		);

		$matches = array();
		foreach ( $candidates as $candidate ) {
			if ( self::overlaps_existing_match( $candidate, $matches ) ) {
				continue;
			}
			$matches[] = $candidate;
		}

		usort(
			$matches,
			static function ( $a, $b ) {
				return $a['position'] <=> $b['position'];
			}
		);

		return $matches;
	}

	public static function replace_matches( $content, $needles, $new_url, &$replacement_count ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}

		$matches = self::find_matches( $content, $needles );
		if ( empty( $matches ) ) {
			return $content;
		}

		$result   = '';
		$previous = 0;

		foreach ( $matches as $match ) {
			$start = (int) $match['position'];
			$len   = (int) $match['length'];
			$result .= substr( $content, $previous, $start - $previous );
			$result .= $new_url;
			$previous = $start + $len;
			++$replacement_count;
		}

		$result .= substr( $content, $previous );

		return $result;
	}

	private static function normalize_needles( $needles ) {
		$needles = array_values( array_unique( array_filter( array_map( 'strval', (array) $needles ), 'strlen' ) ) );
		usort(
			$needles,
			static function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		return $needles;
	}

	private static function has_valid_url_boundary( $content, $needle, $position ) {
		$last_char = substr( $needle, -1 );
		if ( in_array( $last_char, array( '/', '\\', '?', '#', '&', '=' ), true ) ) {
			return true;
		}

		$next_char_position = $position + strlen( $needle );
		if ( $next_char_position >= strlen( $content ) ) {
			return true;
		}

		$next_char = $content[ $next_char_position ];

		return ! preg_match( '/[A-Za-z0-9._~-]/', $next_char );
	}

	private static function overlaps_existing_match( $candidate, $matches ) {
		$candidate_start = (int) $candidate['position'];
		$candidate_end   = $candidate_start + (int) $candidate['length'];

		foreach ( $matches as $match ) {
			$match_start = (int) $match['position'];
			$match_end   = $match_start + (int) $match['length'];

			if ( $candidate_start < $match_end && $candidate_end > $match_start ) {
				return true;
			}
		}

		return false;
	}
}
