# PAY.JP for WooCommerce — 開発計画書

---

## 進捗サマリー

| フェーズ | 内容 | ステータス | PR | マージ日 |
| --------- | ------ | ----------- | ----- | --------- |
| Phase 1 | プラグインスケルトン | ✅ 完了 | #3 | 2026-05-14 |
| Phase 2 | 統合設定画面 | ✅ 完了 | #4 | 2026-05-15 |
| Phase 3 | カード決済（埋め込み型） | ✅ 完了 | #5 | 2026-05-16 |
| Phase 4 | PayPay 決済（埋め込み型） | ✅ 完了 | #6 | 2026-05-16 |
| Phase 5 | Webhook ハンドラ | ✅ 完了 | #6 | 2026-05-16 |
| Phase 6 | 返金処理 | ✅ 完了 | — | 2026-05-17 |
| Phase 7 | Block Checkout 統合 | ✅ 完了 | — | 2026-05-17 |
| Phase 8 | カードトークン保存 | ✅ 完了 | #10 | 2026-05-17 |
| Phase 9 | WooCommerce Subscriptions 対応 | ✅ 完了 | — | 2026-05-28 |
| Phase 10 | 品質・テスト | 🔄 進行中 | — | — |

---

## プロジェクト概要

| 項目 | 内容 |
|------|------|
| プラグイン名 | PAY.JP for WooCommerce |
| スラッグ | `payjp-for-wc` |
| テキストドメイン | `payjp-for-wc` |
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

### カード追加機能（v1.0 対象）

| 機能 | 概要 |
|------|------|
| カードトークン保存 | Setup Flow でカードを登録し、WC Token API で管理。次回購入時にカード入力不要。|
| WooCommerce Subscriptions 対応 | 保存カード（`customer_id`）を使った自動定期課金。WooCommerce Subscriptions プラグインが必要。|

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
    require_once __DIR__ . '/gateways/payjp/payjp-for-wc.php';
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
payjp-for-wc.php                ← ブートストラップ・定数定義
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
  class-payjp-token-manager.php         ← WC Token API + Setup Flow 管理（カード保存・取得・削除）
  class-payjp-subscriptions.php         ← WooCommerce Subscriptions 定期支払いハンドラ
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

### 定数（`payjp-for-wc.php`）

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
| `_payjp_refund_processed_{id}` | string `'1'` | 処理済み返金 ID ごとに 1 エントリ（複数部分返金対応）|
| `_payjp_customer_id` | string | PAY.JP Customer ID (`cus_xxx`)。トークン保存・Subscriptions で使用。|
| `_payjp_payment_method_id` | string | 保存カードの PaymentMethod ID (`pm_xxx`)。|

---

## 開発フェーズ

### Phase 1: プラグインスケルトン ✅

**目標:** プラグインとして認識され、管理画面の「決済」タブに表示される状態にする。

- [x] `payjp-for-wc.php` — ヘッダー・定数・`class_exists` ガード・HPOS 宣言
- [x] `uninstall.php` — 削除時の設定クリア
- [x] `class-payjp-loader.php` — クラスロード・フック登録
- [x] `class-payjp-settings.php` — 共有設定管理
- [x] 各クラスファイルの空実装
- [x] `add_filter( 'woocommerce_payment_gateways', ... )` でゲートウェイ登録

**完了条件:** WooCommerce > 設定 > 決済 にカード・PayPay が表示される。

> ✅ 2026-05-14 完了 — PR #3 mainマージ済み

---

### Phase 2: 統合設定画面 ✅

**目標:** PAY.JP 設定画面から API キーと有効決済手段を設定できる。

- [x] `class-payjp-admin-settings-page.php` — WC Settings API を使った設定ページ
- [x] `payjp_settings` オプションへの保存・読み込み
- [x] `Payjp_Settings::get_public_key()` / `get_secret_key()` の test/live 自動切替
- [x] `is_available()` で `Payjp_Settings::is_method_enabled()` を確認
- [x] 統合設定画面 ↔ 個別ゲートウェイ設定の双方向同期
- [x] `.github/instructions/` Copilot カスタム指示追加
- [x] `scripts/copilot-review.sh` Copilot コメント取得スクリプト追加

**完了条件:** 設定を保存し、`Payjp_Settings::get()` で取得できる。

