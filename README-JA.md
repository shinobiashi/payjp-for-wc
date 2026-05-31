# PAY.JP for WooCommerce

[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php)](https://www.php.net/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0%2B-96588A?logo=woocommerce)](https://woocommerce.com/)
[![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-21759B?logo=wordpress)](https://wordpress.org/)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2%2B-blue)](https://www.gnu.org/licenses/gpl-2.0.html)
[![PHPCS](https://img.shields.io/badge/PHPCS-WordPress%20Standards-green)](https://github.com/WordPress/WordPress-Coding-Standards)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%205-orange)](https://phpstan.org/)

> 英語版ドキュメント: [README.md](README.md)

WooCommerce 向けの PAY.JP v2 決済プラグインです。クレジットカード決済と PayPay 決済を、PAY.JP v2 Payment Widgets（埋め込み型）で提供します。ブロックチェックアウト・クラシックチェックアウトの両方に対応し、HPOS（High-Performance Order Storage）および WooCommerce Subscriptions による定期課金もサポートします。

---

## 目次

- [機能一覧](#機能一覧)
- [動作要件](#動作要件)
- [エンドユーザー向けインストール](#エンドユーザー向けインストール)
- [開発環境のセットアップ](#開発環境のセットアップ)
- [アーキテクチャ](#アーキテクチャ)
- [コードの品質管理](#コードの品質管理)
- [テスト](#テスト)
- [コントリビューション（貢献方法）](#コントリビューション貢献方法)
- [セキュリティ](#セキュリティ)
- [ライセンス](#ライセンス)

---

## 機能一覧

| 機能 | 説明 |
|------|------|
| クレジットカード決済 | PAY.JP v2 Payment Widgets による埋め込み型カード決済 |
| PayPay 決済 | PAY.JP v2 Payment Widgets による埋め込み型 PayPay 決済 |
| カード保存 | PAY.JP Setup Flow + WooCommerce Token API によるカード登録・管理 |
| 定期課金 | WooCommerce Subscriptions を利用したサブスクリプション決済 |
| ブロックチェックアウト | WooCommerce ブロックエディタ対応（React コンポーネント実装） |
| クラシックチェックアウト | 従来のショートコード型チェックアウトにも完全対応 |
| Webhook 処理 | `payment_flow.*`・`refund.*` イベントの受信と処理 |
| 全額・部分返金 | WooCommerce 管理画面から全額および部分返金に対応 |
| HPOS 対応 | High-Performance Order Storage に完全対応 |
| マルチサイト対応 | WordPress マルチサイトネットワークでの動作をサポート |

---

## 動作要件

| 項目 | バージョン |
|------|-----------|
| WordPress | 6.4 以上 |
| WooCommerce | 8.0 以上 |
| PHP | 8.3 以上 |
| PAY.JP アカウント | v2 API キー（公開鍵・秘密鍵）が必要 |

オプション:

- **WooCommerce Subscriptions** — 定期課金機能を使用する場合に必要
- **Composer** — 開発時の依存関係管理
- **Node.js 20+ / npm** — JS ビルドおよび開発環境の起動

---

## エンドユーザー向けインストール

### 配布形態

このプラグインは以下の 2 種類の方法で入手できます。

1. **wordpress.org スタンドアロン版** — WordPress.org プラグインディレクトリからインストール
2. **Japanized for WooCommerce 同梱版** — Japanized for WooCommerce プラグインにバンドルされた形で提供

### WordPress 管理画面からのインストール

1. WordPress 管理画面 → **プラグイン → 新規追加** に移動
2. 検索ボックスに `PAY.JP for WooCommerce` と入力
3. **今すぐインストール** → **有効化** をクリック

### 手動インストール

1. [リリースページ](https://github.com/artws/payjp-for-wc/releases) から最新の ZIP をダウンロード
2. WordPress 管理画面 → **プラグイン → 新規追加 → プラグインのアップロード** で ZIP をアップロード
3. **有効化** をクリック

### 初期設定

1. **WooCommerce → 設定 → 決済** に移動
2. **PAY.JP 設定** タブを開く
3. 以下を入力して保存:
   - **公開鍵**（`pk_live_xxx` または `pk_test_xxx`）
   - **秘密鍵**（`sk_live_xxx` または `sk_test_xxx`）
   - **Webhook シークレット**（PAY.JP ダッシュボードで発行）
   - テストモードの ON/OFF
4. 利用する決済手段（カード・PayPay）を個別に有効化

#### Webhook URL の設定

PAY.JP ダッシュボード → **Webhooks** に以下の URL を登録してください:

```
https://your-store.example.com/wp-json/payjp/v2/webhook
```

受信するイベントとして少なくとも以下を選択してください:

- `payment_flow.succeeded`
- `payment_flow.payment_failed`
- `refund.created`

---

## 開発環境のセットアップ

### リポジトリのクローン

```bash
git clone https://github.com/artws/payjp-for-wc.git
cd payjp-for-wc
```

### 依存関係のインストール

```bash
# PHP 依存関係
composer install

# JS 依存関係
npm install
```

### ローカル WordPress 環境の起動

このプラグインは `@wordpress/env` を使用したローカル環境を提供しています。

```bash
npm run env:start
```

起動後、以下の URL でアクセスできます:

| URL | 説明 |
|-----|------|
| `http://localhost:8888` | WordPress フロントエンド |
| `http://localhost:8888/wp-admin` | 管理画面（admin / password） |

環境の停止:

```bash
npm run env:stop
```

### JS ビルド

```bash
# 本番ビルド（build/ ディレクトリに出力）
npm run build

# 開発中のウォッチビルド（ファイル変更を自動検知）
npm run start
```

---

## アーキテクチャ

### ファイル構成

```
payjp-for-wc.php                          ← ブートストラップ・定数定義
uninstall.php                             ← プラグイン削除時のデータ削除
includes/
  class-payjp-loader.php                  ← クラスロード・フック登録
  class-payjp-settings.php               ← 共有設定マネージャー（API キー等）
  class-payjp-api.php                    ← PAY.JP API ラッパー（wp_remote_* 使用）
  class-wc-gateway-payjp.php             ← 抽象基底クラス（共通処理）
  class-wc-gateway-payjp-card.php        ← カード決済ゲートウェイ
  class-wc-gateway-payjp-paypay.php      ← PayPay 決済ゲートウェイ
  class-payjp-webhook-handler.php        ← Webhook 受信・検証・ルーティング
  class-payjp-token-manager.php          ← WC Token API + Setup Flow 管理
  class-payjp-subscriptions.php          ← WooCommerce Subscriptions 対応
  class-payjp-blocks-integration*.php    ← ブロックチェックアウト（PHP 側）
  class-payjp-admin-settings-page.php    ← 統合設定画面
templates/
  return.php                             ← payments.js リダイレクト後の受け口
src/
  blocks/checkout/
    index.js                             ← registerPaymentMethod（カード・PayPay）
    payment-method-card.js               ← カード決済 React コンポーネント
    payment-method-paypay.js             ← PayPay 決済 React コンポーネント
  admin/settings/index.js                ← 管理画面 JS
build/                                   ← コンパイル済みファイル（git 管理外）
```

### 定数

`payjp-for-wc.php` で以下の定数を定義しています。`defined() || define()` パターンは Japanized for WooCommerce への同梱時の二重読み込みを防ぐために必須です。

```php
defined( 'PAYJP_FOR_WC_VERSION' ) || define( 'PAYJP_FOR_WC_VERSION', '1.0.0' );
defined( 'PAYJP_FOR_WC_FILE' )    || define( 'PAYJP_FOR_WC_FILE',    __FILE__ );
defined( 'PAYJP_FOR_WC_DIR' )     || define( 'PAYJP_FOR_WC_DIR',     plugin_dir_path( __FILE__ ) );
defined( 'PAYJP_FOR_WC_URL' )     || define( 'PAYJP_FOR_WC_URL',     plugin_dir_url( __FILE__ ) );
defined( 'PAYJP_API_BASE' )       || define( 'PAYJP_API_BASE',       'https://api.pay.jp/v2' );
```

### オーダーメタキー

| メタキー | 型 | 説明 |
|----------|----|------|
| `_payjp_payment_flow_id` | string | Payment Flow ID（`pflw_xxx`） |
| `_payjp_payment_method` | string | `card` または `paypay` |
| `_payjp_capture_method` | string | `automatic` または `manual` |
| `_payjp_refund_processed_{id}` | string `'1'` | 処理済み返金 ID ごとのフラグ（部分返金対応） |
| `_payjp_customer_id` | string | PAY.JP Customer ID（カード保存・Subscriptions 用） |
| `_payjp_payment_method_id` | string | 保存カードの PaymentMethod ID |

> **HPOS 必須**: オーダーメタの読み書きは必ず `$order->get_meta()` / `$order->update_meta_data()` + `$order->save()` を使用してください。`get_post_meta()` / `update_post_meta()` は禁止です。

### 決済フロー概要

```
チェックアウト画面
  └─ payments.js（CDN: https://js.pay.jp/payments.js）読み込み
       └─ Payment Widgets 表示（カード入力 / PayPay QR）
            └─ PAY.JP へトークン送信
                 └─ PHP: Payment Flow API 呼び出し（wp_remote_post）
                      └─ PAY.JP Webhook → class-payjp-webhook-handler.php
                           └─ WooCommerce 注文ステータス更新
```

> payments.js を CDN から読み込むのは PCI DSS 準拠のためです。Stripe の `js.stripe.com` と同等のパターンであり、WordPress.org 審査でも認められています。`readme.txt` の `== External Services ==` セクションへの開示が必要です。

---

## コードの品質管理

PHP ファイルを新規作成・編集した後は、コミット前に以下を必ず実行してください。

### PHPCS（WordPress コーディング規約）

```bash
# チェック実行
vendor/bin/phpcs --standard=phpcs.xml .

# 自動修正（修正可能なもののみ）
vendor/bin/phpcbf --standard=phpcs.xml .

# 自動修正後に再チェック
vendor/bin/phpcs --standard=phpcs.xml .
```

エラー・警告が **0 件** であることを確認してください。

### PHPStan（静的解析 level 5）

```bash
vendor/bin/phpstan analyse --memory-limit=1G
```

エラーが **0 件** であることを確認してください。

### JS Lint

```bash
npm run lint:js
npm run lint:css
```

### 手動チェックリスト

ツールでは検出できない以下の項目を自分でチェックしてください:

| # | 項目 | 確認ポイント |
|---|------|------------|
| 1 | ファイルヘッダー | `defined( 'ABSPATH' ) \|\| exit;` が先頭にあるか。GPL ヘッダーと `@package` があるか |
| 2 | DocBlock | 全クラス・メソッド・プロパティに PHPDoc があるか。`@param`・`@return`・`@throws` の漏れはないか |
| 3 | i18n | ユーザー向け文字列がすべて `__( 'text', 'payjp-for-wc' )` 等でラップされているか |
| 4 | 出力エスケープ | `echo` する変数に `esc_html()`・`esc_attr()`・`esc_url()` が付いているか |
| 5 | 入力サニタイズ | `$_POST`・`$_GET` は `wp_unslash()` + `sanitize_*()` + 型ガードがあるか |
| 6 | Webhook 検証 | トークン検証は `hash_equals()` のみか（`===` / `strcmp()` は不可） |
| 7 | HPOS | `$order->get_meta()` / `update_meta_data()` を使い `get_post_meta()` を使っていないか |
| 8 | HTTP リクエスト | `wp_remote_*` を使い `curl` を直接呼んでいないか。`is_wp_error()` を確認しているか |
| 9 | Yoda 条件 | 定数・リテラルを左辺に書いているか（`'yes' === $var`、`null === $x`） |
| 10 | クラスガード | `class_exists()` で二重定義を防いでいるか |

---

## テスト

### ユニットテスト

```bash
vendor/bin/phpunit
```

### E2E テスト（Playwright）

E2E テストの実行にはローカル WordPress 環境が必要です。

```bash
# 1. 環境を起動
npm run env:start

# 2. E2E テストを実行
npx playwright test
```

テスト結果は `test-results/` ディレクトリに出力されます。

### PayPay テスト

PAY.JP が提供するテスト用 PayPay アカウントを使用してください:

| 項目 | 値 |
|------|-----|
| テストアカウント | 080-1111-5912 〜 080-1111-5921（10 アカウント） |
| 1 回あたりの上限 | ¥100 |
| テスト後の処理 | 全額返金必須 |

### Webhook テスト

PAY.JP ダッシュボード → **Webhooks** → **テスト送信** からイベントを手動送信できます。

---

## コントリビューション（貢献方法）

バグ報告・機能要望・プルリクエストを歓迎します。

### ワークフロー

1. このリポジトリをフォーク
2. フィーチャーブランチを作成:
   ```bash
   git checkout -b feature/my-feature
   ```
3. 変更を加える（[WordPress コーディング規約](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)に従うこと）
4. 品質チェックを実行し、すべてパスさせる:
   ```bash
   vendor/bin/phpcs --standard=phpcs.xml .
   vendor/bin/phpstan analyse --memory-limit=1G
   vendor/bin/phpunit
   npm run lint:js
   npm run lint:css
   ```
5. `main` ブランチに対してプルリクエストを作成

### プルリクエストの要件

プルリクエストがマージされるためには、以下の CI チェックがすべて通過する必要があります:

| チェック | 要件 |
|---------|------|
| PHPCS | エラー 0 件 |
| PHPStan level 5 | エラー 0 件 |
| PHPUnit | 全テスト合格 |
| JS lint | エラー 0 件 |
| 新機能 | テストコードを含めること |

### コーディング規約

#### PHP

- **WordPress Coding Standards** 準拠（`vendor/bin/phpcs` でゼロエラー必須）
- **PHPStan level 5** でエラーなし
- **PHP 8.3+** の機能を積極的に活用（共変戻り値型など）
- 全 PHP ファイルの先頭: `defined( 'ABSPATH' ) || exit;`
- 全ファイルに GPL-2.0-or-later ライセンスヘッダーを記載
- HTTP リクエストは `wp_remote_post()` / `wp_remote_get()` のみ使用（`curl` 直呼び出し禁止）
- `wp_remote_*` の戻り値は必ず `is_wp_error()` でチェック
- Yoda 条件式: リテラル・定数を左辺に記述（`'yes' === $var`）
- 例外は `RuntimeException` で統一
- HPOS: `$order->get_meta()` / `update_meta_data()` を使用し、`get_post_meta()` は禁止
- 定数定義: `defined() || define()` パターン（二重読み込み防止のため必須）

#### JavaScript

- `@wordpress/scripts` の ESLint 設定準拠
- payments.js はページ内で一度だけ初期化
- エラー表示は `role="alert" aria-live="polite"` 付き要素に出力
- `src/` ディレクトリを必ず同梱（minified のみは WordPress.org 審査で NG）

#### i18n

- テキストドメイン: `payjp-for-wc`
- `load_plugin_textdomain()` を `plugins_loaded` フックで呼び出す
- 変数を文字列に直接結合しない → `sprintf()` / `printf()` を使用

---

## セキュリティ

### 入力・出力

| 処理 | 使用する関数・方法 |
|------|-----------------|
| テキスト入力のサニタイズ | `sanitize_text_field()` + `wp_unslash()` |
| URL 入力のサニタイズ | `esc_url_raw()` |
| 整数のサニタイズ | `absint()` |
| テキスト出力エスケープ | `esc_html()` |
| 属性値の出力エスケープ | `esc_attr()` |
| URL の出力エスケープ | `esc_url()` |
| HTML コンテンツ | `wp_kses_post()` |

### その他のセキュリティ要件

- **Webhook 検証**: タイミング攻撃対策のため `hash_equals()` のみを使用（`===` / `strcmp()` 禁止）
- **管理画面 AJAX**: `wp_verify_nonce()` + `current_user_can( 'manage_woocommerce' )` の両方が必須
- **DB クエリ**: `$wpdb->prepare()` 必須
- **REST API**: `permission_callback` の設定が必須
- **Payment Flow ID**: サーバーサイドで PAY.JP API を通じて検証（クライアントからの値を無条件に信頼しない）
- **PCI DSS**: カード番号・CVV をログに出力しない。機密情報には `mask_sensitive()` を実装済み

セキュリティの脆弱性を発見された場合は、公開 Issue ではなく [shohei.t@artws.info](mailto:shohei.t@artws.info) までメールでご報告ください。

---

## 外部サービス

このプラグインは以下の外部サービスに接続します:

| サービス | URL | 用途 |
|---------|-----|------|
| PAY.JP API | `https://api.pay.jp/v2` | 決済処理・返金・カード管理 |
| PAY.JP payments.js | `https://js.pay.jp/payments.js` | PCI 準拠の Payment Widgets |

PAY.JP の[利用規約](https://pay.jp/terms)および[プライバシーポリシー](https://pay.jp/privacy)をご確認ください。

---

## ライセンス

GPL-2.0-or-later — 詳細は [LICENSE](LICENSE) ファイルを参照してください。

---

## 作者

**田中昌平 / Artisan Workshop**
- Web: [https://artws.info](https://artws.info)
- PAY.JP: [https://pay.jp](https://pay.jp)

---

## 関連リンク

| リソース | URL |
|---------|-----|
| PAY.JP v2 ガイド | https://docs.pay.jp/v2/guide |
| PAY.JP v2 API リファレンス | https://docs.pay.jp/v2/api |
| PAY.JP v2 LLM 向け全文 | https://docs.pay.jp/v2/llms-full.txt |
| WordPress コーディング規約 | https://developer.wordpress.org/coding-standards/ |
| WooCommerce 開発者ドキュメント | https://developer.woocommerce.com/ |
