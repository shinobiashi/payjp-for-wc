# PAY.JP for WooCommerce — 開発計画書

> このファイルは CLAUDE.md として使用することを想定した開発計画書です。
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
| 対象 PHP | 8.0+ |
| 決済 API | PAY.JP v2 |
| ライセンス | GPL-2.0-or-later |

### 実装する決済手段

| 決済手段 | Gateway ID | 統合方式 |
|---------|-----------|---------|
| クレジットカード | `payjp_card` | Payment Widgets（埋め込み型）|
| PayPay | `payjp_paypay` | Payment Widgets（埋め込み型）|

両手段とも **PAY.JP v2 Payment Flow API** を使用する。
外部リンク型（Checkout v2）は将来対応として保留。

---

## アーキテクチャ方針

### バックエンド

```
payjp-for-woocommerce.php          ← ブートストラップ・定数定義
includes/
  class-payjp-api.php              ← PAY.JP API ラッパー（wp_remote_post ベース）
  class-wc-gateway-payjp.php       ← 抽象基底クラス（共通処理）
  class-wc-gateway-payjp-card.php  ← カード決済ゲートウェイ
  class-wc-gateway-payjp-paypay.php ← PayPay 決済ゲートウェイ
  class-payjp-webhook-handler.php  ← Webhook 受信・検証・ルーティング
  class-payjp-blocks-integration.php ← Block Checkout 統合（PHP 側）
templates/
  return.php                        ← payments.js リダイレクト後の受け口
```

### フロントエンド

```
src/blocks/checkout/
  index.js                         ← registerPaymentMethod（カード・PayPay）
  payment-method-card.js           ← カード決済 React コンポーネント
  payment-method-paypay.js         ← PayPay 決済 React コンポーネント
src/admin/settings/
  index.js                         ← 管理画面 JS（拡張時に使用）
build/                             ← コンパイル済み（gitignore）
```

### 決済フロー（埋め込み型）

```
[チェックアウト画面表示]
  └─ PHP: payment_fields() → Payment Flow 作成 → client_secret 取得
  └─ JS: payments.js ウィジェットをマウント

[顧客が支払いボタンを押す]
  └─ JS: widgets.confirmPayment({ return_url }) を実行
  └─ PAY.JP: 3DS 等を処理後、return_url にリダイレクト

[return_url に戻ってくる]
  └─ PHP: payment_flow_id でサーバーサイド検証（API 直接確認）
  └─ PHP: status === 'succeeded' → payment_complete()

[Webhook 受信（非同期・権威ソース）]
  └─ PHP: X-Payjp-Webhook-Token を hash_equals() で検証
  └─ PHP: payment_flow.succeeded → payment_complete()（冪等）
```

---

## ファイル詳細設計

### 定数（`payjp-for-woocommerce.php`）

```php
define( 'PAYJP_VERSION',     '1.0.0' );
define( 'PAYJP_PLUGIN_FILE', __FILE__ );
define( 'PAYJP_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'PAYJP_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'PAYJP_API_BASE',    'https://api.pay.jp/v2' );
```

### PAY.JP API ラッパー（`class-payjp-api.php`）

責務: PAY.JP v2 REST API との通信を一元管理する。

- `__construct( string $secret_key )` — シークレットキーを受け取る
- `post( string $endpoint, array $body ): array` — POST リクエスト
- `get( string $endpoint ): array` — GET リクエスト
- エラー時は `RuntimeException` を throw（メッセージはユーザー表示可能な文言）
- `wp_remote_post()` / `wp_remote_get()` を使用（`curl` 直呼び出し禁止）
- 全リクエストに `timeout: 30` を設定

### 抽象基底クラス（`class-wc-gateway-payjp.php`）

責務: カード・PayPay の共通処理を集約する。

共通メソッド:
- `init_payjp_settings()` — API キー・テストモード設定読み込み
- `get_api()`: `Payjp_API` インスタンスを返す（遅延初期化）
- `get_return_url_base()` — return_url のベース URL
- `find_order_by_flow_id( string $flow_id ): ?WC_Order`

### カード決済クラス（`class-wc-gateway-payjp-card.php`）

