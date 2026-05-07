# MFSD Ticker Tape — Technical Specification v1.0

**Plugin directory:** `mfsd-ticker-tape/`
**Shortcode(s):** None — output injected via theme action hook `mfsd_ticker_tape_bar`
**Version:** 3.5.0
**Author:** MisterT9007
**Purpose:** Manages and displays a role-targeted scrolling ticker tape in the My Future Self Digital theme header. Admins create messages in a WordPress admin panel and assign them to one or more user roles (or all roles), individual users, enrolled courses, or external RSS feeds. The ticker renders only on the home/front page for logged-in users, resolving personalisation tokens at render time. The plugin hooks into the `mfsd_ticker_tape_bar` action registered in the myfutureself-theme; the theme's `:empty` CSS hides the ticker zone automatically when no messages are rendered.

---

## File Structure

| File | Purpose |
|------|---------|
| `mfsd-ticker-tape.php` | Bootstrap: defines constants, requires the three include files, registers activation/deactivation hooks |
| `includes/db.php` | Database schema creation, auto-upgrade logic, RSS caching helpers, token resolution, and all CRUD functions |
| `includes/admin.php` | Admin menu registration, admin asset enqueue, inline JS for dynamic form sections and AJAX user search, form POST handlers, admin page render function |
| `includes/frontend.php` | Theme action hook, message filtering and rendering, frontend CSS enqueue |
| `assets/css/admin.css` | Admin panel styles: layout, message table, type badges, token panel, user search dropdown, preview bar |
| `assets/css/frontend.css` | Frontend ticker styles: fluid sizing with `clamp()`, scroll animation, reduced-motion accessibility override |

---

## Database Schema

All tables created in `register_activation_hook` via `mfsd_ticker_create_table()`.

### wp_mfsd_ticker_messages

| Column | Type | Notes |
|--------|------|-------|
| `id` | INT UNSIGNED AUTO_INCREMENT | Primary key |
| `message` | TEXT NOT NULL | Message text. Supports tokens: `{first_name}`, `{display_name}`, `{username}`, `{course_name}` |
| `roles` | VARCHAR(500) | JSON array of role slugs, e.g. `["student","parent"]`. Use `["all"]` for everyone |
| `message_type` | VARCHAR(20) | `standard` \| `course_enrolment` \| `user_specific` \| `rss_feed` |
| `course_id` | BIGINT | WP post ID of course (used when `message_type = course_enrolment`; 0 otherwise) |
| `target_user_id` | BIGINT | WP user ID for user-specific messages (0 = no restriction) |
| `feed_url` | VARCHAR(1000) | Full RSS or Atom feed URL (used when `message_type = rss_feed`) |
| `feed_limit` | TINYINT | Max headlines to pull from the feed (1–20, default 5) |
| `feed_prefix` | VARCHAR(200) | Optional label prepended to each headline, e.g. `📰 BBC:` |
| `active` | TINYINT(1) | 1 = live, 0 = paused |
| `sort_order` | INT | Display order; lower numbers appear first |
| `created_at` | DATETIME | Auto-set on insert |
| `updated_at` | DATETIME | Auto-updated on every change |

**Indexes:** `idx_active_order (active, sort_order)`, `idx_target_user (target_user_id)`

**Schema version option:** `mfsd_ticker_db_version` (current: `2.2.0`). The `mfsd_ticker_maybe_upgrade()` function runs on `admin_init` and uses `ALTER TABLE` to add new columns to existing installations without data loss.

---

## Key Flows

### 1. Rendering the ticker tape (frontend)

1. Theme calls `do_action('mfsd_ticker_tape_bar')` from `header.php`.
2. `mfsd_ticker_render_frontend()` fires (hooked at priority 10).
3. If user is not logged in, or the page is not the front page, the function returns immediately — nothing is rendered.
4. The current user's MFSD role is determined via `mfsd_get_user_role()` (theme helper) or by inspecting `$user->roles` directly.
5. `mfsd_ticker_get_messages_for_user()` queries all active messages and applies three-layer filtering:
   - **User-specific:** shown only to the exact `target_user_id`; no other checks apply.
   - **Course enrolment:** shown only if the user is enrolled in the specified course (LearnDash `learndash_user_get_enrolled_courses()` or fallback `mfsd_enrolled_courses` user meta).
   - **RSS feed / Standard:** shown if the role matches `roles` array (or `["all"]`).
