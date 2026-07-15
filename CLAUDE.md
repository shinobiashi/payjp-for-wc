# PAY.JP for WooCommerce — Claude Code Instructions

> 詳細な開発計画・フェーズ定義・WordPress.org 要件は `DEVELOPMENT_PLAN.md` を参照。
> このファイルは Claude Code が作業する上での参照ガイドです。

---

## Git ワークフロー（必須ルール）

| 操作 | 可否 | 備考 |
|------|------|------|
| コードの編集・作成 | ✅ OK | 通常どおり行う |
| `git commit` | ❌ 禁止 | **ユーザーが手動で行う** |
| `git push` | ❌ 禁止 | **ユーザーが手動で行う** |
| `gh pr create` | ✅ OK | ユーザーに確認後に作成する |

> **コミットは絶対に自動で行わないこと。** コード変更後は「変更内容の説明」を出力し、ユーザーがコミット・プッシュするのを待つ。

---

## プロジェクト概要

| 項目 | 値 |
|------|-----|
| プラグインスラッグ / テキストドメイン | `payjp-for-wc` |
| メインファイル | `payjp-for-wc.php` |
| 決済 API | PAY.JP v2（Payment Flow API + Payment Widgets 埋め込み型）|
| 対象環境 | WordPress 6.9+ / WooCommerce 9.0+ / PHP 8.3+ |
| ライセンス | GPL-2.0-or-later |
| 配布 | ① wordpress.org standalone ② Japanized for WooCommerce 同梱 |
| ゲートウェイ | `payjp_card`（クレジットカード）/ `payjp_paypay`（PayPay）|

---

## 詳細リファレンス（必要時に Read すること）

| ファイル | 内容 | 読むタイミング |
|---------|------|--------------|
| `docs/architecture.md` | ファイル構成・定数・オーダーメタキー・アーキテクチャ決定事項の詳細 | 構造に関わる変更・調査の前 |
| `docs/testing.md` | PayPay/カード/Webhook のテスト方法・wp-env 注意点・PAY.JP ドキュメント URL | 動作確認・決済テストの前 |
| `docs/review-checklist.md` | 手動セルフレビュー 10 項目 | PHP 編集後のチェック時（必須）|

---

## スキル

コードを書く前に必ず該当スキルを呼び出すこと:

- PAY.JP API / 決済フロー → `payjp-v2-woocommerce` スキル
- WooCommerce ゲートウェイ / HPOS / Blocks → `wc-development` スキル
- WordPress プラグイン全般 → `wp-plugin-development` スキル
- WordPress セキュリティ監査 → `wp-security-check` スキル
- PHPStan → `wp-phpstan` スキル
- WooCommerce Marketplace 提出 → `woo-marketplace-submission` スキル

---

## コード作成後の必須チェック（省略不可）

**PHP ファイルを新規作成・編集した後は、必ず以下を順番に実行すること。**
コミット前に全項目がクリアされていない場合はコミットしてはならない。

```bash
# 1. PHPCS（エラー・警告 0 件。先に phpcbf で自動修正してよい）
vendor/bin/phpcbf --standard=phpcs.xml .
vendor/bin/phpcs --standard=phpcs.xml .

# 2. PHPStan（level 5、エラー 0 件）
vendor/bin/phpstan analyse --memory-limit=1G

# 3. PHPUnit
vendor/bin/phpunit
```

**4. 手動セルフレビュー**: `docs/review-checklist.md` の 10 項目を必ず確認する
（ABSPATH ガード / DocBlock / i18n / エスケープ / サニタイズ / `hash_equals()` /
HPOS / `wp_remote_*` / Yoda 条件 / `class_exists()` ガード）。

---

## 開発コマンド

```bash
npm run env:start    # WordPress + WooCommerce 起動（localhost:8888）
npm run env:stop     # 停止
npm run start        # JS ウォッチビルド
npm run build        # JS 本番ビルド
npm run lint:js      # JS lint
npm run lint:css     # CSS lint
```

---

## コーディング規約（核心のみ・詳細はセルフレビュー 10 項目と重複）