> ✅ 2026-05-15 完了 — PR #4 mainマージ済み

---

### Phase 3: カード決済（埋め込み型）

**目標:** テスト環境でカード決済が完了し、注文ステータスが「処理中」になる。

- [x] `Payjp_API` クラス完全実装
- [x] `payment_fields()`: Payment Flow 作成 → `wp_add_inline_script()` で client_secret を渡す
- [x] payments.js を CDN からエンキュー（`wp_enqueue_script`）
- [x] `checkout.js`: payments.js ウィジェットマウント・confirmPayment
- [x] `template_redirect` フック: return_url ハンドラ実装
- [x] API で Payment Flow を検証 → `payment_complete()` 呼び出し
- [x] `_payjp_payment_flow_id` をオーダーメタに保存

**完了条件:** テストカードで決済完了 → 注文ステータス「処理中」。

> ✅ 2026-05-16 完了 — PR #5 mainマージ済み

---

### Phase 4: PayPay 決済（埋め込み型）

**目標:** テスト PayPay アカウントで決済が完了し、注文ステータスが「処理中」になる。

- [x] `payment_fields()`: Payment Flow 作成（`payment_method_types: ['paypay']`）
- [x] `payment-method-paypay.js`: PayPay フォームマウント・confirmPayment
- [x] return_url ハンドラをカードと共通化

**完了条件:** PayPay テストアカウントで決済完了 → 注文ステータス「処理中」。

> ✅ 2026-05-16 完了 — PR #6 mainマージ済み

---

### Phase 5: Webhook ハンドラ

**目標:** PAY.JP からの Webhook を受信し、注文ステータスを権威ソースで更新する。

- [x] REST エンドポイント: `POST /wp-json/payjp/v1/webhook`
- [x] `hash_equals()` でトークン検証
- [x] `payment_flow.succeeded` → `payment_complete()`（冪等）
- [x] `payment_flow.payment_failed` → `update_status('failed')`
- [x] `refund.created` → 注文ノートに記録（`wc_create_refund()` は `process_refund()` 側で実施）

**完了条件:** PAY.JP ダッシュボードのテスト送信で注文ステータスが更新される。

> ✅ 2026-05-16 完了 — PR #6 mainマージ済み

---

### Phase 6: 返金処理 ✅

**目標:** WooCommerce 管理画面からの返金が PAY.JP に反映される。

- [x] カード: `process_refund()` → `POST /v2/refunds`（部分返金・全額返金対応）
- [x] PayPay: `supports` に `refunds` を含めず、手動対応とする

**完了条件:** カード注文に対して部分・全額返金が成功する。

> ✅ 2026-05-17 完了

---

### Phase 7: Block Checkout 統合 ✅

**目標:** WooCommerce Block Checkout でカード・PayPay が使用できる。

- [x] `Payjp_Blocks_Integration` クラス（`AbstractPaymentMethodType` 実装）
- [x] `woocommerce_blocks_payment_method_type_registration` フックで登録
- [x] `get_payment_method_data()` で JS に設定値（title/description/supports）を渡す
- [x] `payment-method-card.js` / `payment-method-paypay.js`: label + description 表示
- [x] 支払いフロー: Block Checkout → `process_payment()` → order-pay ページ(widget) → return URL

> **設計注記:** PAY.JP の `confirmPayment()` は常にリダイレクトするため、注文生成前に
> ウィジェットをブロックチェックアウトに埋め込むと order ID が不明になりリンク不可。
> redirect-to-order-pay アプローチを採用（Stripe 等の埋め込みとは異なる）。

> ✅ 2026-05-17 完了

**完了条件:** Gutenberg チェックアウトブロックでカード・PayPay が表示・決済できる。

---

### Phase 8: カードトークン保存

**目標:** ログイン済み顧客がカードを保存し、次回購入時にカード入力なしで決済できる。

- [x] `Payjp_Token_Manager` クラス実装
  - PAY.JP Customer 作成 (`POST /v2/customers`)
  - Setup Flow 作成 (`POST /v2/setup_flows`) → payments.js でカード登録（決済なし）
  - PaymentMethod を Customer に紐付け (`POST /v2/payment_methods/{id}/attach`)
  - `WC_Payment_Token_CC` を使った WooCommerce Token API 統合
  - `_payjp_customer_id` / `_payjp_payment_method_id` をユーザーメタ + オーダーメタに保存
