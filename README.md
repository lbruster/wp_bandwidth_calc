# Check Bandwidth

A small WordPress plugin that estimates monthly bandwidth usage from page size, visits and pageviews, stores submissions in a custom table, and exposes a searchable list.

## What it does

- `[cb_bandwidth]` — a public form: average page size (KB), average monthly visits, average pageviews per visit, and the site's URL. On submit it calculates `page_size × visits × pageviews` and stores the result.
- `[cb_bandwidth_list]` — a searchable table of saved entries (admin-only by default; add `public="yes"` to make it visible to everyone). Search works by entry ID or by a partial match on the website URL.
- `[my_list]` — kept as a backwards-compatible alias of `[cb_bandwidth_list]`.

## Why this version exists

This is a rewrite of an earlier draft of the same plugin. The original had a few problems that are worth being upfront about, since fixing them was most of the work:

- **SQL injection** — the list shortcode built a query by concatenating a raw `$_POST` value straight into SQL (`WHERE \`id\` = $pageid`), with no sanitization or prepared statement.
- **No nonce / CSRF protection** on the form submission handler.
- **No input validation** — the insert function wrote whatever arrived in `$_POST` directly to the database, including into a field that was later echoed back unescaped (a stored XSS risk).
- **A broken search path** — the list shortcode called a second function that queried `WP_Query` against regular WordPress posts, unrelated to the actual custom table, using undefined variables. Dead code that never worked.
- **Mismatched field names** — the list shortcode's form posted a different field name than the one the handler actually read, so the intended search feature never functioned at all.

## What changed

- All queries go through `$wpdb->prepare()` with typed placeholders (`%d` / `%s`); `esc_like()` is used for the partial-text search.
- Form submissions are protected by a WordPress nonce and processed through `admin-post.php` with the standard Post/Redirect/Get pattern (submit → validate → redirect with a status flag → re-render), so refreshing the result page never resubmits the form.
- Every input is sanitized and bounds-checked (page size, visits and pageviews are capped to sane maximums; the URL field is validated and length-limited) before it's stored.
- All output is escaped (`esc_html`, `esc_attr`, `esc_url`) when rendered back.
- A hidden honeypot field silently discards likely bot submissions without touching the database.
- The results list is admin-only by default, with an explicit opt-in (`public="yes"`) to make it public — rather than being public by default with no way to restrict it.
- The DB schema is versioned, so `dbDelta()` only runs on activation or when the schema version actually changes, not on every page load.

## Testing

Verified locally against a from-scratch WordPress 6.8 install:
- A legitimate form submission inserts a row and the success notice shows the correct calculated bandwidth.
- Search by entry ID and by partial website text both return the right rows; a non-matching search shows "No entries found".
- Out-of-range input (e.g. pageviews far above the allowed maximum) is rejected and no row is written.
- A submission with the honeypot field filled in is silently discarded.
- A request with a missing or invalid nonce is rejected with a 403.

## Requirements

- WordPress 5.8+
- PHP 7.4+

---

Built by Leroy Bruster.
