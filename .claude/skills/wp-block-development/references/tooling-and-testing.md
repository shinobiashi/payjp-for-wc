# Tooling and testing

Use this file when deciding what commands to run and what “good verification” looks like.

## Common toolchains

- `@wordpress/scripts` for build/lint/test:
  - https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/
  - `wp-scripts build --blocks-manifest` (or `build-blocks-manifest`) generates `build/blocks-manifest.php` for single-call block registration on WP 6.8+.
- `@wordpress/create-block` to scaffold new blocks:
  - https://developer.wordpress.org/block-editor/reference-guides/packages/packages-create-block/
- Interactivity API template for `create-block`:
  - https://www.npmjs.com/package/@wordpress/create-block-interactive-template
- `@wordpress/env` (wp-env) for local WordPress environments:
  - https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/

## Verification checklist

- `npm run build` (or repo equivalent) succeeds.
- JS lint passes (repo-specific).
- E2E tests pass if present.
- Manual: insert block, save post, reload editor, confirm no “Invalid block”.
