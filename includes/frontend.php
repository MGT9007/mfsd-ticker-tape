<?php
/**
 * MFSD Ticker Tape — Frontend
 *
 * Hooks into do_action('mfsd_ticker_tape_bar') which is called from
 * the theme's header.php inside .mfsd-ticker-zone.
 *
 * The theme provides the outer structural wrapper and CSS variables.
 * This plugin renders the full .mfsd-ticker element inside that zone.
 */

defined( 'ABSPATH' ) || exit;


// ─── HOOK INTO THEME ─────────────────────────────────────────────────────────

add_action( 'mfsd_ticker_tape_bar', 'mfsd_ticker_render_frontend' );

function mfsd_ticker_render_frontend(): void {

    // Only render for logged-in users.
    if ( ! is_user_logged_in() ) {
        return;
    }

    // Get the current user's MFSD role.
    // Uses the theme helper if available, falls back to WordPress native.
    if ( function_exists( 'mfsd_get_user_role' ) ) {
        $role = mfsd_get_user_role();
    } else {
        $user  = wp_get_current_user();
        $roles = (array) $user->roles;
        if ( in_array( 'administrator', $roles, true ) ) $role = 'admin';
        elseif ( in_array( 'teacher', $roles, true ) )   $role = 'teacher';
        elseif ( in_array( 'parent', $roles, true ) )    $role = 'parent';
        elseif ( in_array( 'student', $roles, true ) )   $role = 'student';
        else                                              $role = 'parent';
    }

    // Get messages for this role.
    $messages = mfsd_ticker_get_messages_for_role( $role );

    // If no messages, render nothing — the theme's :empty CSS hides the zone.
    if ( empty( $messages ) ) {
        return;
    }

    // Build the scrolling text — all messages joined with a separator.
    $separator = ' &nbsp;&nbsp;&bull;&nbsp;&nbsp; ';
    $texts     = array_map(
        fn( $m ) => esc_html( $m['message'] ),
        array_values( $messages )
    );
    $scroll_content = implode( $separator, $texts );

    // Animation speed: base 30s + 2s per message so longer lists scroll slower.
    $speed = 30 + ( count( $messages ) * 2 );

    // Icon — differs between gamer and corporate (theme body class handles
    // colour but we give the gamer theme a sparkle icon).
    $is_student = ( $role === 'student' );
    $icon       = $is_student ? '⚡' : '★';
    ?>

    <div class="mfsd-ticker" role="marquee" aria-label="<?php esc_attr_e( 'Latest news and updates', 'mfsd-ticker-tape' ); ?>">

      <div class="mfsd-ticker__label" aria-hidden="true">
        <span class="mfsd-ticker__label-icon"><?php echo $icon; ?></span>
        <?php esc_html_e( 'WHATS NEW AT MFS!', 'mfsd-ticker-tape' ); ?>
        <span class="mfsd-ticker__label-icon"><?php echo $icon; ?></span>
      </div>

      <div class="mfsd-ticker__track">
        <span class="mfsd-ticker__content"
              style="animation-duration: <?php echo esc_attr( $speed ); ?>s;"
              aria-live="off">
          <?php echo $scroll_content; ?>
          <?php /* Duplicate content so the scroll loops seamlessly */ ?>
          <?php echo $separator . $scroll_content; ?>
        </span>
      </div>

    </div>

    <?php
}


// ─── FRONTEND ASSETS ─────────────────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', 'mfsd_ticker_frontend_assets' );
function mfsd_ticker_frontend_assets(): void {

    if ( ! is_user_logged_in() ) return;

    wp_enqueue_style(
        'mfsd-ticker-frontend',
        MFSD_TICKER_URI . 'assets/css/frontend.css',
        [ 'mfsd-base' ],  // Depends on theme base CSS for variables.
        MFSD_TICKER_VERSION
    );
}
