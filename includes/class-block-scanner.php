<?php
/**
 * Block scanner: pure, WordPress-free block-targeting core.
 *
 * @package DiviOpsModuleBridge
 */

declare( strict_types = 1 );

namespace Rtv_Diviops;

defined( 'ABSPATH' ) || 'cli' === PHP_SAPI || exit;

/**
 * Scans block markup and resolves auto_index targets.
 *
 * This replicates DiviOps' own module_update byte-scan (in the DiviOps Agent
 * plugin, includes/trait-page.php, documented there as matching get_page_layout's
 * auto_index counting): every block is counted per type in document order,
 * including blocks that carry no JSON attributes. The single deliberate departure
 * is namespace-aware name extraction, so difl/faq is kept whole rather than being
 * truncated to difl at the separating slash. Getting the counting identical to
 * DiviOps is the whole point, because the indices a caller reads from a layout
 * dump must be the indices that resolve here.
 */
final class Block_Scanner {

	/**
	 * The opening-delimiter prefix shared by every block. A closing delimiter is
	 * `<!-- /wp:`, which this prefix does not match, so closers are skipped for
	 * free.
	 */
	private const OPEN_PREFIX = '<!-- wp:';

	/**
	 * Scan content into an ordered list of block descriptors.
	 *
	 * @param string $content Block markup.
	 * @return array<int, array<string, mixed>> One descriptor per opening block,
	 *                                          in document order.
	 */
	public static function scan( string $content ): array {
		$blocks   = array();
		$counters = array();
		$offset   = 0;
		$prefix   = strlen( self::OPEN_PREFIX );

		while ( true ) {
			$pos = strpos( $content, self::OPEN_PREFIX, $offset );
			if ( false === $pos ) {
				break;
			}

			$name_start = $pos + $prefix;

			$name = self::read_name( $content, $name_start );
			if ( '' === $name ) {
				// Not a real block opener; step past this candidate and continue.
				$offset = $name_start;
				continue;
			}

			$after = $name_start + strlen( $name );

			$has_json   = isset( $content[ $after ], $content[ $after + 1 ] )
				&& ' ' === $content[ $after ] && '{' === $content[ $after + 1 ];
			$json_start = null;
			$json_end   = null;
			$cursor     = $after;

			if ( $has_json ) {
				$json_start = $after + 1;
				$json_end   = self::read_json_end( $content, $json_start );
				if ( -1 === $json_end ) {
					// Unbalanced attributes: the document is malformed past here.
					break;
				}
				$cursor = $json_end;
			}

			while ( isset( $content[ $cursor ] ) && ' ' === $content[ $cursor ] ) {
				++$cursor;
			}

			if ( isset( $content[ $cursor ] ) && '/' === $content[ $cursor ]
				&& '-->' === substr( $content, $cursor + 1, 3 ) ) {
				$is_self_closing = true;
				$open_end        = $cursor + 4;
			} elseif ( '-->' === substr( $content, $cursor, 3 ) ) {
				$is_self_closing = false;
				$open_end        = $cursor + 3;
			} else {
				// No terminator where one was expected; skip this candidate.
				$offset = $after;
				continue;
			}

			$short = 0 === strpos( $name, 'divi/' ) ? substr( $name, 5 ) : $name;

			$counters[ $short ] = ( $counters[ $short ] ?? 0 ) + 1;

			$blocks[] = array(
				'name'            => $name,
				'short_name'      => $short,
				'index'           => $counters[ $short ],
				'auto_index'      => $short . ':' . $counters[ $short ],
				'start'           => $pos,
				'open_end'        => $open_end,
				'has_json'        => $has_json,
				'is_self_closing' => $is_self_closing,
				'json_start'      => $json_start,
				'json_end'        => $json_end,
			);

			$offset = $open_end;
		}

		return $blocks;
	}

	/**
	 * Resolve an auto_index label (`short_name:N`) to its block descriptor.
	 *
	 * Fails closed: an unknown type, an out-of-range index, or a malformed label
	 * returns null rather than resolving to the wrong block.
	 *
	 * @param string $content    Block markup.
	 * @param string $auto_index Target label, e.g. `difl/faq:2`.
	 * @return array<string, mixed>|null The matching descriptor, or null.
	 */
	public static function find( string $content, string $auto_index ): ?array {
		foreach ( self::scan( $content ) as $block ) {
			if ( $block['auto_index'] === $auto_index ) {
				return $block;
			}
		}
		return null;
	}

	/**
	 * Read a WordPress block name at an offset: `namespace/name`, or a bare
	 * `name` for core blocks written without their namespace.
	 *
	 * @param string $content    Block markup.
	 * @param int    $name_start Offset just past the opening prefix.
	 * @return string The block name, or an empty string if none is present.
	 */
	private static function read_name( string $content, int $name_start ): string {
		if ( preg_match( '#\G[a-z][a-z0-9-]*(?:/[a-z][a-z0-9-]*)?#', $content, $matches, 0, $name_start ) ) {
			return $matches[0];
		}
		return '';
	}

	/**
	 * Given the offset of a `{`, return the offset just past its matching `}`.
	 *
	 * String-aware and escape-aware, so braces inside attribute string values,
	 * including Divi's `$variable({...})$` tokens, do not shift the depth count.
	 *
	 * @param string $content Block markup.
	 * @param int    $open    Offset of the opening brace.
	 * @return int The offset just past the matching brace, or -1 if unbalanced.
	 */
	private static function read_json_end( string $content, int $open ): int {
		$len       = strlen( $content );
		$depth     = 0;
		$in_string = false;
		$escaped   = false;

		for ( $i = $open; $i < $len; $i++ ) {
			$char = $content[ $i ];

			if ( $in_string ) {
				if ( $escaped ) {
					$escaped = false;
				} elseif ( '\\' === $char ) {
					$escaped = true;
				} elseif ( '"' === $char ) {
					$in_string = false;
				}
				continue;
			}

			if ( '"' === $char ) {
				$in_string = true;
			} elseif ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char ) {
				--$depth;
				if ( 0 === $depth ) {
					return $i + 1;
				}
			}
		}

		return -1;
	}
}
