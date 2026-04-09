<?php
/**
 * MFSD Ticker Tape — Database Layer
 *
 * Table: {prefix}mfsd_ticker_messages
 *
 * Columns:
 *   id             INT AUTO_INCREMENT PRIMARY KEY
 *   message        TEXT NOT NULL              — The ticker tape text.
 *                                               Supports tokens: {first_name} {display_name}
 *                                               {username} {course_name}
 *   roles          VARCHAR(500) NOT NULL      — JSON array of role slugs, e.g. ["student","parent"]
 *                                               Use ["all"] to target every role
 *   message_type   VARCHAR(20) DEFAULT 'standard'
 *                                             — 'standard' | 'course_enrolment' | 'user_specific' | 'rss_feed'
 *   course_id      BIGINT DEFAULT 0           — Post ID of the course (used when message_type = course_enrolment)
 *   target_user_id BIGINT DEFAULT 0           — WP user ID for user-specific messages (0 = no restriction)
 *   feed_url       VARCHAR(1000) DEFAULT ''   — RSS feed URL (used when message_type = rss_feed)
 *   feed_limit     TINYINT DEFAULT 5          — Max headlines to pull from the feed
 *   feed_prefix    VARCHAR(200) DEFAULT ''    — Optional label prepended to each headline e.g. "📰 BBC:"
 *   active         TINYINT(1) DEFAULT 1       — 1 = live, 0 = paused
 *   sort_order     INT DEFAULT 0              — Display order (lower = first)
 *   created_at     DATETIME DEFAULT NOW()
 *   updated_at     DATETIME DEFAULT NOW() ON UPDATE NOW()
 */

defined( 'ABSPATH' ) || exit;

// ─── SCHEMA VERSION ──────────────────────────────────────────────────────────

define( 'MFSD_TICKER_DB_VERSION', '2.2.0' );

/**
 * Create the ticker messages table on plugin activation.
 */
function mfsd_ticker_create_table(): void {
    global $wpdb;

    $table           = $wpdb->prefix . MFSD_TICKER_TABLE;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
        message        TEXT          NOT NULL,
        roles          VARCHAR(500)  NOT NULL DEFAULT '[\"all\"]',
        message_type   VARCHAR(20)   NOT NULL DEFAULT 'standard',
        course_id      BIGINT        NOT NULL DEFAULT 0,
        target_user_id BIGINT        NOT NULL DEFAULT 0,
        feed_url       VARCHAR(1000) NOT NULL DEFAULT '',
        feed_limit     TINYINT       NOT NULL DEFAULT 5,
        feed_prefix    VARCHAR(200)  NOT NULL DEFAULT '',
        active         TINYINT(1)    NOT NULL DEFAULT 1,
        sort_order     INT           NOT NULL DEFAULT 0,
        created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_active_order (active, sort_order),
        KEY idx_target_user  (target_user_id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'mfsd_ticker_db_version', MFSD_TICKER_DB_VERSION );
}

/**
 * Run any needed schema upgrades.
 * Called on admin_init so upgrades apply automatically after a plugin update.
 */
function mfsd_ticker_maybe_upgrade(): void {
    $installed = get_option( 'mfsd_ticker_db_version', '1.0.0' );
    if ( version_compare( $installed, MFSD_TICKER_DB_VERSION, '>=' ) ) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . MFSD_TICKER_TABLE;

    // If the table doesn't exist yet (fresh install not yet activated),
    // create it now rather than trying to ALTER a missing table.
    $table_exists = $wpdb->get_var(
        $wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
    );

    if ( ! $table_exists ) {
        mfsd_ticker_create_table();
        return;
    }

    $columns = $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 );

    if ( ! in_array( 'message_type', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN message_type VARCHAR(20) NOT NULL DEFAULT 'standard' AFTER roles" );
    }
    if ( ! in_array( 'course_id', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN course_id BIGINT NOT NULL DEFAULT 0 AFTER message_type" );
    }
    if ( ! in_array( 'target_user_id', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN target_user_id BIGINT NOT NULL DEFAULT 0 AFTER course_id" );
    }
    if ( ! in_array( 'feed_url', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN feed_url VARCHAR(1000) NOT NULL DEFAULT '' AFTER target_user_id" );
    }
    if ( ! in_array( 'feed_limit', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN feed_limit TINYINT NOT NULL DEFAULT 5 AFTER feed_url" );
    }
    if ( ! in_array( 'feed_prefix', $columns, true ) ) {
        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN feed_prefix VARCHAR(200) NOT NULL DEFAULT '' AFTER feed_limit" );
    }

    update_option( 'mfsd_ticker_db_version', MFSD_TICKER_DB_VERSION );
}
add_action( 'admin_init', 'mfsd_ticker_maybe_upgrade' );


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
                'message'        => '🚀 Welcome to My Future Self Digital, {first_name}! Explore your courses, earn coins and unlock your potential.',
                'roles'          => json_encode( [ 'all' ] ),
                'message_type'   => 'standard',
                'course_id'      => 0,
                'target_user_id' => 0,
                'feed_url'       => '',
                'feed_limit'     => 5,
                'feed_prefix'    => '',
                'active'         => 1,
                'sort_order'     => 0,
            ],
            [ '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%d', '%d' ]
        );
    }
}


