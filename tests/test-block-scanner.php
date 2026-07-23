<?php
/**
 * Tests for the block scanner.
 *
 * The scanner replicates DiviOps' own module_update byte-scan
 * (diviops-agent/includes/trait-page.php:1402, documented there as matching
 * get_page_layout's auto_index counting): every block is counted per type in
 * document order, including blocks with no JSON attributes. The one deliberate
 * departure is namespace-aware name extraction, so difl/faq is not truncated to
 * difl at the separating slash.
 *
 * @package DiviOpsModuleBridge
 */

require_once dirname( __DIR__ ) . '/includes/class-block-scanner.php';

use Rtv_Diviops\Block_Scanner;

/*
 * A mixed tree: divi/*, difl/*, core/*, a self-closing block, a no-JSON block,
 * a repeated third-party type (to exercise per-type counting), and an empty-attrs
 * block. Whitespace between comments is intentional and must not affect counting.
 */
$content = implode(
	'',
	array(
		'<!-- wp:divi/section {"a":1} -->',
		'<!-- wp:divi/row {} -->',
		'<!-- wp:difl/faq {"x":2} --><!-- /wp:difl/faq -->',
		'<!-- wp:difl/faq {"x":3} --><!-- /wp:difl/faq -->',
		'<!-- wp:core/paragraph {"y":4} --><p>hi</p><!-- /wp:core/paragraph -->',
		'<!-- wp:difl/icon {"i":1} /-->',
		'<!-- wp:divi/placeholder -->',
		'<!-- wp:divi/text {"z":5} --><!-- /wp:divi/text -->',
		'<!-- /wp:divi/row -->',
		'<!-- /wp:divi/section -->',
	)
);

$blocks = Block_Scanner::scan( $content );

assert_same( 8, count( $blocks ), 'scan finds every opening block, and no closing tags' );

// Document-order names.
$names = array_map(
	static function ( array $b ): string {
		return $b['name'];
	},
	$blocks
);
assert_same(
	array( 'divi/section', 'divi/row', 'difl/faq', 'difl/faq', 'core/paragraph', 'difl/icon', 'divi/placeholder', 'divi/text' ),
	$names,
	'names are extracted whole, including the third-party namespace'
);

// Short names: divi/ is stripped, everything else is kept whole.
$short = array_map(
	static function ( array $b ): string {
		return $b['short_name'];
	},
	$blocks
);
assert_same(
	array( 'section', 'row', 'difl/faq', 'difl/faq', 'core/paragraph', 'difl/icon', 'placeholder', 'text' ),
	$short,
	'short_name strips only the divi/ prefix'
);

// auto_index labels: short_name:N, counted per type in document order.
$auto = array_map(
	static function ( array $b ): string {
		return $b['auto_index'];
	},
	$blocks
);
assert_same(
	array( 'section:1', 'row:1', 'difl/faq:1', 'difl/faq:2', 'core/paragraph:1', 'difl/icon:1', 'placeholder:1', 'text:1' ),
	$auto,
	'the repeated difl/faq increments; unrelated types keep independent counters'
);

// The no-JSON placeholder still counts, but is flagged unwritable.
$placeholder = $blocks[6];
assert_same( 'placeholder:1', $placeholder['auto_index'], 'a block with no JSON attributes still counts for auto_index' );
assert_same( false, $placeholder['has_json'], 'a block with no JSON attributes is flagged has_json=false' );

// The self-closing difl/icon is recognized as self-closing and carries JSON.
$icon = $blocks[5];
assert_same( true, $icon['is_self_closing'], 'a /--> block is flagged self-closing' );
assert_same( true, $icon['has_json'], 'the self-closing block still exposes its JSON' );

// Byte offsets locate the opening comment exactly.
$faq2 = $blocks[3];
assert_same(
	'<!-- wp:difl/faq {"x":3} -->',
	substr( $content, $faq2['start'], $faq2['open_end'] - $faq2['start'] ),
	'start/open_end bound the second faq opening comment precisely'
);
assert_same(
	'{"x":3}',
	substr( $content, $faq2['json_start'], $faq2['json_end'] - $faq2['json_start'] ),
	'json_start/json_end bound the attribute JSON precisely'
);

// find() resolves an auto_index label to its block, and fails closed on a miss.
$found = Block_Scanner::find( $content, 'difl/faq:2' );
assert_true( is_array( $found ), 'find resolves an existing auto_index target' );
assert_same( $faq2['start'], $found['start'], 'find returns the correct occurrence' );

assert_same( null, Block_Scanner::find( $content, 'difl/faq:5' ), 'find fails closed when the index does not exist' );
assert_same( null, Block_Scanner::find( $content, 'nope:1' ), 'find fails closed on an unknown type' );
assert_same( null, Block_Scanner::find( $content, 'malformed' ), 'find fails closed on a malformed target' );
