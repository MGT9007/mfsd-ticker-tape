<?php
/**
 * MFSD Ticker Tape — Admin Screen
 *
 * Adds a submenu under the MFSD admin group (or top-level if not present).
 * Admin can:
 *   - View all ticker messages with status, roles, and order
 *   - Add new messages
 *   - Edit existing messages
 *   - Toggle active/paused
 *   - Delete messages
 *   - Set display order
 */

defined( 'ABSPATH' ) || exit;


// ─── MENU REGISTRATION ───────────────────────────────────────────────────────

add_action( 'admin_menu', 'mfsd_ticker_register_menu' );
function mfsd_ticker_register_menu(): void {

    // Try to attach under the MFSD top-level menu if it exists.
    // Otherwise create a standalone top-level menu.
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
            'mfsd-admin',  // Parent slug of the MFSD top-level menu.
            __( 'Ticker Tape', 'mfsd-ticker-tape' ),
            __( 'Ticker Tape', 'mfsd-ticker-tape' ),
            'manage_options',
            'mfsd-ticker-tape',
            'mfsd_ticker_render_admin_page'
        );
    }
}

/**
 * Check if the MFSD top-level admin menu exists.
 *
 * @return bool
 */
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
    if ( strpos( $hook, 'mfsd-ticker-tape' ) === false ) return;

    wp_enqueue_style(
        'mfsd-ticker-admin',
        MFSD_TICKER_URI . 'assets/css/admin.css',
        [],
        MFSD_TICKER_VERSION
    );
}


// ─── FORM HANDLING ───────────────────────────────────────────────────────────