// ─── TOKEN RESOLUTION ─────────────────────────────────────────────────────────

/**
 * Available message tokens and their descriptions (shown in admin).
 *
 * @return array  token => description
 */
function mfsd_ticker_available_tokens(): array {
    return [
        '{first_name}'   => __( "User's first name (e.g. \"Sarah\")", 'mfsd-ticker-tape' ),
        '{display_name}' => __( "User's display name (e.g. \"Sarah Jones\")", 'mfsd-ticker-tape' ),
        '{username}'     => __( "User's login username", 'mfsd-ticker-tape' ),
        '{course_name}'  => __( 'Name of the enrolled course (course enrolment type only)', 'mfsd-ticker-tape' ),
    ];
}

/**
 * Replace tokens in a message string for the given user and optional course.
 *
 * @param string  $message
 * @param WP_User $user
 * @param int     $course_id  Post ID of course, 0 if not applicable.
 * @return string  Resolved, already-escaped message text ready for output.
 */
function mfsd_ticker_resolve_tokens( string $message, WP_User $user, int $course_id = 0 ): string {
    $first_name  = ! empty( $user->first_name ) ? $user->first_name : $user->display_name;
    $course_name = '';

    if ( $course_id > 0 ) {
        $course_post = get_post( $course_id );
        $course_name = $course_post ? $course_post->post_title : '';
    }

    $replacements = [
        '{first_name}'   => esc_html( $first_name ),
        '{display_name}' => esc_html( $user->display_name ),
        '{username}'     => esc_html( $user->user_login ),
        '{course_name}'  => esc_html( $course_name ),
    ];

    return str_replace( array_keys( $replacements ), array_values( $replacements ), $message );
}


// ─── RSS FEED HELPERS ─────────────────────────────────────────────────────────

/**
 * Fetch headlines from an RSS feed URL.
 *
 * Results are cached in a WordPress transient for 30 minutes so the feed is
 * not requested on every page load. Uses WP's built-in SimplePie integration.
 *
 * @param string $feed_url    Full URL of the RSS/Atom feed.
 * @param int    $limit       Max number of headlines to return (1–20).
 * @param string $prefix      Optional prefix prepended to each headline, e.g. "📰 BBC:".
 * @return string[]           Array of plain-text headline strings, ready for esc_html().
 */
