<?php
/**
 * Plugin Name:       DiviOps Module Bridge
 * Plugin URI:        https://github.com/rubicon/diviops-module-bridge
 * Description:       Teaches DiviOps Agent to read and write third-party Divi 5 modules. Built to be made obsolete by an upstream fix.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  diviops-agent
 * Author:            Dax Davis
 * Author URI:        https://daxdavis.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       diviops-module-bridge
 * Domain Path:       /languages
 * Update URI:        https://github.com/rubicon/diviops-module-bridge
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * DiviOps Agent hardcodes the `divi/` namespace in its block-targeting paths, so
 * third-party Divi 5 modules (difl/*, decm/*, d5bgo/*) can be read in a layout
 * dump but never addressed for get or update. This plugin resolves those targets
 * after DiviOps has already failed the lookup.
 *
 * KNOWN LIMITATION: the bridge attaches to `rest_post_dispatch`, which WordPress
 * fires only for real HTTP requests to the REST API. In-process callers using
 * rest_do_request() go straight through WP_REST_Server::dispatch() and bypass
 * this plugin entirely. That is acceptable because the DiviOps MCP server makes
 * real HTTP calls, but a PHP-side caller will silently get DiviOps' unmodified
 * behavior. Do not spend an afternoon debugging that.
 *
 * @package DiviOpsModuleBridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Machine-facing identifiers use the `rtv_` vendor prefix (options, filters,
 * nonce actions, transient keys). The plugin slug and text domain intentionally
 * do not, so the public identity reads as what it is. See CLAUDE.md.
 */
define( 'RTV_DIVIOPS_BRIDGE_VERSION', '0.1.0' );
define( 'RTV_DIVIOPS_BRIDGE_FILE', __FILE__ );
define( 'RTV_DIVIOPS_BRIDGE_DIR', plugin_dir_path( __FILE__ ) );
