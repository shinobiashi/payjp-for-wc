# `block.json` (metadata) guidance

Use this file when you’re editing `block.json` fields or choosing between script/styles fields.

## Practical rules

- Treat `name` as stable API (renaming breaks existing content).
- Prefer adding new functionality without changing saved markup; if markup must change, add a `deprecated` version.
- Keep assets scoped: editor assets should not ship to frontend unless needed.

## API version + schema

**WordPress 6.9+ requires apiVersion 3.** The block.json schema now only validates blocks with `apiVersion: 3`. Older versions (1 or 2) trigger console warnings when `SCRIPT_DEBUG` is enabled.

**Why apiVersion 3 matters:**
- WordPress 6.9 iframes the post editor if all *registered* blocks have apiVersion 3+; WordPress 7.0 (released May 2026) relaxes this to check only blocks *inserted in the post*.
- The iframe is not yet enforced in core: any apiVersion ≤ 2 block in the content drops the editor out of the iframe (the Gutenberg plugin 22.6+ already enforces it; core enforcement is planned for a future release).
- Benefits: style isolation (admin CSS won't affect editor content), correct viewport units (vw, vh), native media queries.

**Migration checklist:**
1. Update `apiVersion` to `3` in block.json.
2. Ensure all style handles are declared in block.json (styles not included won't load in the iframe).
3. Test blocks that rely on third-party scripts (window scoping may differ).
4. Add a `$schema` to improve editor tooling and validation.

References:

- Block metadata: https://developer.wordpress.org/block-editor/reference-guides/block-api/block-metadata/
- Block API versions: https://developer.wordpress.org/block-editor/reference-guides/block-api/block-api-versions/
- Iframe migration guide: https://developer.wordpress.org/block-editor/reference-guides/block-api/block-api-versions/block-migration-for-iframe-editor-compatibility/
- Block schema index: https://schemas.wp.org/

## Modern asset fields to know

This is not a full schema; it’s a “what matters in practice” list:

- `editorScript` / `editorStyle`: editor-only assets.
- `script` / `style`: shared assets.
- `viewScript` / `viewStyle`: frontend view assets.
- `viewScriptModule`: module-based frontend scripts (stable since WP 6.5; required for Interactivity API view code).
- `render`: points to a PHP render file for dynamic blocks (WP 6.1+).

## Helpful upstream references

- Block metadata reference (block.json):
  - https://developer.wordpress.org/block-editor/reference-guides/block-api/block-metadata/
- Block.json schema (editor tooling):
  - https://schemas.wp.org/trunk/block.json

