# PAY.JP for WooCommerce — 開発計画書

> このファイルは `CLAUDE.md` として使用することを想定した開発計画書です。
> レビュー後に `CLAUDE.md` にリネームしてください。

---

## プロジェクト概要

| 項目 | 内容 |
|------|------|
| プラグイン名 | PAY.JP for WooCommerce |
| スラッグ | `payjp-for-woocommerce` |
| テキストドメイン | `payjp-for-woocommerce` |
| バージョン | 1.0.0 |
| 対象 WooCommerce | 8.0+ |
| 対象 WordPress | 6.4+ |
| 対象 PHP | 8.0+（Japanized for WooCommerce 同梱時は実質 8.3+）|
| 決済 API | PAY.JP v2 |
| ライセンス | GPL-2.0-or-later |
| 配布形態 | ① standalone（wordpress.org）② Japanized for WooCommerce 同梱 |

### 実装する決済手段

| 決済手段 | Gateway ID | 統合方式 |
|---------|-----------|---------|
| クレジットカード | `payjp_card` | Payment Widgets（埋め込み型）|
| PayPay | `payjp_paypay` | Payment Widgets（埋め込み型）|

両手段とも **PAY.JP v2 Payment Flow API** を使用する。
外部リンク型（Checkout v2）は v2 以降の対応として保留。

---

## 配布形態と同梱対応

### ① Standalone（wordpress.org 公開）

通常の WordPress プラグインとして単独インストール・有効化できる。

### ② Japanized for WooCommerce 同梱

