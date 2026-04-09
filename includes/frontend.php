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

    // Only display on the home page.
    if ( ! is_front_page() ) {
        return;
    }

    $user = wp_get_current_user();

    // Get the current user's MFSD role.
    // Uses the theme helper if available, falls back to WordPress native.
    if ( function_exists( 'mfsd_get_user_role' ) ) {
        $role = mfsd_get_user_role();
    } else {
        $roles = (array) $user->roles;
        if ( in_array( 'administrator', $roles, true ) )     $role = 'admin';
        elseif ( in_array( 'teacher', $roles, true ) )       $role = 'teacher';
        elseif ( in_array( 'parent', $roles, true ) )        $role = 'parent';
        elseif ( in_array( 'student', $roles, true ) )       $role = 'student';
        else                                                  $role = 'parent';
    }

    // Get messages for this user (role + course enrolment + user-specific).
    $messages = mfsd_ticker_get_messages_for_user( $role, $user );

    // If no messages, render nothing — the theme's :empty CSS hides the zone.
    if ( empty( $messages ) ) {
        return;
    }

    // Build the scrolling text — resolve tokens per message then join.
    $separator = ' &nbsp;&nbsp;&bull;&nbsp;&nbsp; ';
    $texts     = [];
    foreach ( array_values( $messages ) as $msg ) {
        $type      = $msg['message_type'] ?? 'standard';
        $course_id = (int) ( $msg['course_id'] ?? 0 );

        if ( $type === 'rss_feed' ) {
            // Expand RSS feed into individual headline items.
            $headlines = mfsd_ticker_fetch_rss_headlines(
                $msg['feed_url']    ?? '',
                (int) ( $msg['feed_limit']  ?? 5 ),
                $msg['feed_prefix'] ?? ''
            );
            foreach ( $headlines as $headline ) {
                $texts[] = esc_html( $headline );
            }
        } else {
            // Resolve personalisation tokens then output.
            $resolved = mfsd_ticker_resolve_tokens( $msg['message'], $user, $course_id );
            $texts[]  = $resolved;
        }
    }
    $scroll_content = implode( $separator, $texts );

    // Animation speed: base 30s + 4s per item in the tape.
    // We count actual text items (headlines expanded) not just DB rows.
    $speed = 30 + ( count( $texts ) * 4 );

    // Icon — differs between gamer and corporate themes.
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