function mfsd_ticker_fetch_rss_headlines( string $feed_url, int $limit = 5, string $prefix = '' ): array {
    if ( empty( $feed_url ) ) {
        return [];
    }

    $limit         = max( 1, min( 20, $limit ) );
    $transient_key = 'mfsd_rss_' . md5( $feed_url . $limit );

    $cached = get_transient( $transient_key );
    if ( is_array( $cached ) ) {
        return $cached;
    }

    // Use WordPress HTTP API directly — more reliable than SimplePie on all hosts.
    $response = wp_remote_get( $feed_url, [
        'timeout'    => 10,
        'user-agent' => 'Mozilla/5.0 (compatible; MFSD Ticker/' . MFSD_TICKER_VERSION . '; +https://mfsd.me)',
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( 'MFSD RSS ERROR: wp_error - ' . $response->get_error_message() . ' for ' . $feed_url );
        set_transient( $transient_key, [], 5 * MINUTE_IN_SECONDS );
        return [];
    }

    $http_code = wp_remote_retrieve_response_code( $response );
    if ( $http_code !== 200 ) {
        error_log( 'MFSD RSS ERROR: HTTP ' . $http_code . ' for ' . $feed_url );
        set_transient( $transient_key, [], 5 * MINUTE_IN_SECONDS );
        return [];
    }

    $body = wp_remote_retrieve_body( $response );
    if ( empty( $body ) ) {
        error_log( 'MFSD RSS ERROR: empty body for ' . $feed_url );
        set_transient( $transient_key, [], 5 * MINUTE_IN_SECONDS );
        return [];
    }

    error_log( 'MFSD RSS: got ' . strlen( $body ) . ' bytes from ' . $feed_url );

    // Parse the XML safely.
    libxml_use_internal_errors( true );
    $xml = simplexml_load_string( $body );
    $xml_errors = libxml_get_errors();
    libxml_clear_errors();

    if ( $xml === false ) {
        error_log( 'MFSD RSS ERROR: XML parse failed for ' . $feed_url . ' - ' . print_r( $xml_errors, true ) );
        set_transient( $transient_key, [], 5 * MINUTE_IN_SECONDS );
        return [];
    }

    error_log( 'MFSD RSS: XML parsed OK, checking for items' );

    // Support both RSS 2.0 (channel/item) and Atom (entry).
    $items = [];
    if ( isset( $xml->channel->item ) ) {
        $items = $xml->channel->item; // RSS 2.0
    } elseif ( isset( $xml->entry ) ) {
        $items = $xml->entry; // Atom
    }

    $headlines = [];
    $prefix    = trim( $prefix );
    $count     = 0;
    foreach ( $items as $item ) {
        if ( $count >= $limit ) break;
        $title = wp_strip_all_tags( (string) $item->title );
        if ( $title ) {
            $headlines[] = $prefix !== '' ? $prefix . ' ' . $title : $title;
            $count++;
        }
    }

    set_transient( $transient_key, $headlines, 30 * MINUTE_IN_SECONDS );
    return $headlines;
}

/**
 * Clear the RSS cache for a given feed URL (called on save/update).
 *
 * @param string $feed_url
 * @param int    $limit
 */
function mfsd_ticker_clear_rss_cache( string $feed_url, int $limit = 5 ): void {
    delete_transient( 'mfsd_rss_' . md5( $feed_url . $limit ) );
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
 * Get messages visible to the current user.
 *
 * Applies three layers of filtering:
 *   1. User-specific messages  — only shown to that exact user ID.
 *   2. Course-enrolment messages — shown only when user is enrolled in that course.
 *   3. Standard role-based messages — shown to matching roles.
 *
 * Tokens are NOT resolved here — resolution happens at render time in frontend.php.
 *
 * @param string  $current_role  e.g. 'student'
 * @param WP_User $user
 * @return array[]
 */
function mfsd_ticker_get_messages_for_user( string $current_role, WP_User $user ): array {
    global $wpdb;
    $table    = $wpdb->prefix . MFSD_TICKER_TABLE;
    $messages = $wpdb->get_results(
        "SELECT * FROM {$table} WHERE active = 1 ORDER BY sort_order ASC, id ASC",
        ARRAY_A
    ) ?: [];

    $user_id          = (int) $user->ID;
    $enrolled_courses = mfsd_ticker_get_user_enrolled_course_ids( $user_id );

    $visible = [];
    foreach ( $messages as $msg ) {
        $type        = $msg['message_type'] ?? 'standard';
        $target_user = (int) ( $msg['target_user_id'] ?? 0 );
        $course_id   = (int) ( $msg['course_id'] ?? 0 );
        $roles       = json_decode( $msg['roles'], true );
        if ( ! is_array( $roles ) ) {
            $roles = [ 'all' ];
        }

        // ── 1. User-specific ─────────────────────────────────────────────────
        if ( $target_user > 0 ) {
            if ( $target_user === $user_id ) {
                $visible[] = $msg;
            }
            // Never show to anyone else — skip remaining checks.
            continue;
        }

        // ── 2. Course enrolment ───────────────────────────────────────────────
        if ( $type === 'course_enrolment' ) {
            if ( $course_id > 0 && in_array( $course_id, $enrolled_courses, true ) ) {
                $visible[] = $msg;
            }
            continue;
        }

        // ── 3. RSS feed — role-gated, headlines resolved at render time ───────
        if ( $type === 'rss_feed' ) {
            if ( in_array( 'all', $roles, true ) || in_array( $current_role, $roles, true ) ) {
                $visible[] = $msg;
            }
            continue;
        }

        // ── 4. Standard role-based ────────────────────────────────────────────
        if ( in_array( 'all', $roles, true ) || in_array( $current_role, $roles, true ) ) {
            $visible[] = $msg;
        }
    }

    return $visible;
}

/**
 * Back-compat wrapper — role only, derives user from current session.
 *
 * @param string $current_role
 * @return array[]
 */
function mfsd_ticker_get_messages_for_role( string $current_role ): array {
    $user = wp_get_current_user();
    if ( ! $user->exists() ) {
        return [];
    }
    return mfsd_ticker_get_messages_for_user( $current_role, $user );
}

/**
 * Return course post IDs the user is enrolled in.
 *
 * Supports LearnDash out of the box; falls back to a custom user-meta key
 * (mfsd_enrolled_courses) which can be populated by other LMS plugins.
 *
 * @param int $user_id
 * @return int[]
 */
function mfsd_ticker_get_user_enrolled_course_ids( int $user_id ): array {
    if ( function_exists( 'learndash_user_get_enrolled_courses' ) ) {
        return array_map( 'intval', learndash_user_get_enrolled_courses( $user_id ) );
    }

    $meta = get_user_meta( $user_id, 'mfsd_enrolled_courses', true );
    if ( is_array( $meta ) ) {
        return array_map( 'intval', $meta );
    }

    return [];
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
 * @param array  $roles
 * @param int    $active
 * @param int    $sort_order
 * @param string $message_type    'standard' | 'course_enrolment' | 'user_specific' | 'rss_feed'
 * @param int    $course_id       Post ID of course (0 if not applicable)
 * @param int    $target_user_id  WP user ID (0 = no restriction)
 * @param string $feed_url        RSS feed URL (rss_feed type only)
 * @param int    $feed_limit      Max headlines (1–20)
 * @param string $feed_prefix     Optional prefix label
 * @return int|false
 */
function mfsd_ticker_insert_message(
    string $message,
    array  $roles,
    int    $active         = 1,
    int    $sort_order     = 0,
    string $message_type   = 'standard',
    int    $course_id      = 0,
    int    $target_user_id = 0,
    string $feed_url       = '',
    int    $feed_limit     = 5,
    string $feed_prefix    = ''
) {
    global $wpdb;
    $table  = $wpdb->prefix . MFSD_TICKER_TABLE;
    $result = $wpdb->insert(
        $table,
        [
            'message'        => $message,
            'roles'          => json_encode( $roles ),
            'message_type'   => $message_type,
            'course_id'      => $course_id,
            'target_user_id' => $target_user_id,
            'feed_url'       => $feed_url,
            'feed_limit'     => $feed_limit,
            'feed_prefix'    => $feed_prefix,
            'active'         => $active,
            'sort_order'     => $sort_order,
        ],
        [ '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%d', '%d' ]
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
 * @param string $message_type
 * @param int    $course_id
 * @param int    $target_user_id
 * @param string $feed_url
 * @param int    $feed_limit
 * @param string $feed_prefix
 * @return bool
 */
function mfsd_ticker_update_message(
    int    $id,
    string $message,
    array  $roles,
    int    $active,
    int    $sort_order,
    string $message_type   = 'standard',
    int    $course_id      = 0,
    int    $target_user_id = 0,
    string $feed_url       = '',
    int    $feed_limit     = 5,
    string $feed_prefix    = ''
): bool {
    global $wpdb;
    $table  = $wpdb->prefix . MFSD_TICKER_TABLE;

    // Clear cached headlines when a feed entry is updated.
    if ( $message_type === 'rss_feed' && $feed_url ) {
        mfsd_ticker_clear_rss_cache( $feed_url, $feed_limit );
    }

    $result = $wpdb->update(
        $table,
        [
            'message'        => $message,
            'roles'          => json_encode( $roles ),
            'message_type'   => $message_type,
            'course_id'      => $course_id,
            'target_user_id' => $target_user_id,
            'feed_url'       => $feed_url,
            'feed_limit'     => $feed_limit,
            'feed_prefix'    => $feed_prefix,
            'active'         => $active,
            'sort_order'     => $sort_order,
        ],
        [ 'id' => $id ],
        [ '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%d', '%d' ],
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
    $table   = $wpdb->prefix . MFSD_TICKER_TABLE;
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