- [x] `WC_Gateway_Payjp_Card` の `supports` に `'tokenization'`・`'add_payment_method'` を追加
- [x] `payment_fields()` に保存済みカード選択 UI を追加（WC 標準の `saved_payment_methods()` 利用）
- [x] 「このカードを保存する」チェックボックスの表示・処理
- [x] 保存カード選択時: `customer_id` + `confirm: true` で Payment Flow を即時作成・確定
- [x] マイアカウント > 支払い方法 からのカード追加（Setup Flow）・削除
- [x] `uninstall.php` にトークンデータのクリーンアップを追加

**完了条件:** ログイン済みユーザーがカードを保存し、次回購入時に選択して決済できる。マイアカウントでカード管理ができる。

> ✅ 2026-05-17 完了 — PR #10 mainマージ済み

---

### Phase 9: WooCommerce Subscriptions 対応

**目標:** WooCommerce Subscriptions を使った定期購入商品の初回決済・自動更新課金・支払い方法変更が動作する。

**前提:** Phase 8（カードトークン保存）が完了していること。WooCommerce Subscriptions プラグインがインストール済みであること。

- [x] `Payjp_Subscriptions` クラス実装
  - `class_exists( 'WC_Subscriptions' )` で存在チェック → 未インストール時は機能を無効化
  - `woocommerce_scheduled_subscription_payment_payjp_card` フックで定期支払い処理
  - サブスクリプション親注文の `_payjp_customer_id` を取得 → `customer_id` + `confirm: true` で自動課金
  - 失敗時は `update_status('failed')` → WCS_Retry_Manager が自動的にリトライをスケジュール
- [x] `WC_Gateway_Payjp_Card` の `supports` に全サブスクリプションケイパビリティを追加
- [x] 支払い方法変更: 保存カード選択 → `process_subscription_method_change()` で $0 注文を処理 → `on_payment_method_updated()` で PM ID を更新
- [x] Webhook `payment_flow.succeeded` / `payment_flow.payment_failed` → WCS が `payment_complete()` / `update_status('failed')` に自動フックしてサブスクリプションステータスを更新

**完了条件:** 定期購入商品の初回購入・自動更新課金・支払い方法変更が正常に動作する。

> ✅ 2026-05-28 完了

---

### Phase 10: 品質・テスト

- [x] PHPCS: `vendor/bin/phpcs` — 0 エラー・0 警告
- [x] PHPStan: level 5 でエラーなし
- [x] JS lint: `npm run lint:js` — エラーなし
- [x] CSS lint: `npm run lint:css` — エラーなし
- [x] wp-env + Playwright E2E テスト 15/15 PASS（`tests/e2e/phase10.spec.js`）
- [x] HPOS 有効時の動作確認（wp-env 環境で HPOS 有効・プラグイン競合なし確認済み）
- [x] Block Checkout / Classic Checkout の両方で PAY.JP 決済手段の表示確認済み
- [x] My Account > 支払い方法: カード追加フォーム（Setup Flow マウントポイント）表示確認済み
- [x] Webhook: 401/415/400/200 各レスポンスコード確認済み
- [x] セキュリティ監査: PHPCS/PHPStan + 手動チェック全項目 PASS（入力サニタイズ・出力エスケープ・nonce・capability・hash_equals・HPOS・PCI DSS）
- [x] `readme.txt` 作成（WordPress.org 必須形式・External Services セクション含む）
- [x] `README.md` 作成（英語 / 機能・開発環境・コントリビュートガイド・テスト手順）
- [x] `README-JA.md` 作成（日本語版）
- [x] `.github/workflows/release.yml` 作成（タグ push → ZIP 生成 → GitHub Release 添付）
- [x] `.distignore` 作成（開発用ファイルをリリース ZIP から除外）
- [x] 管理設定画面に Webhook URL 表示と設定手順を追加
- [ ] 同梱テスト: Japanized for WooCommerce に組み込んで二重読み込みがないことを確認
- [ ] カードトークン保存: 実際のテストカードで保存・再利用・削除の動作確認
- [ ] WooCommerce Subscriptions: 定期購入の初回・自動更新・支払い方法変更の動作確認

---