実装メソッド:
- `payment_scripts()` — payments.js + checkout.js をエンキュー
- `payment_fields()` — Payment Flow 作成 → client_secret を JS に渡す
- `process_payment( $order_id )` — pending ステータスにして return
- `process_refund( $order_id, $amount, $reason )` — 返金 API 呼び出し
- `handle_return()` — return_url 受信 → API 検証 → ステータス更新

追加設定フィールド:
- `capture_method` — automatic / manual（デフォルト: automatic）

### PayPay 決済クラス（`class-wc-gateway-payjp-paypay.php`）

実装メソッド:
- `payment_scripts()` — payments.js + checkout.js をエンキュー
- `payment_fields()` — Payment Flow 作成（paypay）→ client_secret を JS に渡す
- `process_payment( $order_id )` — pending ステータスにして return
- `handle_return()` — return_url 受信 → API 検証 → ステータス更新

PayPay 固有制約:
- 返金は Webhook または管理画面から手動対応（`supports` に `refunds` を含めない）
- テスト時は ¥100 以下・全額返金必須

### Webhook ハンドラ（`class-payjp-webhook-handler.php`）

- REST エンドポイント: `POST /wp-json/payjp/v1/webhook`
- トークン検証: `hash_equals()` で timing-safe 比較
- 処理イベント:
  - `payment_flow.succeeded` → `payment_complete()`
  - `payment_flow.payment_failed` → `update_status('failed')`
  - `refund.created` → WC 返金レコード作成
- 冪等性: `$order->is_paid()` チェックで二重処理防止

### Block Checkout 統合（`class-payjp-blocks-integration.php`）

`AbstractPaymentMethodType` を実装する。

- `get_payment_method_data()` — JS に渡す設定値を返す
  ```php
  return [
      'title'    => $this->gateway->title,
      'supports' => $this->gateway->supports,
  ];
  ```
- `get_payment_method_script_handles()` — `build/blocks/checkout.js` を返す

---

## 開発フェーズ

### Phase 1: プラグインスケルトン

**目標:** プラグインとして認識され、管理画面の「決済」タブに表示される状態にする。

- [ ] `payjp-for-woocommerce.php` — プラグインヘッダー・定数・HPOS 宣言
- [ ] `includes/` ディレクトリと各クラスファイルの空実装
- [ ] `add_filter( 'woocommerce_payment_gateways', ... )` でゲートウェイ登録
- [ ] `plugins_loaded` でクラスロード

**完了条件:** WooCommerce > 設定 > 決済 にカード・PayPay が表示される。

---

### Phase 2: 設定画面

**目標:** API キーと基本設定を管理画面から保存できる状態にする。

設定フィールド（カード・PayPay 共通）:
- `enabled` — 有効/無効
- `title` — 決済名（表示）
- `description` — 決済説明
- `test_mode` — テストモード toggle
- `test_public_key` / `test_secret_key`
- `live_public_key` / `live_secret_key`
- `webhook_secret` — Webhook トークン（PAY.JP ダッシュボードから取得）

カード専用:
- `capture_method` — `automatic` / `manual`（デフォルト: automatic）

**完了条件:** 設定を保存し、`get_option()` で取得できる。

---

### Phase 3: カード決済（埋め込み型）

**目標:** テスト環境でカード決済が完了し、注文ステータスが「処理中」になる。

実装ステップ:
1. `Payjp_API` クラス実装（`post()` / `get()`）
2. `payment_fields()`: Payment Flow 作成 → `wp_add_inline_script()` で client_secret を渡す
3. `checkout.js` / `payment-method-card.js`: payments.js ウィジェットマウント・confirmPayment
4. `template_redirect` フック: return_url ハンドラ実装
5. API で Payment Flow を検証 → `payment_complete()` 呼び出し
6. `_payjp_payment_flow_id` をオーダーメタに保存

**完了条件:** テストカードで決済完了 → 注文ステータス「処理中」。

---

### Phase 4: PayPay 決済（埋め込み型）

**目標:** テスト PayPay アカウントで決済が完了し、注文ステータスが「処理中」になる。

