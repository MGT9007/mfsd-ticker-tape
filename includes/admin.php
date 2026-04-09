<?php
/**
 * MFSD Ticker Tape — Admin Screen
 *
 * Adds a submenu under the MFSD admin group (or top-level if not present).
 * Admin can:
 *   - View all ticker messages with status, type, roles, and order
 *   - Add / edit / delete messages
 *   - Toggle active/paused
 *   - Set display order
 *   - Choose message type: Standard | Course Enrolment | User-Specific
 *   - Use personalisation tokens ({first_name} etc.)
 */

defined( 'ABSPATH' ) || exit;


// ─── MENU REGISTRATION ───────────────────────────────────────────────────────

add_action( 'admin_menu', 'mfsd_ticker_register_menu' );
function mfsd_ticker_register_menu(): void {

    if ( ! mfsd_ticker_mfsd_menu_exists() ) {
        add_menu_page(
            __( 'MFSD Ticker Tape', 'mfsd-ticker-tape' ),
            __( 'MFSD Ticker', 'mfsd-ticker-tape' ),
            'manage_options',
            'mfsd-ticker-tape',
            'mfsd_ticker_render_admin_page',
            'dashicons-megaphone',
            58
        );
    } else {
        add_submenu_page(
            'mfsd-admin',
            __( 'Ticker Tape', 'mfsd-ticker-tape' ),
            __( 'Ticker Tape', 'mfsd-ticker-tape' ),
            'manage_options',
            'mfsd-ticker-tape',
            'mfsd_ticker_render_admin_page'
        );
    }
}

function mfsd_ticker_mfsd_menu_exists(): bool {
    global $menu;
    if ( ! is_array( $menu ) ) return false;
    foreach ( $menu as $item ) {
        if ( isset( $item[2] ) && $item[2] === 'mfsd-admin' ) return true;
    }
    return false;
}


// ─── ADMIN ASSETS ────────────────────────────────────────────────────────────

add_action( 'admin_enqueue_scripts', 'mfsd_ticker_admin_assets' );
function mfsd_ticker_admin_assets( string $hook ): void {
    // Only load on our page.
    if ( strpos( $hook, 'mfsd-ticker-tape' ) === false ) return;

    wp_enqueue_style(
        'mfsd-ticker-admin',
        MFSD_TICKER_URI . 'assets/css/admin.css',
        [],
        MFSD_TICKER_VERSION
    );

    // Inline JS for dynamic form sections + user search.
    wp_add_inline_script( 'jquery', mfsd_ticker_admin_inline_js() );
}

/**
 * Inline JS — no separate file needed for these small helpers.
 */
function mfsd_ticker_admin_inline_js(): string {
    $js  = 'jQuery(function($){';
    $js .= 'function mfsdUpdateTypeUI(){';
    $js .= 'var type=$("input[name=\'message_type\']:checked").val();';
    $js .= '$(".mfsd-ticker-admin__section--roles").toggle(type!=="user_specific"&&type!=="rss_feed");';
    $js .= '$(".mfsd-ticker-admin__section--course").toggle(type==="course_enrolment");';
    $js .= '$(".mfsd-ticker-admin__section--user").toggle(type==="user_specific");';
    $js .= '$(".mfsd-ticker-admin__section--rss").toggle(type==="rss_feed");';
    $js .= 'var isRss=(type==="rss_feed");';
    $js .= '$("#mfsd_ticker_message").prop("required",!isRss);';
    $js .= '$(".mfsd-ticker-admin__msg-required").toggle(!isRss);';
    $js .= '$(".mfsd-ticker-admin__msg-desc-standard").toggle(!isRss);';
    $js .= '$(".mfsd-ticker-admin__msg-desc-rss").toggle(isRss);';
    $js .= 'if(type==="rss_feed"){$(".mfsd-ticker-admin__section--roles").show();}';
    $js .= '}';
    $js .= '$("input[name=\'message_type\']").on("change",mfsdUpdateTypeUI);mfsdUpdateTypeUI();';
    $js .= 'var userTimer;';
    $js .= '$("#mfsd_user_search").on("input",function(){';
    $js .= 'var q=$(this).val().trim();clearTimeout(userTimer);';
    $js .= 'if(q.length<2){$("#mfsd_user_results").empty().hide();return;}';
    $js .= 'userTimer=setTimeout(function(){';
    $js .= '$.post(ajaxurl,{action:"mfsd_ticker_user_search",nonce:mfsdTicker.nonce,q:q},function(res){';
    $js .= 'var $ul=$("#mfsd_user_results").empty();';
    $js .= 'if(!res.success||!res.data.length){$ul.append(\'<li class="mfsd-no-results">No users found</li>\').show();return;}';
    $js .= '$.each(res.data,function(i,u){$("<li>").text(u.label).attr("data-id",u.id).on("click",function(){';
    $js .= '$("#mfsd_target_user_id").val(u.id);$("#mfsd_user_search").val(u.label);$ul.empty().hide();';
    $js .= '}).appendTo($ul);});$ul.show();});},300);});';
    $js .= '$(document).on("click",".mfsd-token-btn",function(){';
    $js .= 'var token=$(this).data("token");var $ta=$("#mfsd_ticker_message")[0];';
    $js .= 'var start=$ta.selectionStart,end=$ta.selectionEnd,val=$ta.value;';
    $js .= '$ta.value=val.slice(0,start)+token+val.slice(end);';
    $js .= '$ta.focus();$ta.selectionStart=$ta.selectionEnd=start+token.length;});';
    $js .= '$("#mfsd_clear_user").on("click",function(){';
    $js .= '$("#mfsd_target_user_id").val("0");$("#mfsd_user_search").val("");$("#mfsd_user_results").empty().hide();});';
    $js .= '$(document).on("click",function(e){';
    $js .= 'if(!$(e.target).closest(".mfsd-ticker-admin__user-search-wrap").length){$("#mfsd_user_results").empty().hide();}});';
    $js .= '});';
    return $js;
}