## WordPress.org 公開要件

### readme.txt 必須項目

```
=== PAY.JP for WooCommerce ===
Contributors: shohei.tanaka
Tags: woocommerce, payment, payjp, paypay, credit-card
Requires at least: 6.4
Tested up to: 6.X
Requires PHP: 8.3
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

- [x] すべての `$_GET` / `$_POST` / `$_SERVER` を sanitize してから使用
  - テキスト: `sanitize_text_field()` / `wp_unslash()` と組み合わせ ✅
  - 整数: `absint()` ✅
- [x] すべての出力をエスケープ（`esc_html()` / `esc_attr()` / `esc_url()` / `wp_kses_post()`）✅
- [x] フォーム送信に nonce 検証（WooCommerce が `woocommerce-settings` nonce を代行。チェックアウトも WC が検証）✅
- [x] 権限チェック: `current_user_can( 'manage_woocommerce' )` を管理画面保存処理で確認 ✅
- [x] Webhook トークンは `hash_equals()` で検証（タイミング攻撃対策）✅
- [x] `$wpdb` 直接クエリなし（WC / WP API のみ使用）✅

#### ライセンス・コード品質

- [x] GPL-2.0-or-later ライセンスヘッダーを全 PHP ファイルに記載（`@license GPL-2.0-or-later` を全 14 クラスファイルの DocBlock に追加済み）✅
- [x] 難読化コード禁止（minify は可、mangle 禁止）✅
- [x] ソースコード（`src/`）を同梱すること（minified のみは不可）✅
- [x] WordPress 同梱ライブラリを優先（`wp_enqueue_script()` 使用）✅
- [x] `eval()` / `base64_decode()` をコード実行目的で使用しない ✅

#### i18n（国際化）

- [x] すべてのユーザー向け文字列を翻訳関数でラップ（`__()` / `esc_html__()` 等）✅
- [x] テキストドメインは `payjp-for-wc`（プラグインスラッグと一致）✅
- [x] `load_plugin_textdomain()` を `plugins_loaded` フックで呼び出す ✅（`class-payjp-loader.php`）
- [x] 変数を文字列に直接結合しない（`sprintf()` / `printf()` を使用）✅

#### プラグイン作法

- [x] `defined( 'ABSPATH' ) || exit;` を全 PHP ファイルの先頭に記載 ✅
- [x] `register_activation_hook()` / `register_deactivation_hook()` — 有効化時の処理不要につき N/A ✅
- [x] `uninstall.php` でオプション削除（注文メタは削除しない）✅
- [x] 過剰な admin notice を出さない ✅
- [x] `wp_head` / `wp_footer` を乱用しない ✅
- [x] カスタムテーブルなし → `dbDelta()` 不要 ✅

#### wordpress.org 審査でよく指摘される点

- [x] `wp_remote_*` の戻り値を `is_wp_error()` で必ずチェック ✅（`class-payjp-api.php`）
- [x] ハードコードされた URL がないこと（`plugins_url()` / `home_url()` / `rest_url()` を使用）✅
- [x] 外部 API への接続は必ずユーザーの操作に起因するもの（Webhook 受信は外部起点だが受動的な受け口のため問題なし）✅
- [x] `readme.txt` の `Tested up to` を最新 WordPress に合わせる ✅（7.0）
- [x] スクリーンショット・バナー・アイコンを `.wordpress-org/` に準備（icon-256x256, icon-128x128, banner-1544x500, banner-772x250, screenshot-1 を PHP GD で生成済み。SVN の `/assets/` にアップロードすること）✅

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
vendor/bin/phpcs --standard=phpcs.xml .         # PHPCS チェック
vendor/bin/phpcs --standard=phpcs.xml . --fix   # 自動修正
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
| --------- | ----- |
| ガイド | https://docs.pay.jp/v2/guide |
| API リファレンス | https://docs.pay.jp/v2/api |
| LLM 向け全文 | https://docs.pay.jp/v2/llms-full.txt |
| 個別ページ MDX | 各ページ URL に `.mdx` を付与 |

---

## 今後の拡張候補（v2 以降）

- Checkout v2（外部リンク型）対応
- Apple Pay 対応
- オーソリ管理画面（手動キャプチャ UI）
- 多言語対応（`.pot` ファイル生成 → GlotPress）
