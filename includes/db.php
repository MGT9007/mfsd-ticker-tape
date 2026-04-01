<?php
/**
 * MFSD Ticker Tape — Database Layer
 *
 * Table: {prefix}mfsd_ticker_messages
 *
 * Columns:
 *   id          INT AUTO_INCREMENT PRIMARY KEY
 *   message     TEXT NOT NULL              — The ticker tape text
 *   roles       VARCHAR(500) NOT NULL      — JSON array of role slugs, e.g. ["student","parent"]
 *                                            Use ["all"] to target every role
 *   active      TINYINT(1) DEFAULT 1       — 1 = live, 0 = paused
 *   sort_order  INT DEFAULT 0              — Display order (lower = first)
 *   created_at  DATETIME DEFAULT NOW()
 *   updated_at  DATETIME DEFAULT NOW() ON UPDATE NOW()
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create the ticker messages table on plugin activation.
 */
function mfsd_ticker_create_table(): void {
    global $wpdb;

    $table      = $wpdb->prefix . MFSD_TICKER_TABLE;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        message     TEXT         NOT NULL,
        roles       VARCHAR(500) NOT NULL DEFAULT '[\"all\"]',
        active      TINYINT(1)   NOT NULL DEFAULT 1,
        sort_order  INT          NOT NULL DEFAULT 0,
        created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'mfsd_ticker_db_version', MFSD_TICKER_VERSION );
}

/**
 * Insert a default welcome message if the table is empty.
 */
function mfsd_ticker_seed_default(): void {
    global $wpdb;
    $table = $wpdb->prefix . MFSD_TICKER_TABLE;
    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    if ( $count === 0 ) {
        $wpdb->insert(
            $table,
            [
                'message'    => '🚀 Welcome to My Future Self Digital! Explore your courses, earn coins and unlock your potential.',
                'roles'      => json_encode( [ 'all' ] ),
                'active'     => 1,
                'sort_order' => 0,
            ],
            [ '%s', '%s', '%d', '%d' ]
        );
    }
}


// ─── CRUD HELPERS ─────────────────────────────────────────────────────────────

/**
 * Get all messages (admin use — all statuses).
 *
 * @return array[]
 */
function mfsd_ticker_get_all_messages(): array {
    global $wpdb;
    $table = $wpdb->prefix . MFSD_TICKER_TABLE;
    return $wpdb->get_results(
        "SELECT * FROM {$table} ORDER BY sort_order ASC, id ASC",
        ARRAY_A
    ) ?: [];
}

/**
 * Get messages visible to the current user's role.
 *
 * @param string $current_role  e.g. 'student', 'parent', 'admin'
 * @return array[]
 */
function mfsd_ticker_get_messages_for_role( string $current_role ): array {
    global $wpdb;
    $table    = $wpdb->prefix . MFSD_TICKER_TABLE;
    $messages = $wpdb->get_results(
        "SELECT * FROM {$table} WHERE active = 1 ORDER BY sort_order ASC, id ASC",
        ARRAY_A
    ) ?: [];

    return array_filter( $messages, function( $msg ) use ( $current_role ) {
        $roles = json_decode( $msg['roles'], true );
        if ( ! is_array( $roles ) ) return false;
        return in_array( 'all', $roles, true ) || in_array( $current_role, $roles, true );
    } );
}

/**
 * Get a single message by ID.
 *
 * @param int $id
 * @return array|null
 */
function mfsd_ticker_get_message( int $id ): ?array {
    global $wpdb;
    $table = $wpdb->prefix . MFSD_TICKER_TABLE;
    $row   = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
        ARRAY_A
    );
    return $row ?: null;
}

/**
 * Insert a new message.
 *
 * @param string $message
 * @param array  $roles       Array of role slugs, e.g. ['student'] or ['all']
 * @param int    $active
 * @param int    $sort_order
 * @return int|false  Inserted ID or false on failure.
 */
function mfsd_ticker_insert_message( string $message, array $roles, int $active = 1, int $sort_order = 0 ) {
    global $wpdb;
    $table  = $wpdb->prefix . MFSD_TICKER_TABLE;
    $result = $wpdb->insert(
        $table,
        [
            'message'    => $message,
            'roles'      => json_encode( $roles ),
            'active'     => $active,
            'sort_order' => $sort_order,
        ],
        [ '%s', '%s', '%d', '%d' ]
    );
    return $result ? $wpdb->insert_id : false;
}

/**
 * Update an existing message.
 *
 * @param int    $id
 * @param string $message
 * @param array  $roles
 * @param int    $active
 * @param int    $sort_order
 * @return bool
 */
function mfsd_ticker_update_message( int $id, string $message, array $roles, int $active, int $sort_order ): bool {
    global $wpdb;
    $table  = $wpdb->prefix . MFSD_TICKER_TABLE;
    $result = $wpdb->update(
        $table,
        [
            'message'    => $message,
            'roles'      => json_encode( $roles ),
            'active'     => $active,
            'sort_order' => $sort_order,
        ],
        [ 'id' => $id ],
        [ '%s', '%s', '%d', '%d' ],
        [ '%d' ]
    );
    return $result !== false;
}

/**
 * Delete a message.
 *
 * @param int $id
 * @return bool
 */
function mfsd_ticker_delete_message( int $id ): bool {
    global $wpdb;
    $table = $wpdb->prefix . MFSD_TICKER_TABLE;
    return (bool) $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
}

/**
 * Toggle a message's active state.
 *
 * @param int $id
 * @return bool
 */
function mfsd_ticker_toggle_active( int $id ): bool {
    global $wpdb;
    $table = $wpdb->prefix . MFSD_TICKER_TABLE;
    $current = (int) $wpdb->get_var(
        $wpdb->prepare( "SELECT active FROM {$table} WHERE id = %d", $id )
    );
    return (bool) $wpdb->update(
        $table,
        [ 'active' => $current ? 0 : 1 ],
        [ 'id'     => $id ],
        [ '%d' ],
        [ '%d' ]
    );
}