実装ステップ:
1. `payment_fields()`: Payment Flow 作成（`payment_method_types: ['paypay']`）
2. `payment-method-paypay.js`: PayPay フォームマウント・confirmPayment
3. return_url ハンドラ（カードと共通化可能）

**完了条件:** PayPay テストアカウントで決済完了 → 注文ステータス「処理中」。

---

### Phase 5: Webhook ハンドラ

**目標:** PAY.JP からの Webhook を受信し、注文ステータスを信頼できるソースで更新する。

実装ステップ:
1. REST エンドポイント登録（`rest_api_init`）
2. トークン検証（`hash_equals()`）
3. `payment_flow.succeeded` → `payment_complete()`（冪等）
4. `payment_flow.payment_failed` → `update_status('failed')`
5. `refund.created` → `wc_create_refund()`

**完了条件:** PAY.JP ダッシュボードからテスト Webhook 送信 → 注文ステータスが更新される。

---

### Phase 6: 返金処理

**目標:** WooCommerce 管理画面から返金操作が PAY.JP に反映される。

- カード: `process_refund()` → `POST /v2/refunds`
- PayPay: PAY.JP ダッシュボードから手動対応（`supports` に `refunds` 含めず）

**完了条件:** カード注文に対して部分返金・全額返金が成功する。

---

### Phase 7: Block Checkout 統合

**目標:** WooCommerce Block Checkout でカード・PayPay が使用できる。

実装ステップ:
1. `Payjp_Blocks_Integration` クラス実装
2. `woocommerce_blocks_payment_method_type_registration` フックで登録
3. `get_payment_method_data()` で JS に設定値を渡す
4. `payment-method-card.js` / `payment-method-paypay.js`: payments.js ウィジェット統合
5. Block Checkout の `onPaymentSetup` フックで client_secret を WC に渡す方法の検討

**完了条件:** Gutenberg ブロックエディタのチェックアウトブロックでカード・PayPay が表示・決済できる。

---

### Phase 8: 品質・テスト

- [ ] `vendor/bin/phpcs --standard=phpcs.xml.dist .` — 0 エラー・0 警告
- [ ] `vendor/bin/phpstan analyse` — level 5 でエラーなし
- [ ] `npm run lint:js` — エラーなし
- [ ] wp-env でのエンドツーエンド手動テスト（カード・PayPay・Webhook・返金）
- [ ] HPOS 有効時の動作確認（WooCommerce > 設定 > 詳細 > 機能）

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

### JavaScript

- `@wordpress/scripts` の ESLint 設定に準拠（`npm run lint:js`）
- payments.js はページ内で一度だけ初期化する
- エラー表示は `role="alert" aria-live="polite"` 付き要素に出力

### セキュリティチェックリスト

- [ ] シークレットキーは `get_option()` 経由のみ、ハードコード禁止
- [ ] Webhook トークンは `hash_equals()` で検証（timing-safe）
- [ ] 全ての出力に適切なエスケープ（`esc_html()` / `esc_url()` / `esc_js()` / `esc_attr()`）
- [ ] 管理 AJAX には `check_admin_referer()` または `wp_verify_nonce()`
- [ ] Payment Flow の結果はサーバーサイド API 呼び出しで検証（リダイレクトパラメータを信頼しない）
- [ ] PCI DSS: カード情報はサーバーに届かない（payments.js トークン化のみ）

---

## 主要メタキー

| メタキー | 型 | 説明 |
|---------|-----|------|
| `_payjp_payment_flow_id` | string | Payment Flow ID (`pflw_xxx`) |
| `_payjp_client_secret` | string | client_secret（参照用）|
| `_payjp_payment_method` | string | `card` または `paypay` |
| `_payjp_refund_id` | string | 返金 ID（最新）|

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
vendor/bin/phpcs --standard=phpcs.xml.dist .    # PHPCS
vendor/bin/phpcs --standard=phpcs.xml.dist . --fix  # 自動修正
vendor/bin/phpstan analyse                       # PHPStan
npm run lint:js                                  # JS lint
npm run lint:css                                 # CSS lint
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
- カード保存（顧客への紐付け・再利用）
- 定期課金（Subscription）対応
- オーソリ管理画面（手動キャプチャ UI）
- WooCommerce Payments の互換性確認
