<?php
/**
 * Tests for the attribute merge.
 *
 * A patch is deep-merged into one block's attribute JSON. The merge operates on
 * the decoded object graph rather than an associative array, so an empty object
 * stays an empty object and never degrades to an empty array. That degradation is
 * the {} to [] hazard the design spec calls out: it silently changes Divi
 * attribute semantics. Working on the object graph avoids it by construction.
 *
 * Existing property order is preserved and new keys append in patch order, so the
 * byte-splice that follows produces the smallest possible diff.
 *
 * @package DiviOpsModuleBridge
 */

require_once dirname( __DIR__ ) . '/includes/class-attr-merge.php';

use Rtv_Diviops\Attr_Merge;

// Deep merge combines nested siblings rather than clobbering the whole subtree.
assert_same(
	'{"module":{"advanced":{"a":1},"decoration":{"b":2}}}',
	Attr_Merge::merge_json( '{"module":{"advanced":{"a":1}}}', '{"module":{"decoration":{"b":2}}}' ),
	'a nested patch merges into the existing subtree instead of replacing it'
);

// An empty object at the top level survives a merge that never touches it.
assert_same(
	'{"a":1,"group":{},"b":2,"c":3}',
	Attr_Merge::merge_json( '{"a":1,"group":{},"b":2}', '{"c":3}' ),
	'a top-level empty object is preserved as {} and the new key appends in order'
);

// A scalar patch overwrites a scalar.
assert_same(
	'{"x":2}',
	Attr_Merge::merge_json( '{"x":1}', '{"x":2}' ),
	'a scalar value is overwritten by the patch'
);

// A scalar patch replaces an object outright (no merge of unlike types).
assert_same(
	'{"x":5}',
	Attr_Merge::merge_json( '{"x":{"a":1}}', '{"x":5}' ),
	'a scalar patch replaces an object value entirely'
);

// An empty object nested inside a subtree survives a recursive merge around it.
assert_same(
	'{"outer":{"inner":{},"keep":1}}',
	Attr_Merge::merge_json( '{"outer":{"inner":{}}}', '{"outer":{"keep":1}}' ),
	'an empty object nested inside a merged subtree is preserved'
);

// A patch that adds a brand-new empty object keeps it empty.
assert_same(
	'{"a":1,"cleared":{}}',
	Attr_Merge::merge_json( '{"a":1}', '{"cleared":{}}' ),
	'a patch may introduce an empty object and it stays {}'
);
