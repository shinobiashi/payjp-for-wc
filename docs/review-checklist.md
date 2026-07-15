# 手動セルフレビューチェックリスト

> CLAUDE.md から参照される。PHP ファイルを新規作成・編集したら、
> PHPCS / PHPStan のあとにこの 10 項目を必ず自分でチェックすること
> （ツールでは検出できない項目）。

| # | チェック項目 | 確認ポイント |
|---|------------|------------|
| 1 | **ファイルヘッダー** | `defined( 'ABSPATH' ) \|\| exit;` が先頭にあるか。GPL ライセンスヘッダーと `@package` があるか |
| 2 | **DocBlock** | 全クラス・メソッド・プロパティに PHPDoc があるか。`@param`・`@return`・`@throws` の漏れはないか |
| 3 | **i18n** | ユーザー向け文字列がすべて `__( 'text', 'payjp-for-wc' )` 等でラップされているか |
| 4 | **出力エスケープ** | `echo` する変数に `esc_html()`・`esc_attr()`・`esc_url()` が必ず付いているか |
| 5 | **入力サニタイズ** | `$_POST`・`$_GET` は `wp_unslash()` + `sanitize_*()` + `is_string()` ガードがあるか。配列フィールドは要素を `is_string()` で先に絞り込んでから `sanitize_*()` を適用しているか（順序を逆にするとネスト配列で `sanitize_text_field()` に配列が渡りうる） |
| 6 | **Webhook** | トークン検証は `hash_equals()` のみか（`===` / `strcmp()` は不可） |
| 7 | **HPOS** | オーダーメタは `$order->get_meta()` / `update_meta_data()` のみか。`get_post_meta()` を使っていないか |
| 8 | **HTTP リクエスト** | `wp_remote_*` を使っているか。`curl` を直接呼んでいないか。`is_wp_error()` を確認しているか |
| 9 | **Yoda 条件** | 定数・リテラルを左辺に書いているか（`'yes' === $var`、`null === $x`） |
| 10 | **クラスガード** | `class_exists()` で二重定義を防いでいるか |
