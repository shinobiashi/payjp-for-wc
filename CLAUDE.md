# PAY.JP for WooCommerce — Claude Code Instructions

> 詳細な開発計画・フェーズ定義・WordPress.org 要件は `DEVELOPMENT_PLAN.md` を参照。
> このファイルは Claude Code が作業する上での参照ガイドです。

---

## プロジェクト概要

| 項目 | 値 |
|------|-----|
| プラグインスラッグ | `payjp-for-woocommerce` |
| テキストドメイン | `payjp-for-woocommerce` |
| メインファイル | `payjp-for-woocommerce.php` |
| 決済 API | PAY.JP v2 |
| 対象環境 | WordPress 6.4+ / WooCommerce 8.0+ / PHP 8.0+ |
| ライセンス | GPL-2.0-or-later |
| 配布 | ① wordpress.org standalone ② Japanized for WooCommerce 同梱 |

## 実装する決済手段

| Gateway ID | 決済手段 | 統合方式 |
|-----------|---------|---------|
| `payjp_card` | クレジットカード | Payment Widgets（埋め込み型）|
| `payjp_paypay` | PayPay | Payment Widgets（埋め込み型）|

両手段とも **PAY.JP v2 Payment Flow API** を使用する（Checkout v2 は v2 以降保留）。

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

## ファイル構成

```
payjp-for-woocommerce.php          ← ブートストラップ・定数定義
uninstall.php                      ← プラグイン削除時のデータ削除
includes/
  class-payjp-loader.php           ← クラスロード・フック登録
  class-payjp-settings.php         ← 共有設定マネージャー（API キー等）
  class-payjp-api.php              ← PAY.JP API ラッパー（wp_remote_* 使用）
  class-wc-gateway-payjp.php       ← 抽象基底クラス（共通処理）
  class-wc-gateway-payjp-card.php  ← カード決済ゲートウェイ
  class-wc-gateway-payjp-paypay.php← PayPay 決済ゲートウェイ
  class-payjp-webhook-handler.php  ← Webhook 受信・検証・ルーティング
  class-payjp-blocks-integration.php ← Block Checkout 統合（PHP 側）
  class-payjp-admin-settings-page.php ← 統合設定画面
  class-payjp-token-manager.php    ← WC Token API + Setup Flow 管理
  class-payjp-subscriptions.php    ← WooCommerce Subscriptions 対応
templates/
  return.php                       ← payments.js リダイレクト後の受け口
src/
  blocks/checkout/
    index.js                       ← registerPaymentMethod（カード・PayPay）
    payment-method-card.js         ← カード決済 React コンポーネント
    payment-method-paypay.js       ← PayPay 決済 React コンポーネント
  admin/settings/index.js          ← 管理画面 JS
build/                             ← コンパイル済み（git 管理外）
```

---

## 定数（`payjp-for-woocommerce.php` で定義）

```php
defined( 'PAYJP_FOR_WC_VERSION' ) || define( 'PAYJP_FOR_WC_VERSION', '1.0.0' );
defined( 'PAYJP_FOR_WC_FILE' )    || define( 'PAYJP_FOR_WC_FILE',    __FILE__ );
defined( 'PAYJP_FOR_WC_DIR' )     || define( 'PAYJP_FOR_WC_DIR',     plugin_dir_path( __FILE__ ) );
defined( 'PAYJP_FOR_WC_URL' )     || define( 'PAYJP_FOR_WC_URL',     plugin_dir_url( __FILE__ ) );
defined( 'PAYJP_API_BASE' )       || define( 'PAYJP_API_BASE',       'https://api.pay.jp/v2' );
```

`defined() || define()` パターンは Japanized for WooCommerce への同梱時の二重読み込み防止のため必須。

---

## オーダーメタキー

| キー | 型 | 説明 |
|------|-----|------|
| `_payjp_payment_flow_id` | string | Payment Flow ID (`pflw_xxx`) |
| `_payjp_payment_method` | string | `card` または `paypay` |
| `_payjp_capture_method` | string | `automatic` または `manual` |
| `_payjp_refund_id` | string | 最新の返金 ID |
| `_payjp_customer_id` | string | PAY.JP Customer ID（トークン保存・Subscriptions 用）|
| `_payjp_payment_method_id` | string | 保存カードの PaymentMethod ID |