add_action( 'admin_post_mfsd_ticker_save', 'mfsd_ticker_handle_save' );
function mfsd_ticker_handle_save(): void {
    check_admin_referer( 'mfsd_ticker_save_message' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Unauthorised', 'mfsd-ticker-tape' ) );
    }

    $id         = isset( $_POST['message_id'] ) ? (int) $_POST['message_id'] : 0;
    $message    = sanitize_textarea_field( $_POST['message'] ?? '' );
    $roles_raw  = $_POST['roles'] ?? [];
    $roles      = is_array( $roles_raw )
                ? array_map( 'sanitize_key', $roles_raw )
                : [ 'all' ];
    $active     = isset( $_POST['active'] ) ? 1 : 0;
    $sort_order = (int) ( $_POST['sort_order'] ?? 0 );

    if ( empty( $message ) ) {
        wp_redirect( add_query_arg( [ 'page' => 'mfsd-ticker-tape', 'error' => 'empty' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    if ( empty( $roles ) ) {
        $roles = [ 'all' ];
    }

    if ( $id > 0 ) {
        mfsd_ticker_update_message( $id, $message, $roles, $active, $sort_order );
        $redirect_msg = 'updated';
    } else {
        mfsd_ticker_insert_message( $message, $roles, $active, $sort_order );
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


// ─── ADMIN PAGE RENDER ────────────────────────────────────────────────────────

/**
 * All available roles in MFSD.
 *
 * @return array  slug => label
 */
function mfsd_ticker_available_roles(): array {
    return [
        'all'            => __( 'Everyone (all roles)', 'mfsd-ticker-tape' ),
        'student'        => __( 'Student', 'mfsd-ticker-tape' ),
        'parent'         => __( 'Parent', 'mfsd-ticker-tape' ),
        'teacher'        => __( 'Teacher', 'mfsd-ticker-tape' ),
        'administrator'  => __( 'Administrator', 'mfsd-ticker-tape' ),
    ];
}

function mfsd_ticker_render_admin_page(): void {
    $messages  = mfsd_ticker_get_all_messages();
    $edit_id   = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
    $edit_msg  = $edit_id ? mfsd_ticker_get_message( $edit_id ) : null;
    $all_roles = mfsd_ticker_available_roles();

    // Status notices.
    $notice = '';
    if ( isset( $_GET['msg'] ) ) {
        $notices = [
            'added'   => [ 'success', __( 'Message added successfully.', 'mfsd-ticker-tape' ) ],
            'updated' => [ 'success', __( 'Message updated successfully.', 'mfsd-ticker-tape' ) ],
            'deleted' => [ 'success', __( 'Message deleted.', 'mfsd-ticker-tape' ) ],
            'toggled' => [ 'success', __( 'Message status toggled.', 'mfsd-ticker-tape' ) ],
        ];
        $key = sanitize_key( $_GET['msg'] );
        if ( isset( $notices[ $key ] ) ) {
            $notice = $notices[ $key ];
        }
    }
    if ( isset( $_GET['error'] ) && $_GET['error'] === 'empty' ) {
        $notice = [ 'error', __( 'Message text cannot be empty.', 'mfsd-ticker-tape' ) ];
    }

    // Form values — editing or blank.
    $form = [
        'id'         => $edit_id,
        'message'    => $edit_msg['message']    ?? '',
        'roles'      => $edit_msg ? json_decode( $edit_msg['roles'], true ) : [ 'all' ],
        'active'     => $edit_msg ? (int) $edit_msg['active'] : 1,
        'sort_order' => $edit_msg ? (int) $edit_msg['sort_order'] : 0,
    ];
    ?>

    <div class="wrap mfsd-ticker-admin">

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

        <?php /* ── LEFT: Message list ──────────────────────────────────────── */ ?>
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
                  <th><?php esc_html_e( 'Roles', 'mfsd-ticker-tape' ); ?></th>
                  <th><?php esc_html_e( 'Status', 'mfsd-ticker-tape' ); ?></th>
                  <th><?php esc_html_e( 'Actions', 'mfsd-ticker-tape' ); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ( $messages as $msg ) :
                  $msg_roles  = json_decode( $msg['roles'], true ) ?: [ 'all' ];
                  $role_labels = array_map( fn( $r ) => $all_roles[ $r ] ?? $r, $msg_roles );
                  $is_active   = (int) $msg['active'] === 1;
                ?>
                  <tr class="<?php echo $is_active ? 'mfsd-ticker-admin__row--active' : 'mfsd-ticker-admin__row--paused'; ?>">
                    <td class="mfsd-ticker-admin__order"><?php echo esc_html( $msg['sort_order'] ); ?></td>
                    <td class="mfsd-ticker-admin__message-text"><?php echo esc_html( $msg['message'] ); ?></td>
                    <td>
                      <?php foreach ( $role_labels as $label ) : ?>
                        <span class="mfsd-ticker-admin__role-badge"><?php echo esc_html( $label ); ?></span>
                      <?php endforeach; ?>
                    </td>
                    <td>
                      <span class="mfsd-ticker-admin__status mfsd-ticker-admin__status--<?php echo $is_active ? 'live' : 'paused'; ?>">
                        <?php echo $is_active ? esc_html__( 'Live', 'mfsd-ticker-tape' ) : esc_html__( 'Paused', 'mfsd-ticker-tape' ); ?>
                      </span>
                    </td>
                    <td class="mfsd-ticker-admin__actions">

                      <?php /* Edit */ ?>
                      <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'mfsd-ticker-tape', 'edit' => $msg['id'] ], admin_url( 'admin.php' ) ) ); ?>"
                         class="button button-small">
                        <?php esc_html_e( 'Edit', 'mfsd-ticker-tape' ); ?>
                      </a>

                      <?php /* Toggle */ ?>
                      <a href="<?php echo esc_url( wp_nonce_url(
                          add_query_arg( [ 'action' => 'mfsd_ticker_toggle', 'id' => $msg['id'] ], admin_url( 'admin-post.php' ) ),
                          'mfsd_ticker_toggle_message'
                      ) ); ?>" class="button button-small">
                        <?php echo $is_active ? esc_html__( 'Pause', 'mfsd-ticker-tape' ) : esc_html__( 'Activate', 'mfsd-ticker-tape' ); ?>
                      </a>

                      <?php /* Delete */ ?>
                      <a href="<?php echo esc_url( wp_nonce_url(
                          add_query_arg( [ 'action' => 'mfsd_ticker_delete', 'id' => $msg['id'] ], admin_url( 'admin-post.php' ) ),
                          'mfsd_ticker_delete_message'
                      ) ); ?>"
                         class="button button-small mfsd-ticker-admin__btn-delete"
                         onclick="return confirm('<?php esc_attr_e( 'Delete this message? This cannot be undone.', 'mfsd-ticker-tape' ); ?>')">
                        <?php esc_html_e( 'Delete', 'mfsd-ticker-tape' ); ?>
                      </a>

                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>

          <?php endif; ?>

        </div><?php /* end list col */ ?>

        <?php /* ── RIGHT: Add / Edit form ──────────────────────────────────── */ ?>
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

            <?php /* Message text */ ?>
            <div class="mfsd-ticker-admin__field">
              <label for="mfsd_ticker_message">
                <?php esc_html_e( 'Message Text', 'mfsd-ticker-tape' ); ?>
                <span class="required">*</span>
              </label>
              <textarea id="mfsd_ticker_message"
                        name="message"
                        rows="3"
                        maxlength="500"
                        required><?php echo esc_textarea( $form['message'] ); ?></textarea>
              <p class="description">
                <?php esc_html_e( 'You can use emoji at the start for visual flair, e.g. 🚀 🔥 🎮', 'mfsd-ticker-tape' ); ?>
              </p>
            </div>

            <?php /* Role assignment */ ?>
            <div class="mfsd-ticker-admin__field">
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
                <?php esc_html_e( 'Tick "Everyone" to show to all roles regardless of other selections.', 'mfsd-ticker-tape' ); ?>
              </p>
            </div>

            <?php /* Sort order */ ?>
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
              <p class="description"><?php esc_html_e( 'Lower numbers appear first in the scroll.', 'mfsd-ticker-tape' ); ?></p>
            </div>

            <?php /* Active toggle */ ?>
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

      <?php /* Preview bar */ ?>
      <div class="mfsd-ticker-admin__preview-wrap">
        <h2><?php esc_html_e( 'Live Preview', 'mfsd-ticker-tape' ); ?></h2>
        <p class="description">
          <?php esc_html_e( 'This shows how active messages appear in the ticker tape on the front end.', 'mfsd-ticker-tape' ); ?>
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