// ─── AJAX: USER SEARCH ───────────────────────────────────────────────────────

add_action( 'wp_ajax_mfsd_ticker_user_search', 'mfsd_ticker_ajax_user_search' );
function mfsd_ticker_ajax_user_search(): void {
    check_ajax_referer( 'mfsd_ticker_admin_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [], 403 );
    }

    $q = sanitize_text_field( $_POST['q'] ?? '' );
    if ( strlen( $q ) < 2 ) {
        wp_send_json_success( [] );
    }

    $users = get_users( [
        'search'         => '*' . $q . '*',
        'search_columns' => [ 'user_login', 'user_email', 'display_name', 'user_nicename' ],
        'number'         => 10,
        'fields'         => [ 'ID', 'display_name', 'user_email', 'user_login' ],
    ] );

    $results = array_map( fn( $u ) => [
        'id'    => (int) $u->ID,
        'label' => sprintf( '%s (%s)', $u->display_name, $u->user_email ),
    ], $users );

    wp_send_json_success( $results );
}


// ─── FORM HANDLING ───────────────────────────────────────────────────────────

add_action( 'admin_post_mfsd_ticker_save', 'mfsd_ticker_handle_save' );
function mfsd_ticker_handle_save(): void {
    check_admin_referer( 'mfsd_ticker_save_message' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Unauthorised', 'mfsd-ticker-tape' ) );
    }

    $id           = isset( $_POST['message_id'] ) ? (int) $_POST['message_id'] : 0;
    $message      = sanitize_textarea_field( $_POST['message'] ?? '' );
    $message_type = in_array( $_POST['message_type'] ?? '', [ 'standard', 'course_enrolment', 'user_specific', 'rss_feed' ], true )
                    ? $_POST['message_type']
                    : 'standard';
    $course_id      = (int) ( $_POST['course_id'] ?? 0 );
    $target_user_id = (int) ( $_POST['target_user_id'] ?? 0 );
    $feed_url       = esc_url_raw( $_POST['feed_url'] ?? '' );
    $feed_limit     = max( 1, min( 20, (int) ( $_POST['feed_limit'] ?? 5 ) ) );
    $feed_prefix    = sanitize_text_field( $_POST['feed_prefix'] ?? '' );
    $roles_raw      = $_POST['roles'] ?? [];
    $roles          = is_array( $roles_raw )
                    ? array_map( 'sanitize_key', $roles_raw )
                    : [ 'all' ];
    $active         = isset( $_POST['active'] ) ? 1 : 0;
    $sort_order     = (int) ( $_POST['sort_order'] ?? 0 );

    if ( empty( $message ) ) {
        wp_redirect( add_query_arg( [ 'page' => 'mfsd-ticker-tape', 'error' => 'empty' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    if ( empty( $roles ) ) {
        $roles = [ 'all' ];
    }

    // For user-specific messages, reset role targeting.
    if ( $message_type === 'user_specific' ) {
        $roles     = [ 'all' ];
        $course_id = 0;
        if ( $target_user_id < 1 ) {
            wp_redirect( add_query_arg( [ 'page' => 'mfsd-ticker-tape', 'error' => 'no_user' ], admin_url( 'admin.php' ) ) );
            exit;
        }
    }

    // For course enrolment messages, reset user targeting.
    if ( $message_type === 'course_enrolment' ) {
        $target_user_id = 0;
        if ( $course_id < 1 ) {
            wp_redirect( add_query_arg( [ 'page' => 'mfsd-ticker-tape', 'error' => 'no_course' ], admin_url( 'admin.php' ) ) );
            exit;
        }
    }

    // For RSS feed messages, message text is used as a fallback label only.
    if ( $message_type === 'rss_feed' ) {
        $course_id      = 0;
        $target_user_id = 0;
        if ( empty( $feed_url ) ) {
            wp_redirect( add_query_arg( [ 'page' => 'mfsd-ticker-tape', 'error' => 'no_feed' ], admin_url( 'admin.php' ) ) );
            exit;
        }
    } else {
        // Non-RSS types: clear feed fields.
        $feed_url    = '';
        $feed_limit  = 5;
        $feed_prefix = '';
    }

    if ( $id > 0 ) {
        mfsd_ticker_update_message( $id, $message, $roles, $active, $sort_order, $message_type, $course_id, $target_user_id, $feed_url, $feed_limit, $feed_prefix );
        $redirect_msg = 'updated';
    } else {
        mfsd_ticker_insert_message( $message, $roles, $active, $sort_order, $message_type, $course_id, $target_user_id, $feed_url, $feed_limit, $feed_prefix );
        $redirect_msg = 'added';
    }

    wp_redirect( add_query_arg( [ 'page' => 'mfsd-ticker-tape', 'msg' => $redirect_msg ], admin_url( 'admin.php' ) ) );
    exit;
}

add_action( 'admin_post_mfsd_ticker_delete', 'mfsd_ticker_handle_delete' );
function mfsd_ticker_handle_delete(): void {
    check_admin_referer( 'mfsd_ticker_delete_message' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Unauthorised', 'mfsd-ticker-tape' ) );
    }

    $id = (int) ( $_GET['id'] ?? 0 );
    if ( $id > 0 ) {
        mfsd_ticker_delete_message( $id );
    }

    wp_redirect( add_query_arg( [ 'page' => 'mfsd-ticker-tape', 'msg' => 'deleted' ], admin_url( 'admin.php' ) ) );
    exit;
}

add_action( 'admin_post_mfsd_ticker_toggle', 'mfsd_ticker_handle_toggle' );
function mfsd_ticker_handle_toggle(): void {
    check_admin_referer( 'mfsd_ticker_toggle_message' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Unauthorised', 'mfsd-ticker-tape' ) );
    }

    $id = (int) ( $_GET['id'] ?? 0 );
    if ( $id > 0 ) {
        mfsd_ticker_toggle_active( $id );
    }

    wp_redirect( add_query_arg( [ 'page' => 'mfsd-ticker-tape', 'msg' => 'toggled' ], admin_url( 'admin.php' ) ) );
    exit;
}

add_action( 'admin_post_mfsd_ticker_clear_rss', 'mfsd_ticker_handle_clear_rss' );
function mfsd_ticker_handle_clear_rss(): void {
    check_admin_referer( 'mfsd_ticker_clear_rss' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Unauthorised', 'mfsd-ticker-tape' ) );
    }

    $id  = (int) ( $_GET['id'] ?? 0 );
    $msg = $id > 0 ? mfsd_ticker_get_message( $id ) : null;

    if ( $msg && ! empty( $msg['feed_url'] ) ) {
        mfsd_ticker_clear_rss_cache( $msg['feed_url'], (int) ( $msg['feed_limit'] ?? 5 ) );
    }

    wp_redirect( add_query_arg( [ 'page' => 'mfsd-ticker-tape', 'msg' => 'feed_cleared' ], admin_url( 'admin.php' ) ) );
    exit;
}


// ─── HELPERS ─────────────────────────────────────────────────────────────────

function mfsd_ticker_available_roles(): array {
    return [
        'all'           => __( 'Everyone (all roles)', 'mfsd-ticker-tape' ),
        'student'       => __( 'Student', 'mfsd-ticker-tape' ),
        'parent'        => __( 'Parent', 'mfsd-ticker-tape' ),
        'teacher'       => __( 'Teacher', 'mfsd-ticker-tape' ),
        'administrator' => __( 'Administrator', 'mfsd-ticker-tape' ),
    ];
}

/**
 * Return published courses for the course dropdown.
 * Supports LearnDash (sfwd-courses) and a generic 'course' post type.
 *
 * @return WP_Post[]
 */
function mfsd_ticker_get_courses(): array {
    $types = [];
    if ( post_type_exists( 'sfwd-courses' ) ) $types[] = 'sfwd-courses';
    if ( post_type_exists( 'course' ) )       $types[] = 'course';
    if ( empty( $types ) )                    return [];

    return get_posts( [
        'post_type'      => $types,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ] );
}

/**
 * Human-readable label for a message type.
 */
function mfsd_ticker_type_label( string $type ): string {
    return match ( $type ) {
        'course_enrolment' => __( 'Course Enrolment', 'mfsd-ticker-tape' ),
        'user_specific'    => __( 'User Specific', 'mfsd-ticker-tape' ),
        'rss_feed'         => __( 'RSS Feed', 'mfsd-ticker-tape' ),
        default            => __( 'Standard', 'mfsd-ticker-tape' ),
    };
}


// ─── ADMIN PAGE RENDER ────────────────────────────────────────────────────────

function mfsd_ticker_render_admin_page(): void {
    $messages  = mfsd_ticker_get_all_messages();
    $edit_id   = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
    $edit_msg  = $edit_id ? mfsd_ticker_get_message( $edit_id ) : null;
    $all_roles = mfsd_ticker_available_roles();
    $courses   = mfsd_ticker_get_courses();
    $tokens    = mfsd_ticker_available_tokens();

    // Pass nonce to inline JS.
    $nonce = wp_create_nonce( 'mfsd_ticker_admin_nonce' );
    echo "<script>var mfsdTicker = { nonce: '" . esc_js( $nonce ) . "' };</script>";

    // Status notices.
    $notice = '';
    if ( isset( $_GET['msg'] ) ) {
        $notices = [
            'added'   => [ 'success', __( 'Message added.', 'mfsd-ticker-tape' ) ],
            'updated' => [ 'success', __( 'Message updated.', 'mfsd-ticker-tape' ) ],
            'deleted' => [ 'success', __( 'Message deleted.', 'mfsd-ticker-tape' ) ],
            'toggled'      => [ 'success', __( 'Message status toggled.', 'mfsd-ticker-tape' ) ],
            'feed_cleared' => [ 'success', __( 'RSS cache cleared — fresh headlines will load on next page view.', 'mfsd-ticker-tape' ) ],
        ];
        $key = sanitize_key( $_GET['msg'] );
        if ( isset( $notices[ $key ] ) ) $notice = $notices[ $key ];
    }
    $error_map = [
        'empty'     => __( 'Message text cannot be empty.', 'mfsd-ticker-tape' ),
        'no_user'   => __( 'Please select a user for a user-specific message.', 'mfsd-ticker-tape' ),
        'no_course' => __( 'Please select a course for a course enrolment message.', 'mfsd-ticker-tape' ),
        'no_feed'   => __( 'Please enter a feed URL for an RSS feed message.', 'mfsd-ticker-tape' ),
    ];
    if ( isset( $_GET['error'] ) ) {
        $ekey = sanitize_key( $_GET['error'] );
        if ( isset( $error_map[ $ekey ] ) ) $notice = [ 'error', $error_map[ $ekey ] ];
    }

    // Form defaults (editing or blank).
    $form = [
        'id'             => $edit_id,
        'message'        => $edit_msg['message']              ?? '',
        'roles'          => $edit_msg ? json_decode( $edit_msg['roles'], true ) : [ 'all' ],
        'message_type'   => $edit_msg['message_type']         ?? 'standard',
        'course_id'      => (int) ( $edit_msg['course_id']    ?? 0 ),
        'target_user_id' => (int) ( $edit_msg['target_user_id'] ?? 0 ),
        'feed_url'       => $edit_msg['feed_url']             ?? '',
        'feed_limit'     => (int) ( $edit_msg['feed_limit']   ?? 5 ),
        'feed_prefix'    => $edit_msg['feed_prefix']          ?? '',
        'active'         => $edit_msg ? (int) $edit_msg['active'] : 1,
        'sort_order'     => (int) ( $edit_msg['sort_order']   ?? 0 ),
    ];

    // Pre-populate user display name for editing.
    $target_user_label = '';
    if ( $form['target_user_id'] > 0 ) {
        $tu = get_user_by( 'id', $form['target_user_id'] );
        if ( $tu ) {
            $target_user_label = sprintf( '%s (%s)', $tu->display_name, $tu->user_email );
        }
    }
    ?>

    <div class="wrap mfsd-ticker-admin" id="mfsd-ticker-admin">

      <h1 class="mfsd-ticker-admin__title">
        <span class="dashicons dashicons-megaphone"></span>
        <?php esc_html_e( 'MFSD Ticker Tape', 'mfsd-ticker-tape' ); ?>
      </h1>

      <?php if ( $notice ) : ?>
        <div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible">
          <p><?php echo esc_html( $notice[1] ); ?></p>
        </div>
      <?php endif; ?>

      <div class="mfsd-ticker-admin__layout">

        <?php /* ── TOP: Message list ────────────────────────────────────── */ ?>
        <div class="mfsd-ticker-admin__list-col">

          <h2><?php esc_html_e( 'All Messages', 'mfsd-ticker-tape' ); ?></h2>

          <?php if ( empty( $messages ) ) : ?>
            <p><?php esc_html_e( 'No messages yet. Add one using the form.', 'mfsd-ticker-tape' ); ?></p>
          <?php else : ?>

            <table class="widefat mfsd-ticker-admin__table">
              <thead>
                <tr>
                  <th><?php esc_html_e( 'Order', 'mfsd-ticker-tape' ); ?></th>
                  <th><?php esc_html_e( 'Message', 'mfsd-ticker-tape' ); ?></th>
                  <th><?php esc_html_e( 'Type / Target', 'mfsd-ticker-tape' ); ?></th>
                  <th><?php esc_html_e( 'Status', 'mfsd-ticker-tape' ); ?></th>
                  <th><?php esc_html_e( 'Actions', 'mfsd-ticker-tape' ); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ( $messages as $msg ) :
                    $msg_roles    = json_decode( $msg['roles'], true ) ?: [ 'all' ];
                    $role_labels  = array_map( fn( $r ) => $all_roles[ $r ] ?? $r, $msg_roles );
                    $is_active    = (int) $msg['active'] === 1;
                    $mtype        = $msg['message_type'] ?? 'standard';
                    $tuid         = (int) ( $msg['target_user_id'] ?? 0 );
                    $mcid         = (int) ( $msg['course_id'] ?? 0 );

                    // Build a readable target string.
                    if ( $mtype === 'user_specific' && $tuid > 0 ) {
                        $tu = get_user_by( 'id', $tuid );
                        $target_display = $tu
                            ? sprintf( '👤 %s', esc_html( $tu->display_name ) )
                            : sprintf( '👤 User #%d', $tuid );
                    } elseif ( $mtype === 'course_enrolment' && $mcid > 0 ) {
                        $cp = get_post( $mcid );
                        $target_display = $cp
                            ? sprintf( '🎓 %s', esc_html( $cp->post_title ) )
                            : sprintf( '🎓 Course #%d', $mcid );
                    } elseif ( $mtype === 'rss_feed' ) {
                        $feed_url_display = $msg['feed_url'] ?? '';
                        $target_display   = $feed_url_display
                            ? sprintf( '📡 %s (max %d)', esc_html( parse_url( $feed_url_display, PHP_URL_HOST ) ?: $feed_url_display ), (int) ( $msg['feed_limit'] ?? 5 ) )
                            : '📡 No URL set';
                    } else {
                        $target_display = implode( ', ', $role_labels );
                    }
                ?>
                  <tr class="<?php echo $is_active ? 'mfsd-ticker-admin__row--active' : 'mfsd-ticker-admin__row--paused'; ?>">
                    <td class="mfsd-ticker-admin__order"><?php echo esc_html( $msg['sort_order'] ); ?></td>
                    <td class="mfsd-ticker-admin__message-text">
                      <?php echo esc_html( $msg['message'] ); ?>
                    </td>
                    <td>
                      <span class="mfsd-ticker-admin__type-badge mfsd-ticker-admin__type-badge--<?php echo esc_attr( $mtype ); ?>">
                        <?php echo esc_html( mfsd_ticker_type_label( $mtype ) ); ?>
                      </span><br>
                      <small class="mfsd-ticker-admin__target-info"><?php echo $target_display; ?></small>
                    </td>
                    <td>
                      <span class="mfsd-ticker-admin__status mfsd-ticker-admin__status--<?php echo $is_active ? 'live' : 'paused'; ?>">
                        <?php echo $is_active ? esc_html__( 'Live', 'mfsd-ticker-tape' ) : esc_html__( 'Paused', 'mfsd-ticker-tape' ); ?>
                      </span>
                    </td>
                    <td class="mfsd-ticker-admin__actions">
                      <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'mfsd-ticker-tape', 'edit' => $msg['id'] ], admin_url( 'admin.php' ) ) ); ?>"
                         class="button button-small">
                        <?php esc_html_e( 'Edit', 'mfsd-ticker-tape' ); ?>
                      </a>
                      <a href="<?php echo esc_url( wp_nonce_url(
                          add_query_arg( [ 'action' => 'mfsd_ticker_toggle', 'id' => $msg['id'] ], admin_url( 'admin-post.php' ) ),
                          'mfsd_ticker_toggle_message'
                      ) ); ?>" class="button button-small">
                        <?php echo $is_active ? esc_html__( 'Pause', 'mfsd-ticker-tape' ) : esc_html__( 'Activate', 'mfsd-ticker-tape' ); ?>
                      </a>
                      <a href="<?php echo esc_url( wp_nonce_url(
                          add_query_arg( [ 'action' => 'mfsd_ticker_delete', 'id' => $msg['id'] ], admin_url( 'admin-post.php' ) ),
                          'mfsd_ticker_delete_message'
                      ) ); ?>"
                         class="button button-small mfsd-ticker-admin__btn-delete"
                         onclick="return confirm('<?php esc_attr_e( 'Delete this message? This cannot be undone.', 'mfsd-ticker-tape' ); ?>')">
                        <?php esc_html_e( 'Delete', 'mfsd-ticker-tape' ); ?>
                      </a>
                      <?php if ( $mtype === 'rss_feed' && ! empty( $msg['feed_url'] ) ) : ?>
                      <a href="<?php echo esc_url( wp_nonce_url(
                          add_query_arg( [ 'action' => 'mfsd_ticker_clear_rss', 'id' => $msg['id'] ], admin_url( 'admin-post.php' ) ),
                          'mfsd_ticker_clear_rss'
                      ) ); ?>" class="button button-small mfsd-ticker-admin__btn-refresh"
                         title="<?php esc_attr_e( 'Clear cached headlines and fetch fresh ones', 'mfsd-ticker-tape' ); ?>">
                        <?php esc_html_e( '↺ Refresh Feed', 'mfsd-ticker-tape' ); ?>
                      </a>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>

          <?php endif; ?>

        </div><?php /* end list col */ ?>

        <?php /* ── BELOW: Add / Edit form (full width) ────────────────── */ ?>
        <div class="mfsd-ticker-admin__form-col">

          <h2>
            <?php echo $edit_id
              ? esc_html__( 'Edit Message', 'mfsd-ticker-tape' )
              : esc_html__( 'Add New Message', 'mfsd-ticker-tape' );
            ?>
          </h2>

          <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'mfsd_ticker_save_message' ); ?>
            <input type="hidden" name="action"     value="mfsd_ticker_save">
            <input type="hidden" name="message_id" value="<?php echo esc_attr( $form['id'] ); ?>">

            <?php /* ── Message Type ── */ ?>
            <div class="mfsd-ticker-admin__field">
              <label><?php esc_html_e( 'Message Type', 'mfsd-ticker-tape' ); ?></label>
              <div class="mfsd-ticker-admin__type-radios">
                <?php
                $types = [
                    'standard'         => __( '📢 Standard — show by role', 'mfsd-ticker-tape' ),
                    'course_enrolment' => __( '🎓 Course Enrolment — show to enrolled users', 'mfsd-ticker-tape' ),
                    'user_specific'    => __( '👤 User Specific — show to one user only', 'mfsd-ticker-tape' ),
                    'rss_feed'         => __( '📡 RSS Feed — pull headlines from a feed', 'mfsd-ticker-tape' ),
                ];
                foreach ( $types as $tval => $tlabel ) : ?>
                  <label class="mfsd-ticker-admin__role-check">
                    <input type="radio"
                           name="message_type"
                           value="<?php echo esc_attr( $tval ); ?>"
                           <?php checked( $form['message_type'], $tval ); ?>>
                    <?php echo esc_html( $tlabel ); ?>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>

            <?php /* ── Message Text ── */ ?>
            <div class="mfsd-ticker-admin__field">
              <label for="mfsd_ticker_message">
                <?php esc_html_e( 'Message Text', 'mfsd-ticker-tape' ); ?>
                <span class="required mfsd-ticker-admin__msg-required">*</span>
              </label>
              <textarea id="mfsd_ticker_message"
                        name="message"
                        rows="4"
                        maxlength="500"><?php echo esc_textarea( $form['message'] ); ?></textarea>
              <p class="description mfsd-ticker-admin__msg-desc-standard">
                <?php esc_html_e( 'You can use emoji for visual flair, e.g. 🚀 🔥 🎮', 'mfsd-ticker-tape' ); ?>
              </p>
              <p class="description mfsd-ticker-admin__msg-desc-rss" style="display:none;">
                <?php esc_html_e( 'Optional — used as a fallback label if the RSS feed cannot be reached.', 'mfsd-ticker-tape' ); ?>
              </p>
            </div>

            <?php /* ── Token helper ── */ ?>
            <div class="mfsd-ticker-admin__field mfsd-ticker-admin__token-panel">
              <label><?php esc_html_e( 'Personalisation Tokens', 'mfsd-ticker-tape' ); ?></label>
              <p class="description" style="margin-bottom:8px;">
                <?php esc_html_e( 'Click a token to insert it at the cursor position in the message.', 'mfsd-ticker-tape' ); ?>
              </p>
              <div class="mfsd-ticker-admin__token-buttons">
                <?php foreach ( $tokens as $token => $desc ) : ?>
                  <button type="button"
                          class="button button-small mfsd-token-btn"
                          data-token="<?php echo esc_attr( $token ); ?>"
                          title="<?php echo esc_attr( $desc ); ?>">
                    <?php echo esc_html( $token ); ?>
                  </button>
                <?php endforeach; ?>
              </div>
              <table class="mfsd-ticker-admin__token-table">
                <?php foreach ( $tokens as $token => $desc ) : ?>
                  <tr>
                    <td><code><?php echo esc_html( $token ); ?></code></td>
                    <td><?php echo esc_html( $desc ); ?></td>
                  </tr>
                <?php endforeach; ?>
              </table>
            </div>

            <?php /* ── Roles (standard only) ── */ ?>
            <div class="mfsd-ticker-admin__field mfsd-ticker-admin__section--roles">
              <label><?php esc_html_e( 'Show to', 'mfsd-ticker-tape' ); ?></label>
              <div class="mfsd-ticker-admin__roles">
                <?php foreach ( $all_roles as $slug => $label ) : ?>
                  <label class="mfsd-ticker-admin__role-check">
                    <input type="checkbox"
                           name="roles[]"
                           value="<?php echo esc_attr( $slug ); ?>"
                           <?php checked( in_array( $slug, (array) $form['roles'], true ) ); ?>>
                    <?php echo esc_html( $label ); ?>
                  </label>
                <?php endforeach; ?>
              </div>
              <p class="description">
                <?php esc_html_e( 'Tick "Everyone" to show to all roles.', 'mfsd-ticker-tape' ); ?>
              </p>
            </div>

            <?php /* ── Course picker (course_enrolment only) ── */ ?>
            <div class="mfsd-ticker-admin__field mfsd-ticker-admin__section--course">
              <label for="mfsd_course_id">
                <?php esc_html_e( 'Course', 'mfsd-ticker-tape' ); ?>
              </label>
              <?php if ( $courses ) : ?>
                <select name="course_id" id="mfsd_course_id">
                  <option value="0"><?php esc_html_e( '— Select a course —', 'mfsd-ticker-tape' ); ?></option>
                  <?php foreach ( $courses as $course ) : ?>
                    <option value="<?php echo esc_attr( $course->ID ); ?>"
                            <?php selected( $form['course_id'], $course->ID ); ?>>
                      <?php echo esc_html( $course->post_title ); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <p class="description">
                  <?php esc_html_e( 'Message is shown only to users enrolled in this course. Use {course_name} token in the message text.', 'mfsd-ticker-tape' ); ?>
                </p>
              <?php else : ?>
                <p class="description" style="color:#b32d2e;">
                  <?php esc_html_e( 'No published courses found. Install LearnDash or another supported LMS and publish a course.', 'mfsd-ticker-tape' ); ?>
                </p>
                <input type="hidden" name="course_id" value="0">
              <?php endif; ?>
            </div>

            <?php /* ── User search (user_specific only) ── */ ?>
            <div class="mfsd-ticker-admin__field mfsd-ticker-admin__section--user">
              <label for="mfsd_user_search">
                <?php esc_html_e( 'Target User', 'mfsd-ticker-tape' ); ?>
              </label>
              <input type="hidden" name="target_user_id" id="mfsd_target_user_id"
                     value="<?php echo esc_attr( $form['target_user_id'] ); ?>">
              <div class="mfsd-ticker-admin__user-search-wrap">
                <input type="text"
                       id="mfsd_user_search"
                       class="regular-text"
                       placeholder="<?php esc_attr_e( 'Search by name, username or email…', 'mfsd-ticker-tape' ); ?>"
                       autocomplete="off"
                       value="<?php echo esc_attr( $target_user_label ); ?>">
                <ul id="mfsd_user_results" class="mfsd-ticker-admin__user-results" style="display:none;"></ul>
              </div>
              <?php if ( $form['target_user_id'] > 0 ) : ?>
                <button type="button" id="mfsd_clear_user" class="button button-small" style="margin-top:6px;">
                  <?php esc_html_e( '✕ Clear user', 'mfsd-ticker-tape' ); ?>
                </button>
              <?php endif; ?>
              <p class="description">
                <?php esc_html_e( 'Message will only appear in the ticker for this specific user.', 'mfsd-ticker-tape' ); ?>
              </p>
            </div>

            <?php /* ── RSS Feed fields (rss_feed only) ── */ ?>
            <div class="mfsd-ticker-admin__field mfsd-ticker-admin__section--rss">

              <label for="mfsd_feed_url">
                <?php esc_html_e( 'Feed URL', 'mfsd-ticker-tape' ); ?>
                <span class="required">*</span>
              </label>
              <input type="url"
                     id="mfsd_feed_url"
                     name="feed_url"
                     class="large-text"
                     placeholder="https://feeds.bbci.co.uk/news/rss.xml"
                     value="<?php echo esc_attr( $form['feed_url'] ); ?>">
              <p class="description">
                <?php esc_html_e( 'Full URL of the RSS or Atom feed. Headlines are cached for 30 minutes.', 'mfsd-ticker-tape' ); ?>
              </p>

              <div class="mfsd-ticker-admin__rss-options">

                <div class="mfsd-ticker-admin__rss-option">
                  <label for="mfsd_feed_limit">
                    <?php esc_html_e( 'Max Headlines', 'mfsd-ticker-tape' ); ?>
                  </label>
                  <input type="number"
                         id="mfsd_feed_limit"
                         name="feed_limit"
                         value="<?php echo esc_attr( $form['feed_limit'] ); ?>"
                         min="1"
                         max="20"
                         style="width:70px;">
                  <p class="description"><?php esc_html_e( 'How many headlines to pull from the feed (1–20).', 'mfsd-ticker-tape' ); ?></p>
                </div>

                <div class="mfsd-ticker-admin__rss-option">
                  <label for="mfsd_feed_prefix">
                    <?php esc_html_e( 'Headline Prefix', 'mfsd-ticker-tape' ); ?>
                  </label>
                  <input type="text"
                         id="mfsd_feed_prefix"
                         name="feed_prefix"
                         class="regular-text"
                         placeholder="<?php esc_attr_e( 'e.g. 📰 BBC News:', 'mfsd-ticker-tape' ); ?>"
                         maxlength="100"
                         value="<?php echo esc_attr( $form['feed_prefix'] ); ?>">
                  <p class="description"><?php esc_html_e( 'Optional label prepended to each headline.', 'mfsd-ticker-tape' ); ?></p>
                </div>

              </div>

            </div>

            <?php /* ── Sort order ── */ ?>
            <div class="mfsd-ticker-admin__field mfsd-ticker-admin__field--inline">
              <label for="mfsd_ticker_order">
                <?php esc_html_e( 'Display Order', 'mfsd-ticker-tape' ); ?>
              </label>
              <input type="number"
                     id="mfsd_ticker_order"
                     name="sort_order"
                     value="<?php echo esc_attr( $form['sort_order'] ); ?>"
                     min="0"
                     max="999"
                     style="width:80px;">
              <p class="description"><?php esc_html_e( 'Lower numbers appear first.', 'mfsd-ticker-tape' ); ?></p>
            </div>

            <?php /* ── Active toggle ── */ ?>
            <div class="mfsd-ticker-admin__field">
              <label class="mfsd-ticker-admin__role-check">
                <input type="checkbox"
                       name="active"
                       value="1"
                       <?php checked( $form['active'], 1 ); ?>>
                <?php esc_html_e( 'Active (live on site)', 'mfsd-ticker-tape' ); ?>
              </label>
            </div>

            <div class="mfsd-ticker-admin__submit">
              <button type="submit" class="button button-primary">
                <?php echo $edit_id
                  ? esc_html__( 'Update Message', 'mfsd-ticker-tape' )
                  : esc_html__( 'Add Message', 'mfsd-ticker-tape' );
                ?>
              </button>
              <?php if ( $edit_id ) : ?>
                <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'mfsd-ticker-tape' ], admin_url( 'admin.php' ) ) ); ?>"
                   class="button">
                  <?php esc_html_e( 'Cancel', 'mfsd-ticker-tape' ); ?>
                </a>
              <?php endif; ?>
            </div>

          </form>

        </div><?php /* end form col */ ?>

      </div><?php /* end layout */ ?>

      <?php /* ── Preview bar ── */ ?>
      <div class="mfsd-ticker-admin__preview-wrap">
        <h2><?php esc_html_e( 'Live Preview', 'mfsd-ticker-tape' ); ?></h2>
        <p class="description">
          <?php esc_html_e( 'Shows all active messages. Tokens display as placeholders in the preview.', 'mfsd-ticker-tape' ); ?>
        </p>
        <div class="mfsd-ticker-admin__preview">
          <div class="mfsd-ticker-admin__preview-label">
            <?php esc_html_e( 'WHATS NEW AT MFS!', 'mfsd-ticker-tape' ); ?>
          </div>
          <div class="mfsd-ticker-admin__preview-track">
            <span class="mfsd-ticker-admin__preview-content">
              <?php
              $active_messages = array_filter( $messages, fn( $m ) => (int) $m['active'] === 1 );
              if ( $active_messages ) {
                  $texts = array_map( fn( $m ) => esc_html( $m['message'] ), $active_messages );
                  echo implode( ' &nbsp;&nbsp;•&nbsp;&nbsp; ', $texts );
              } else {
                  esc_html_e( 'No active messages — add one above.', 'mfsd-ticker-tape' );
              }
              ?>
            </span>
          </div>
        </div>
      </div>

    </div><?php /* end wrap */ ?>
    <?php
}