[Japanized for WooCommerce](https://wordpress.org/plugins/woocommerce-for-japan/)（10,000+ installs）に同梱し、
追加インストール不要で決済機能を提供する。

**二重読み込み防止の仕組み:**

```php
// Japanized for WooCommerce 側のローダー例
if ( ! defined( 'PAYJP_FOR_WC_VERSION' ) ) {
    require_once __DIR__ . '/gateways/payjp/payjp-for-woocommerce.php';
}
```

プラグイン本体では定数を `defined() || define()` パターンで定義し、
クラスは `class_exists()` でガードする。

**パス・URL の解決:**

`plugin_dir_path( __FILE__ )` / `plugin_dir_url( __FILE__ )` を一貫して使用することで、
どのディレクトリに置かれても正しいパス・URL を返す。

---

## アーキテクチャ方針

### バックエンド

```
payjp-for-woocommerce.php                ← ブートストラップ・定数定義
uninstall.php                            ← アンインストール時のデータ削除
includes/
  class-payjp-loader.php                 ← クラスオートロード・フック登録
  class-payjp-settings.php              ← 共通設定マネージャー（API キー等）
  class-payjp-api.php                   ← PAY.JP API ラッパー
  class-wc-gateway-payjp.php            ← 抽象基底クラス（共通処理）
  class-wc-gateway-payjp-card.php       ← カード決済ゲートウェイ
  class-wc-gateway-payjp-paypay.php     ← PayPay 決済ゲートウェイ
  class-payjp-webhook-handler.php       ← Webhook 受信・検証・ルーティング
  class-payjp-blocks-integration.php    ← Block Checkout 統合（PHP 側）
  class-payjp-admin-settings-page.php   ← 統合設定画面
templates/
  return.php                             ← payments.js リダイレクト後の受け口
readme.txt                               ← wordpress.org 用 readme
```

### フロントエンド

```
src/blocks/checkout/
  index.js                  ← registerPaymentMethod（カード・PayPay）
  payment-method-card.js    ← カード決済 React コンポーネント
  payment-method-paypay.js  ← PayPay 決済 React コンポーネント
src/admin/settings/
  index.js                  ← 管理画面 JS（拡張時に使用）
build/                      ← コンパイル済み（gitignore）
```

### 決済フロー（埋め込み型）

```
[チェックアウト画面表示]
  └─ PHP: payment_fields() → Payment Flow 作成 → client_secret 取得
  └─ JS:  payments.js ウィジェットをマウント（CDN から読み込み）

[顧客が支払いボタンを押す]
  └─ JS: widgets.confirmPayment({ return_url }) を実行
  └─ PAY.JP: 3DS 等を処理後、return_url にリダイレクト

[return_url に戻ってくる]
  └─ PHP: payment_flow_id でサーバーサイド API 検証
  └─ PHP: status === 'succeeded' → payment_complete()

[Webhook 受信（非同期・権威ソース）]
  └─ PHP: X-Payjp-Webhook-Token を hash_equals() で検証
  └─ PHP: payment_flow.succeeded → payment_complete()（冪等）
```

---

## 統合設定画面（管理画面で決済を選ぶ）

### 設計方針

API キー・テストモード・Webhook シークレットは **全決済手段で共有** する。
どの決済手段を有効にするかを **1 ヵ所（PAY.JP 設定画面）で管理** し、
個別ゲートウェイ設定では表示タイトルなどの細部だけを設定する。

### 共有設定オプション（`payjp_settings`）

```php
[
    'test_mode'       => true,
    'test_public_key' => 'pk_test_xxx',
    'test_secret_key' => 'sk_test_xxx',
    'live_public_key' => 'pk_live_xxx',
    'live_secret_key' => 'sk_live_xxx',
    'webhook_secret'  => 'your_webhook_token',
    'enabled_methods' => [ 'card', 'paypay' ],  // 有効にする決済手段
]
```

### 統合設定画面の構成

```
WooCommerce > 設定 > 決済 > PAY.JP 設定
─────────────────────────────────────────────────────
  [セクション] API 設定
    テストモード         [ON / OFF]
    テスト 公開鍵        [input]
    テスト 秘密鍵        [input / password]
    本番 公開鍵          [input]
    本番 秘密鍵          [input / password]
    Webhook シークレット  [input]

  [セクション] 有効にする決済手段
    [✓] クレジットカード（PAY.JP）
    [✓] PayPay（PAY.JP）
─────────────────────────────────────────────────────
```

個別ゲートウェイ設定（`WooCommerce > 設定 > 決済 > PAY.JP クレジットカード`）:

```
  表示タイトル         [input]  ← 顧客に見える決済名
  説明文               [textarea]
  キャプチャ方法        [automatic / manual]  ← カードのみ
```

### 有効化の連動

`Payjp_Settings::get_enabled_methods()` の結果に基づき、
各ゲートウェイの `is_available()` が `false` を返すことで自動的に非表示になる。

---

## ファイル詳細設計

### 定数（`payjp-for-woocommerce.php`）

```php
defined( 'PAYJP_FOR_WC_VERSION' ) || define( 'PAYJP_FOR_WC_VERSION', '1.0.0' );
defined( 'PAYJP_FOR_WC_FILE' )    || define( 'PAYJP_FOR_WC_FILE',    __FILE__ );
defined( 'PAYJP_FOR_WC_DIR' )     || define( 'PAYJP_FOR_WC_DIR',     plugin_dir_path( __FILE__ ) );
defined( 'PAYJP_FOR_WC_URL' )     || define( 'PAYJP_FOR_WC_URL',     plugin_dir_url( __FILE__ ) );
defined( 'PAYJP_API_BASE' )       || define( 'PAYJP_API_BASE',       'https://api.pay.jp/v2' );
```

### 共通設定マネージャー（`class-payjp-settings.php`）

```php
class Payjp_Settings {
    const OPTION_KEY = 'payjp_settings';

    public static function get( string $key, $default = '' );
    public static function get_all(): array;
    public static function get_public_key(): string;   // test/live 自動切替
    public static function get_secret_key(): string;   // test/live 自動切替
    public static function is_test_mode(): bool;
    public static function get_enabled_methods(): array; // ['card', 'paypay']
    public static function is_method_enabled( string $method ): bool;
    public static function get_webhook_secret(): string;
}
```

### PAY.JP API ラッパー（`class-payjp-api.php`）

```php
class Payjp_API {
    public function __construct( string $secret_key );
    public function post( string $endpoint, array $body ): array;
    public function get( string $endpoint ): array;
    // エラー時は RuntimeException を throw
    // wp_remote_post() / wp_remote_get() を使用（curl 直呼び出し禁止）
    // 全リクエストに timeout: 30 を設定
}
```

### アンインストール（`uninstall.php`）

```php
// プラグイン削除時に設定・メタデータを削除
delete_option( 'payjp_settings' );
delete_option( 'woocommerce_payjp_card_settings' );
delete_option( 'woocommerce_payjp_paypay_settings' );
// 注文メタは削除しない（取引記録として保持）
```

### 主要オーダーメタキー

| メタキー | 型 | 説明 |
|---------|-----|------|
| `_payjp_payment_flow_id` | string | Payment Flow ID (`pflw_xxx`) |
| `_payjp_payment_method` | string | `card` または `paypay` |
| `_payjp_capture_method` | string | `automatic` または `manual` |
| `_payjp_refund_id` | string | 最新の返金 ID |

---

## 開発フェーズ

### Phase 1: プラグインスケルトン

**目標:** プラグインとして認識され、管理画面の「決済」タブに表示される状態にする。

- [ ] `payjp-for-woocommerce.php` — ヘッダー・定数・`class_exists` ガード・HPOS 宣言
- [ ] `uninstall.php` — 削除時の設定クリア
- [ ] `class-payjp-loader.php` — クラスロード・フック登録
- [ ] `class-payjp-settings.php` — 共有設定管理
- [ ] 各クラスファイルの空実装
- [ ] `add_filter( 'woocommerce_payment_gateways', ... )` でゲートウェイ登録

**完了条件:** WooCommerce > 設定 > 決済 にカード・PayPay が表示される。

---

### Phase 2: 統合設定画面

**目標:** PAY.JP 設定画面から API キーと有効決済手段を設定できる。

- [ ] `class-payjp-admin-settings-page.php` — WC Settings API を使った設定ページ
- [ ] `payjp_settings` オプションへの保存・読み込み
- [ ] `Payjp_Settings::get_public_key()` / `get_secret_key()` の test/live 自動切替
- [ ] `is_available()` で `Payjp_Settings::is_method_enabled()` を確認

**完了条件:** 設定を保存し、`Payjp_Settings::get()` で取得できる。

---

### Phase 3: カード決済（埋め込み型）

**目標:** テスト環境でカード決済が完了し、注文ステータスが「処理中」になる。

- [ ] `Payjp_API` クラス完全実装
- [ ] `payment_fields()`: Payment Flow 作成 → `wp_add_inline_script()` で client_secret を渡す
- [ ] payments.js を CDN からエンキュー（`wp_enqueue_script`）
- [ ] `checkout.js`: payments.js ウィジェットマウント・confirmPayment
- [ ] `template_redirect` フック: return_url ハンドラ実装
- [ ] API で Payment Flow を検証 → `payment_complete()` 呼び出し
- [ ] `_payjp_payment_flow_id` をオーダーメタに保存

**完了条件:** テストカードで決済完了 → 注文ステータス「処理中」。

---

### Phase 4: PayPay 決済（埋め込み型）

**目標:** テスト PayPay アカウントで決済が完了し、注文ステータスが「処理中」になる。

- [ ] `payment_fields()`: Payment Flow 作成（`payment_method_types: ['paypay']`）
- [ ] `payment-method-paypay.js`: PayPay フォームマウント・confirmPayment
- [ ] return_url ハンドラをカードと共通化

**完了条件:** PayPay テストアカウントで決済完了 → 注文ステータス「処理中」。

---

### Phase 5: Webhook ハンドラ

**目標:** PAY.JP からの Webhook を受信し、注文ステータスを権威ソースで更新する。

- [ ] REST エンドポイント: `POST /wp-json/payjp/v1/webhook`
- [ ] `hash_equals()` でトークン検証
- [ ] `payment_flow.succeeded` → `payment_complete()`（冪等）
- [ ] `payment_flow.payment_failed` → `update_status('failed')`
- [ ] `refund.created` → `wc_create_refund()`

**完了条件:** PAY.JP ダッシュボードのテスト送信で注文ステータスが更新される。

---

### Phase 6: 返金処理

**目標:** WooCommerce 管理画面からの返金が PAY.JP に反映される。

- [ ] カード: `process_refund()` → `POST /v2/refunds`
- [ ] PayPay: `supports` に `refunds` を含めず、手動対応とする

**完了条件:** カード注文に対して部分・全額返金が成功する。

---

### Phase 7: Block Checkout 統合

**目標:** WooCommerce Block Checkout でカード・PayPay が使用できる。

- [ ] `Payjp_Blocks_Integration` クラス（`AbstractPaymentMethodType` 実装）
- [ ] `woocommerce_blocks_payment_method_type_registration` フックで登録
- [ ] `get_payment_method_data()` で JS に設定値を渡す
- [ ] `payment-method-card.js`: payments.js ウィジェット統合（`useEffect` でマウント）
- [ ] Block Checkout の `onPaymentSetup` フックで client_secret を WC に渡す

**完了条件:** Gutenberg チェックアウトブロックでカード・PayPay が表示・決済できる。

---

### Phase 8: 品質・テスト

- [ ] PHPCS: `vendor/bin/phpcs` — 0 エラー・0 警告
- [ ] PHPStan: level 5 でエラーなし
- [ ] JS lint: `npm run lint:js` — エラーなし
- [ ] wp-env でエンドツーエンド手動テスト
- [ ] HPOS 有効時の動作確認
- [ ] 同梱テスト: Japanized for WooCommerce に組み込んで二重読み込みがないことを確認
- [ ] Block Checkout / Classic Checkout の両方で動作確認

---

## WordPress.org 公開要件

### readme.txt 必須項目

```
=== PAY.JP for WooCommerce ===
Contributors: shohei.tanaka
Tags: woocommerce, payment, payjp, paypay, credit-card
Requires at least: 6.4
Tested up to: 6.X
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 8.0
WC tested up to: X.X
```

### 外部サービス開示（readme.txt 必須記載）

WordPress.org はプラグインが外部サービスに接続する場合、
readme.txt への明記と利用規約リンクを**義務付けている**。

```
== External Services ==

This plugin connects to PAY.JP (https://pay.jp) to process payments.
Data is transmitted when the customer completes checkout.
- Terms of Service: https://pay.jp/terms
- Privacy Policy: https://pay.jp/privacy

This plugin loads PAY.JP payments.js from the following URL on checkout pages:
  https://js.pay.jp/payments.js
This script is required for PCI-compliant card tokenization.
Card data is handled by PAY.JP and never passes through your server.
```

### JavaScript CDN について

WordPress.org ガイドラインは原則サードパーティ CDN を禁止しているが、
Stripe (`js.stripe.com`)・PayPal (`paypalobjects.com`) と同様、
**PCI 準拠のために決済業者の JS を CDN から読み込む行為は審査で認められている**。
readme.txt への開示を必ず行うこと。

### コードレビュー通過のためのチェックリスト

#### セキュリティ（必須）

- [ ] すべての `$_GET` / `$_POST` / `$_SERVER` を sanitize してから使用
  - テキスト: `sanitize_text_field()` / `wp_unslash()` と組み合わせ
  - URL: `esc_url_raw()`
  - 整数: `absint()` / `(int)`
- [ ] すべての出力をエスケープ
  - HTML: `esc_html()`
  - URL: `esc_url()`
  - 属性: `esc_attr()`
  - JS: `esc_js()`
  - 直接 HTML 出力: `wp_kses_post()` / `wp_kses()`
- [ ] フォーム送信に `wp_nonce_field()` + `wp_verify_nonce()`（管理画面のみ）
- [ ] 権限チェック: `current_user_can( 'manage_woocommerce' )` を管理画面 AJAX で確認
- [ ] Webhook トークンは `hash_equals()` で検証（タイミング攻撃対策）
- [ ] `$wpdb` を使う場合は必ず `$wpdb->prepare()`

#### ライセンス・コード品質

- [ ] GPL-2.0-or-later ライセンスヘッダーを全 PHP ファイルに記載
- [ ] 難読化コード禁止（minify は可、mangle 禁止）
- [ ] ソースコード（`src/`）を同梱すること（minified のみは不可）
- [ ] WordPress 同梱ライブラリを優先（jQuery は `wp_enqueue_script('jquery')`）
- [ ] `eval()` / `base64_decode()` をコード実行目的で使用しない

#### i18n（国際化）

- [ ] すべてのユーザー向け文字列を翻訳関数でラップ
  - `__( 'text', 'payjp-for-woocommerce' )`
  - `esc_html__( 'text', 'payjp-for-woocommerce' )`
  - `_e( 'text', 'payjp-for-woocommerce' )` など
- [ ] テキストドメインは `payjp-for-woocommerce`（プラグインスラッグと一致）
- [ ] `load_plugin_textdomain()` を `plugins_loaded` フックで呼び出す
- [ ] 変数を文字列に直接結合しない（`printf()` / `sprintf()` を使用）

#### プラグイン作法

- [ ] `defined( 'ABSPATH' ) || exit;` を全 PHP ファイルの先頭に記載
- [ ] `register_activation_hook()` / `register_deactivation_hook()` を必要に応じて設定
- [ ] `uninstall.php` でオプション削除（注文メタは削除しない）
- [ ] 過剰な admin notice を出さない
- [ ] `wp_head` / `wp_footer` を乱用しない
- [ ] データ保存に専用テーブルを使う場合は `dbDelta()` でマイグレーション

#### wordpress.org 審査でよく指摘される点

- [ ] `wp_remote_*` の戻り値を `is_wp_error()` で必ずチェック
- [ ] ハードコードされた URL がないこと（`plugins_url()` / `home_url()` を使用）
- [ ] 外部 API への接続は必ずユーザーの操作に起因するもの（定期自動送信は禁止）
- [ ] `readme.txt` の `Tested up to` を最新 WordPress に合わせる
- [ ] スクリーンショット・バナー・アイコンを `assets/` に準備

---

## コーディング規約

### PHP

- **WordPress Coding Standards 準拠**（PHPCS で自動チェック）
- PHPStan level 5 でエラーなし
- HPOS 必須:
  - `$order->get_meta()` / `$order->update_meta_data()` + `$order->save()`
  - `get_post_meta()` / `update_post_meta()` は **禁止**
  - オーダー検索は `wc_get_orders( [ 'meta_key' => ... ] )`
- `wp_remote_post()` / `wp_remote_get()` を使用（`curl` 直呼び出し禁止）
- 例外は `RuntimeException` で統一、ユーザー表示前に `esc_html()` を適用
- GPL ライセンスヘッダーを全ファイルに記載

### JavaScript

- `@wordpress/scripts` の ESLint 設定に準拠（`npm run lint:js`）
- payments.js はページ内で一度だけ初期化する
- エラー表示は `role="alert" aria-live="polite"` 付き要素に出力
- ソースコード（`src/`）を必ず同梱し、minified のみにしない

---

## 環境・ツール

### ローカル開発

```bash
npm run env:start        # WordPress + WooCommerce 起動（localhost:8888）
npm run env:stop         # 停止
npm run start            # JS ウォッチビルド
npm run build            # JS 本番ビルド
```

### コード品質

```bash
vendor/bin/phpcs --standard=phpcs.xml.dist .         # PHPCS チェック
vendor/bin/phpcs --standard=phpcs.xml.dist . --fix   # 自動修正
vendor/bin/phpstan analyse                            # PHPStan
npm run lint:js                                       # JS lint
npm run lint:css                                      # CSS lint
```

### PAY.JP テスト環境

- テスト API キー: PAY.JP ダッシュボード（テスト）から取得
- PayPay テストアカウント: 080-1111-5912 〜 080-1111-5921（10 アカウント）
- PayPay テスト上限: ¥100 / 回（テスト後に全額返金必須）
- Webhook テスト送信: PAY.JP ダッシュボード > Webhook > テスト送信

### PAY.JP v2 ドキュメント参照先

| リソース | URL |
|---------|-----|
| ガイド | https://docs.pay.jp/v2/guide |
| API リファレンス | https://docs.pay.jp/v2/api |
| LLM 向け全文 | https://docs.pay.jp/v2/llms-full.txt |
| 個別ページ MDX | 各ページ URL に `.mdx` を付与 |

---

## 今後の拡張候補（v2 以降）

- Checkout v2（外部リンク型）対応
- Apple Pay 対応
- カード保存（顧客への紐付け・再利用 / Setup Flow）
- 定期課金（Subscription）対応
- オーソリ管理画面（手動キャプチャ UI）
- 多言語対応（`.pot` ファイル生成 → GlotPress）