**HPOS 必須**: オーダーメタは `$order->get_meta()` / `$order->update_meta_data()` + `$order->save()` を使う。`get_post_meta()` / `update_post_meta()` は禁止。

---

## 開発コマンド

```bash
# ローカル環境
npm run env:start    # WordPress + WooCommerce 起動（localhost:8888）
npm run env:stop     # 停止

# JS ビルド
npm run start        # ウォッチビルド
npm run build        # 本番ビルド

# コード品質
vendor/bin/phpcs --standard=phpcs.xml.dist .          # PHPCS チェック
vendor/bin/phpcs --standard=phpcs.xml.dist . --fix    # 自動修正
vendor/bin/phpstan analyse                             # PHPStan（level 5）
npm run lint:js                                        # JS lint
npm run lint:css                                       # CSS lint
```

---

## コーディング規約

### PHP

- WordPress Coding Standards 準拠（`vendor/bin/phpcs` でゼロエラー必須）
- PHPStan level 5 でエラーなし（`vendor/bin/phpstan analyse`）
- 全 PHP ファイルの先頭: `defined( 'ABSPATH' ) || exit;`
- 全 PHP ファイルに GPL-2.0-or-later ライセンスヘッダー
- クラスは `class_exists()` でガード
- HTTP リクエストは `wp_remote_post()` / `wp_remote_get()` のみ（`curl` 直呼び出し禁止）
- `wp_remote_*` の戻り値は必ず `is_wp_error()` でチェック
- 例外は `RuntimeException` で統一

### セキュリティ（必須・漏れ不可）

- 入力: `sanitize_text_field()` + `wp_unslash()`、URL は `esc_url_raw()`、整数は `absint()`
- 出力: `esc_html()` / `esc_attr()` / `esc_url()` / `wp_kses_post()`
- Webhook トークン検証: **`hash_equals()`** のみ（タイミング攻撃対策）
- 管理画面 AJAX: `wp_verify_nonce()` + `current_user_can( 'manage_woocommerce' )`
- DB クエリ: `$wpdb->prepare()` 必須
- Payment Flow ID はサーバーサイドで API 検証（クライアントの値を信頼しない）

### JavaScript

- `@wordpress/scripts` の ESLint 設定準拠
- payments.js はページ内で一度だけ初期化
- エラー表示は `role="alert" aria-live="polite"` 付き要素に出力
- `src/` を必ず同梱（minified のみは wordpress.org 審査で NG）

### i18n

- テキストドメイン: `payjp-for-woocommerce`（プラグインスラッグと一致させること）
- `load_plugin_textdomain()` を `plugins_loaded` フックで呼び出す
- 変数を文字列に直接結合しない → `sprintf()` / `printf()` を使用

---

## アーキテクチャ上の重要な決定事項

### 共有設定 (`class-payjp-settings.php`)

API キー・テストモード・Webhook シークレットは **全決済手段で共有**。
`Payjp_Settings::OPTION_KEY = 'payjp_settings'` に一元管理。
個別ゲートウェイの設定画面はタイトル・説明文などの表示設定のみ。

### payments.js の CDN 読み込み

`https://js.pay.jp/payments.js` を CDN から読み込む。
PCI 準拠が目的であり、wordpress.org 審査で認められているパターン（Stripe の `js.stripe.com` と同様）。
`readme.txt` の `== External Services ==` セクションへの開示が必須。

### `is_available()` の連動

`Payjp_Settings::is_method_enabled( 'card' )` が `false` を返した場合、
`WC_Gateway_Payjp_Card::is_available()` も `false` を返し、チェックアウトから非表示になる。

---

## テスト環境

- **PayPay テストアカウント**: 080-1111-5912 〜 080-1111-5921（10 アカウント）
- **PayPay テスト上限**: ¥100 / 回（テスト後に全額返金必須）
- **Webhook テスト**: PAY.JP ダッシュボード > Webhook > テスト送信

---

## PAY.JP v2 ドキュメント

| リソース | URL |
|---------|-----|
| ガイド | https://docs.pay.jp/v2/guide |
| API リファレンス | https://docs.pay.jp/v2/api |
| LLM 向け全文 | https://docs.pay.jp/v2/llms-full.txt |

個別ページ MDX: 各ページ URL に `.mdx` を付与（例: `https://docs.pay.jp/v2/guide/payments/checkout.mdx`）
