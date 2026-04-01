<?php
/**
 * Plugin Name:  MFSD Ticker Tape
 * Plugin URI:   https://mfsd.me
 * Description:  Manages and displays role-targeted ticker tape messages
 *               in the My Future Self Digital theme header. Messages are
 *               created in the admin and assigned to one, multiple, or all
 *               user roles. The plugin hooks into the mfsd_ticker_tape_bar
 *               action registered in the myfutureself-theme.
 * Version:      1.0.2
 * Author:       MisterT9007
 * Author URI:   https://s47d.co.uk
 * Text Domain:  mfsd-ticker-tape
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

// ─── CONSTANTS ───────────────────────────────────────────────────────────────

define( 'MFSD_TICKER_VERSION', '1.0.2' );
define( 'MFSD_TICKER_DIR',     plugin_dir_path( __FILE__ ) );
define( 'MFSD_TICKER_URI',     plugin_dir_url( __FILE__ ) );
define( 'MFSD_TICKER_TABLE',   'mfsd_ticker_messages' );

// ─── INCLUDES ─────────────────────────────────────────────────────────────────

require_once MFSD_TICKER_DIR . 'includes/db.php';
require_once MFSD_TICKER_DIR . 'includes/admin.php';
require_once MFSD_TICKER_DIR . 'includes/frontend.php';

// ─── ACTIVATION / DEACTIVATION ───────────────────────────────────────────────

register_activation_hook( __FILE__, 'mfsd_ticker_activate' );
function mfsd_ticker_activate() {
    mfsd_ticker_create_table();
    // Seed a default welcome message so the tape isn't empty on first install.
    mfsd_ticker_seed_default();
}

register_deactivation_hook( __FILE__, 'mfsd_ticker_deactivate' );
function mfsd_ticker_deactivate() {
    // Nothing to clean up on deactivation — messages are preserved in the DB.
}