6. For each visible message, tokens are resolved (`{first_name}`, `{display_name}`, `{username}`, `{course_name}`) and escaped via `mfsd_ticker_resolve_tokens()`. For RSS-type messages, `mfsd_ticker_fetch_rss_headlines()` returns cached or freshly-fetched headlines.
7. All texts are joined with a bullet separator (`•`). Content is duplicated once in the markup to allow the CSS `translateX(-50%)` animation to loop seamlessly.
8. Animation duration is calculated as `30 + (count($texts) * 4)` seconds and applied inline.
9. The icon displayed in the label (`⚡` for students, `★` for others) varies by role.
10. `frontend.css` is enqueued (depends on the theme's `mfsd-base` stylesheet for CSS variables).

### 2. RSS feed fetching

1. `mfsd_ticker_fetch_rss_headlines()` is called at render time with the `feed_url`, `feed_limit`, and `feed_prefix` from the DB row.
2. A transient keyed by `mfsd_rss_` + MD5 of `feed_url + limit` is checked first.
3. If not cached: `wp_remote_get()` fetches the URL with a 10-second timeout. On HTTP errors or XML parse failures, an empty array is cached for 5 minutes and returned.
4. XML is parsed with `simplexml_load_string()`; both RSS 2.0 (`channel/item`) and Atom (`entry`) formats are supported.
5. Up to `feed_limit` titles are collected, with the optional `feed_prefix` prepended. The result is cached for 30 minutes.
6. Admin can manually clear the cache via the "Refresh Feed" button, which calls `mfsd_ticker_clear_rss_cache()`.

### 3. Admin creates/edits a message

1. Admin navigates to MFSD Ticker Tape admin page (under MFSD admin group or as a top-level menu if the group does not exist).
2. A live preview bar at the bottom of the page shows all active messages with a scrolling animation.
3. Admin selects a message type (Standard, Course Enrolment, User Specific, RSS Feed) via radio buttons. jQuery shows/hides the relevant form sections dynamically.
4. For User Specific type, admin types in the user search box. An AJAX request (`mfsd_ticker_user_search`) returns up to 10 matching users. Clicking a result populates a hidden `target_user_id` field.
5. Personalisation tokens can be inserted at cursor position via the token helper buttons.
6. On submit, the form POSTs to `admin-post.php?action=mfsd_ticker_save`. `mfsd_ticker_handle_save()` validates and sanitises all fields, enforces mutual exclusivity rules (e.g. user-specific clears role targeting), and calls `mfsd_ticker_insert_message()` or `mfsd_ticker_update_message()`.
7. Admin is redirected back to the admin page with a success or error query parameter.

### 4. Toggle, Delete, Clear RSS cache

Each action (toggle active, delete, clear RSS cache) is a GET request to `admin-post.php` protected by a WordPress nonce. After the action, the admin is redirected back with a `msg=toggled|deleted|feed_cleared` parameter.

---

## AJAX / REST Endpoints

| Action / Route | Method | Auth | Description |
|----------------|--------|------|-------------|
| `wp_ajax_mfsd_ticker_user_search` | POST | `manage_options` + nonce `mfsd_ticker_admin_nonce` | Searches WP users by name, username, or email. Returns up to 10 results as `[{id, label}]`. Used for the User Specific type user picker. |
| `admin-post.php?action=mfsd_ticker_save` | POST | `manage_options` + `check_admin_referer('mfsd_ticker_save_message')` | Insert or update a ticker message. |
| `admin-post.php?action=mfsd_ticker_delete` | GET | `manage_options` + `check_admin_referer('mfsd_ticker_delete_message')` | Delete a message by `id`. |
| `admin-post.php?action=mfsd_ticker_toggle` | GET | `manage_options` + `check_admin_referer('mfsd_ticker_toggle_message')` | Toggle a message's `active` flag between 0 and 1. |
| `admin-post.php?action=mfsd_ticker_clear_rss` | GET | `manage_options` + `check_admin_referer('mfsd_ticker_clear_rss')` | Delete the transient for the RSS feed URL of the given message ID. |

No REST API routes are registered by this plugin. All admin interactions use the `admin-post.php` pattern.

---

## Admin Panel

**Location:** MFSD admin group > Ticker Tape (or top-level "MFSD Ticker" with dashicons-megaphone icon if the MFSD admin group does not exist).

**Capability required:** `manage_options`

**Layout:** Two-column on wide screens (message list + add/edit form), stacking to single column below 1100px.

**Features:**
- **Message list table:** Shows all messages (active and paused) with sort order, message text, type badge (colour-coded), target info (role names / user display name / course title / feed hostname), status pill (Live / Paused), and action buttons (Edit, Pause/Activate, Delete, Refresh Feed for RSS type).
- **Active row highlight:** Active rows have a green-tinted background; paused rows are amber-tinted with reduced opacity.
- **Add/Edit form:** Radio buttons for message type, textarea for message text (max 500 chars), token helper panel with clickable insert buttons and a reference table, role checkboxes (Everyone / Student / Parent / Teacher / Administrator), course dropdown (auto-populated from LearnDash `sfwd-courses` or generic `course` post type), user search with live AJAX autocomplete, RSS feed URL / limit / prefix fields, sort order number input, active checkbox.
- **Live preview bar:** Shows all active messages in a scrolling ticker replica at the bottom of the page. Tokens display as literal placeholders in the preview.
- **Status notices:** Success/error notices displayed as WordPress admin notices on redirect.

**Inline JS (no separate file):**
- Shows/hides form sections based on selected message type radio.
- Debounced user search (300ms) with click-outside-to-close dropdown.
- Token insert at cursor position.
- Clear user button resets the hidden `target_user_id` field.

---

## SteveGPT Integration

Not applicable. This plugin does not call SteveGPT or the Anthropic Claude API.

---

## Assets

| File | Handle | Dependencies | When loaded |
|------|--------|-------------|-------------|
| `assets/css/admin.css` | `mfsd-ticker-admin` | None | Only on the `mfsd-ticker-tape` admin page |
| `assets/css/frontend.css` | `mfsd-ticker-frontend` | `mfsd-base` (theme) | On all pages for logged-in users (`wp_enqueue_scripts`) |

No separate JS file is used for the frontend. Admin JS is rendered inline via `wp_add_inline_script('jquery', ...)`.

### `assets/css/frontend.css` — Key rules
- `.mfsd-ticker` height uses `clamp(36px, 3.2vw, 60px)` for fluid scaling.
- Label font-size uses `clamp(12px, 1.1vw, 18px)`; content font-size uses `clamp(14px, 1.2vw, 20px)`.
- Scroll animation: `@keyframes mfsd-ticker-scroll` translates from 0 to -50%. Content is duplicated in markup so -50% is exactly one copy, creating a seamless loop.
- Duration is set inline by PHP: `30 + (count($texts) * 4)` seconds.
- Hover pauses the animation via `animation-play-state: paused`.
- `@media (prefers-reduced-motion: reduce)` disables the animation and wraps text normally.

---

## Security

| Check | Where |
|-------|-------|
| `defined('ABSPATH') \|\| exit` | All PHP files |
| `check_admin_referer()` | All `admin-post.php` form handlers (save, delete, toggle, clear RSS) |
| `check_ajax_referer('mfsd_ticker_admin_nonce', 'nonce')` | AJAX user search handler |
| `current_user_can('manage_options')` | All admin handlers and the AJAX endpoint |
| `$wpdb->prepare()` | All queries with user-supplied input |
| `sanitize_textarea_field()` | Message text |
| `sanitize_text_field()` | All short text fields |
| `sanitize_key()` | Role slugs array items |
| `esc_url_raw()` | Feed URL on save |
| `esc_html()` | All output to frontend and admin pages |
| `wp_nonce_url()` | Toggle, delete, and clear-RSS action links in the admin list |
| Role check | Frontend ticker: only renders for logged-in users on the front page |

---

## Inter-Plugin Dependencies

| Dependency | Type | Notes |
|-----------|------|-------|
| `myfutureself-theme` | Required | Must fire `do_action('mfsd_ticker_tape_bar')` from `header.php` for the ticker to appear |
| `mfsd-base` CSS handle | Required | `frontend.css` declares this as a stylesheet dependency for CSS variables |
| `mfsd_get_user_role()` theme helper | Optional | If available, used to determine the user's MFSD role for filtering. Falls back to `$user->roles` inspection |
| LearnDash (`learndash_user_get_enrolled_courses()`) | Optional | Used for course enrolment filtering. Falls back to `get_user_meta($id, 'mfsd_enrolled_courses')` |

---

## WordPress Options

| Option key | Default | Purpose |
|-----------|---------|---------|
| `mfsd_ticker_db_version` | `1.0.0` | Tracks installed schema version; compared against `MFSD_TICKER_DB_VERSION` (`2.2.0`) to trigger upgrades |

---

## Version History

| Version | Changes |
|---------|---------|
| 3.5.0 | Current. Full feature set including all four message types, RSS feed caching, auto schema upgrade, fluid CSS, reduced-motion support |
| 2.2.0 | DB schema version. Added `message_type`, `course_id`, `target_user_id`, `feed_url`, `feed_limit`, `feed_prefix` columns; auto-upgrade on `admin_init` |
| 1.0.0 | Initial release. Standard role-based messages only |
