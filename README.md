# URL Usage Finder

WordPress plugin to find and safely replace URL usage across multiple data sources.

## Features

- Search by exact URL match in:
  - `post_content`
  - `post_excerpt`
  - `post_meta` (including ACF values stored in post meta)
  - navigation menu item meta (`nav_menu_item`)
  - `wp_options` (excluding transients by default)
- Result table with:
  - source type
  - object label
  - field/meta key
  - element hint (`link`, `image`, `button`, `raw_text`)
  - context snippet
  - edit link when available
- Pagination for large result sets (50 rows per page).
- CSV export for the current search results.
- Dry-run preview before replacement (`Before` / `After`).
- Safe selected-row replacement only (no bulk blind overwrite).

## Installation

1. Copy plugin folder to:
   - `wp-content/plugins/url-usage-finder`
2. In WordPress admin, activate:
   - `URL Usage Finder`
3. Open:
   - `Tools -> URL Usage Finder`

## Usage

1. Enter **Old URL**.
2. Select search sources.
3. Click **Find URL Usage**.
4. Review matches and select rows.
5. Enter **New URL**.
6. Click **Preview Selected (Dry Run)** to inspect planned changes.
7. Click **Replace Selected** to apply updates.
8. Optionally click **Export CSV** to download results.

## Data Handling

- Replacements are executed only for selected rows.
- `post_content` / `post_excerpt` updates are done via `wp_update_post()`.
- Post meta updates are done via `update_post_meta()`.
- Option updates are done via `update_option()`.
- Arrays and objects are handled with recursive string replacement to preserve structure.

## Security

- Admin capability required: `manage_options`.
- All write actions protected by nonces.
- Search SQL uses `$wpdb->prepare()` and `$wpdb->esc_like()`.
- Output is escaped in admin rendering.

## Notes and Limitations

- Search is exact-string based for the entered URL.
- Element detection is heuristic and best-effort.
- For very large databases, initial search may still take time depending on host performance.
- CSV export uses current stored search results for the current admin user session.

## Changelog

### 0.1.1

- Improved URL variant matching and boundary handling.
- Unified search and replacement matching logic.
- Temporarily disabled replacement UI and handlers by default.

### 0.1.0

- Initial release.
- Multi-source URL search.
- Selected-row replace.
- Pagination.
- CSV export.
- Dry-run diff preview.
