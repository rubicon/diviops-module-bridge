<?php
/**
 * Attribute merge: pure, WordPress-free deep merge of one block's attributes.
 *
 * @package DiviOpsModuleBridge
 */

declare( strict_types = 1 );

namespace Rtv_Diviops;

defined( 'ABSPATH' ) || 'cli' === PHP_SAPI || exit;

/**
 * Deep-merges an attribute patch into one block's attribute JSON.
 *
 * The merge runs on the decoded object graph rather than an associative array.
 * `json_decode( $json, true )` turns an empty object `{}` into an empty array
 * `[]`, which re-encodes as `[]` and silently changes Divi attribute semantics.
 * Decoding to objects keeps `{}` an empty object, so it survives the round trip
 * by construction. This is the {} to [] hazard the design spec calls out, handled
 * for one block's attributes rather than across a whole re-serialized document.
 *
 * Existing property order is preserved and new keys append in patch order, so the
 * byte-splice that consumes this output produces the smallest possible diff.
 */
final class Attr_Merge {

	/**
	 * Deep-merge a patch into existing attribute JSON.
	 *
	 * @param string $existing_json One block's current attribute JSON object.
	 * @param string $patch_json    The attribute patch, a JSON object.
	 * @return string The merged attribute JSON.
	 */
	public static function merge_json( string $existing_json, string $patch_json ): string {
		$existing = json_decode( $existing_json );
		$patch    = json_decode( $patch_json );

		$merged = self::merge( $existing, $patch );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure function; runs in the test suite without WordPress loaded, so wp_json_encode is unavailable.
		return (string) json_encode( $merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Recursively merge a patch value into an existing value.
	 *
	 * Two objects merge key by key; anything else means the patch replaces the
	 * existing value outright, so unlike types never merge into a hybrid.
	 *
	 * @param mixed $existing The existing value.
	 * @param mixed $patch    The patch value.
	 * @return mixed The merged value.
	 */
	private static function merge( $existing, $patch ) {
		if ( ! $existing instanceof \stdClass || ! $patch instanceof \stdClass ) {
			return $patch;
		}

		foreach ( $patch as $key => $value ) {
			if ( isset( $existing->$key ) && $existing->$key instanceof \stdClass && $value instanceof \stdClass ) {
				$existing->$key = self::merge( $existing->$key, $value );
			} else {
				$existing->$key = $value;
			}
		}

		return $existing;
	}
}