- WordPress Coding Standards + PHPStan level 5 でゼロエラー必須
- PHP 8.3+（共変戻り値型など現代的な PHP 機能を積極的に使用）。未使用の catch 変数は
  non-capturing catch（`catch ( RuntimeException )`）で省略可。`unset()` での回避は不要
- HTTP リクエストは `wp_remote_*` のみ + `is_wp_error()` チェック。例外は `RuntimeException` で統一
- 管理画面 AJAX: `wp_verify_nonce()` + `current_user_can( 'manage_woocommerce' )`
- DB クエリ: `$wpdb->prepare()` 必須
- Payment Flow ID はサーバーサイドで API 検証（クライアントの値を信頼しない）
- 定数定義は `defined() || define()` パターン（Japanized for WooCommerce 同梱時の二重読み込み防止）
- オーダーメタは HPOS API（`$order->get_meta()` 等）のみ。`get_post_meta()` 禁止
- JS: `@wordpress/scripts` ESLint 準拠。payments.js はページ内で一度だけ初期化。
  エラー表示は `role="alert" aria-live="polite"` 付き要素へ。`src/` を必ず同梱
- i18n: テキストドメイン `payjp-for-wc`。`load_plugin_textdomain()` は `plugins_loaded` で。
  変数の直接結合禁止 → `sprintf()` / `printf()`
- `JP4WC_Logger::log_error()` に構造化 context 引数はない（`log_event()` と違う）。
  flow_id 等の識別子はメッセージ文字列に埋め込むこと（例: `'... (flow_id=' . $flow_id . ')'`）
- Webhook ペイロードの値は truthy 判定に頼らず型を検証してから使う
  （`livemode` 等の環境判定フィールドは `isset()` + `is_bool()` で必須化。
  文字列 `"false"` は PHP では truthy になる点に注意）

---

## アーキテクチャ上の重要な決定事項（要約）

**変更前に必ず `docs/architecture.md` の該当セクション詳細を読むこと。**

1. **共有設定**: API キー・テストモード・Webhook シークレットは全決済手段で共有
   （`Payjp_Settings::OPTION_KEY = 'payjp_settings'`）
2. **payments.js は CDN 読み込み**: PCI 準拠目的で wordpress.org 審査上も正当。
   `readme.txt` の External Services 開示が必須
3. **`is_available()` の連動**: `Payjp_Settings::is_method_enabled()` が false なら
   ゲートウェイもチェックアウトから非表示
4. **支払い方法変更の正当性判定**: `class-payjp-loader.php` のガード群は
   セッションの `chosen_payment_method` を基準に正当な切り替えを判定。
   切り替え許可時は `_payjp_*` メタと **`transaction_id` の両方**をクリアする
5. **`payment_complete()` のステータス許可リストガード**: WC コアは `cancelled` からの
   payment_complete を許すため、呼ぶ前に必ず
   `$order->has_status( array( 'pending', 'failed', 'on-hold' ) )` で守る（#22 の教訓）
6. **遅延 Webhook はステータスを変えず通知する**: 確定済み注文への `succeeded` /
   `amount_capturable_updated` 到着時は `processing`/`refunded` へ遷移させず、
   注文メモ + 管理者メール（`Payjp_Admin_Notifier`）+ ログで可視化する（#23 の教訓）
7. **cancel と決済確定のレース**: `cancel_payment_flow()` の cancel API 呼び出しが失敗したら、
   エラー種別を判定せず必ずフローを再取得する。`succeeded` に遷移していれば
   `refund_succeeded_flow_on_cancel()`（`_payjp_cancel_refund_processed` ガード共有）で
   自動返金へフォールバックする。呼び出し元の注文メモ文言は、このガードで返金が
   スキップされうる分岐も正しく表現すること（#24 の教訓）

---

## テスト環境（要点）

- PayPay テスト: アカウント・上限・手順は `docs/testing.md` 参照（¥100 上限・要全額返金）
- Webhook テスト: PAY.JP ダッシュボードから送信。ローカルは着信不可のため REST 経由で手動発火
- wp-env 新規インストールは `woocommerce_coming_soon` の解除が必要（詳細は `docs/testing.md`）
