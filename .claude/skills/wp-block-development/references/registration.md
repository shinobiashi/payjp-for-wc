# Registration patterns (PHP-first)

Use this file when you need to register blocks robustly across repo types (plugin/theme/site).

## Prefer metadata registration

Prefer:

- `register_block_type_from_metadata( $path_to_block_dir, $args = [] )`
  - (`register_block_type()` with a directory path behaves identically)

Why:

- keeps metadata authoritative (`block.json`)
- supports dynamic render (`render`) and other metadata-driven fields
- enables cleaner asset handling

Upstream reference:

- https://developer.wordpress.org/reference/functions/register_block_type_from_metadata/

## Multi-block plugins: manifest registration (WP 6.8+)

Since WordPress 6.8 the recommended way to register several blocks is a single call over a generated manifest:

1. Build with `wp-scripts build --blocks-manifest` (generates `build/blocks-manifest.php`).
2. Register all blocks at once:
   - `wp_register_block_types_from_metadata_collection( __DIR__ . '/build', __DIR__ . '/build/blocks-manifest.php' );`

This avoids reading each `block.json` from disk on every request. If you must support WP 6.7, use `wp_register_block_metadata_collection()` plus individual `register_block_type()` calls instead.

Upstream reference:

- https://developer.wordpress.org/reference/functions/wp_register_block_types_from_metadata_collection/

## Where to register

- Plugins: register on `init` in the main plugin bootstrap or a dedicated loader.
- Themes: register on `init` (or `after_setup_theme` if you need theme supports first), but keep it predictable.

## Dynamic render mapping

If `block.json` includes `render`, ensure the file exists relative to the block root.
Inside the render file, use `get_block_wrapper_attributes()` for wrapper attributes.

