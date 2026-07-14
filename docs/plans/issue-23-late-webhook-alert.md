# Issue #23: 遅延 Webhook（確定済み注文への決済成功通知）の可視化 — 実装プラン

> **GitHub Issue**: https://github.com/shinobiashi/payjp-for-wc/issues/23
> **作業ブランチ**: `feature/issue-23-late-webhook-alert`（最新の main から切る）
> **PR 本文に `Fixes #23` を含めること**（merge 時に自動クローズ）

> **目的**: キャンセル済みなど「もう支払いを受け付けない状態」の注文に対して
> PAY.JP から `payment_flow.succeeded` / `payment_flow.amount_capturable_updated`
> webhook が届いた場合、現状は**無言で破棄**しているため、PAY.JP 側は課金済み・
> WooCommerce 側はキャンセルという不整合が管理者に見えない。
> これを「注文メモ + 管理者メール通知 + ログ」で可視化し、
> `amount_capturable_updated`（未キャプチャのオーソリ）についてはオーソリの自動取り消し（void）まで行う。
>
> **ステータスは変更しない**（`cancelled` のまま維持。理由は「設計判断」参照）。

---

## 背景（発生シナリオ）

PayPay は決済確定が遅延することがある。次のレースで不整合が発生する:

1. 顧客が PayPay で支払い → フローが `processing` のまま顧客がサイトに戻る
2. 注文は `pending` のまま webhook 待ち
3. WooCommerce の在庫保持時間切れ・管理者操作・顧客のマイアカウント操作などで注文が `cancelled` になる
4. `cancel_payment_flow()`（`class-wc-gateway-payjp.php:551`）がフローのキャンセルを試みるが、
   その直前にフローが `succeeded` へ遷移していると cancel API が `invalid_status` で失敗する
5. 数秒後、`payment_flow.succeeded` webhook が到着するが、
   `Payjp_Webhook_Handler::handle_payment_succeeded()` の #22 ガード
   （`! $order->has_status( array( 'pending', 'failed', 'on-hold' ) )` → return）で**無言で破棄**される
6. 結果: PAY.JP 側は課金済み、WC 側はキャンセル。管理者は気づけない

`payment_flow.amount_capturable_updated`（手動キャプチャの PayPay オーソリ完了）も同様に
キャンセル済み注文では無言で破棄され、**顧客の与信枠が拘束されたまま**になる。

---

## スコープ

### やること

1. **新設定「異常通知メールアドレス」**（Alert Email）を PAY.JP 設定ページに追加。
   空欄時は `get_option( 'admin_email' )` にフォールバック
2. **管理者向け通知メールクラス** `Payjp_Admin_Notifier` の新設（`wp_mail()` ベース）
3. **`handle_payment_succeeded()`**: 非 payable ステータスの注文への succeeded 到着時に
   注文メモ + メール + ログ（冪等ガード付き）
4. **`handle_payment_capturable_updated()`**: 確定済み注文への capturable 到着時に
   オーソリ自動 void + 注文メモ + メール + ログ（冪等ガード付き）
5. ユニットテスト追加、`docs/architecture.md` 更新

### やらないこと（別 Issue）

- **Issue #24**: `cancel_payment_flow()` の cancel 失敗時に `succeeded` を再取得して返金にフォールバックするレース対策
  （https://github.com/shinobiashi/payjp-for-wc/issues/24 / プラン: `docs/plans/issue-24-cancel-race-refund-fallback.md`）
- **Issue #25**: `woocommerce_cancel_unpaid_order` フィルターによる自動キャンセル保留 + Action Scheduler ポーリング
  （https://github.com/shinobiashi/payjp-for-wc/issues/25 / プラン: `docs/plans/issue-25-prevent-autocancel-inflight.md`）

> **メタキーの整合に関する注意**: 本 Issue が参照する `_payjp_cancel_refund_processed`（アラート抑止条件）は
> #24 の返金フォールバックが立てる共有ガードでもある。3 プラン間でキー名を変更しないこと。
> また #25 は本 Issue 実装後の webhook handler に `Monitor::clear()` 呼び出しを追加する。
- succeeded 検知時の**自動返金**（販売機会を機械的に潰すため人間の判断に委ねる）
- `WC_Email` サブクラス化（下記「設計判断 D-3」参照）
- readme.txt の changelog / バージョン番号更新（リリース作業時に実施）

---

## 設計判断（決定済み・実装時に再検討しない）

### D-1: 注文ステータスは変更しない

- `processing` への自動復活は在庫巻き戻し済みのため過剰販売リスクがあり、#22 ガードの導入意図と矛盾する
- `failed` は意味的に誤り（決済自体は成功している）
- `refunded` 遷移も不適切（WC 側に入金記録がなく `wc_create_refund()` の帳簿が崩れる。
  `cancel_payment_flow()` が `wc_create_refund()` を避けているのと同じ理由）
- → **メモとメールで判断材料を届け、返金 or 手動対応は管理者が選ぶ**

### D-2: `_payjp_cancel_refund_processed` が立っている注文には通知しない

キャンセル時に `cancel_payment_flow()` が自動返金済み（`_payjp_cancel_refund_processed` = `'1'`）の場合、
succeeded フローは返金後も `succeeded` のままなので、遅延/再送 webhook が届いても**異常ではない**。
この場合は従来どおり無言で return する（誤報防止）。

### D-3: メールは `wp_mail()` 直送（`WC_Email` サブクラスにしない)

宛先管理を PAY.JP 設定ページに一元化するというユーザー要件のため。
`WC_Email` にすると WooCommerce > 設定 > メール 側にも宛先欄ができて二重管理になる。
店舗向け運用アラート（顧客向けトランザクションメールではない）なので `wp_mail()` で十分。
カスタマイズ用にフィルターフックを提供する。

### D-4: `amount_capturable_updated` のみ自動アクション（void）を行う

未キャプチャのオーソリ取り消しは**金銭が動かない**（与信枠の解放のみ）ため自動化リスクが低い。
自動 void は注文が `cancelled` または `failed` の場合**のみ**実行。
それ以外の非対象ステータスでは通知のみ。

### D-5: API キーはイベントの `livemode` で選択する

webhook はプラグインの現在のテストモード設定と無関係に届き得る。
フローオブジェクトの `livemode`（bool）を見て live / test のシークレットキーを選ぶ。
該当キーが未設定なら API 呼び出しはスキップし、通知メールにその旨を含める。

---

## 変更ファイル一覧

| ファイル | 変更 |
|---------|------|
| `includes/gateways/payjp/class-payjp-admin-notifier.php` | **新規**: 通知メール送信クラス |
| `includes/gateways/payjp/class-payjp-webhook-handler.php` | 遅延 webhook 検知・void・通知呼び出し |
| `includes/gateways/payjp/class-payjp-settings.php` | `get_alert_email()` 追加 + docblock の設定構造更新 |
| `includes/admin/class-payjp-admin-settings-page.php` | 「Notifications」セクション + `payjp_alert_email` フィールド + save/output 対応 |
| `includes/class-payjp-loader.php` | 新規クラスファイルの `require_once` 追加 |
| `tests/Unit/WebhookHandlerTest.php` | 遅延 webhook 系テスト追加 |
| `tests/Unit/AdminNotifierTest.php` | **新規**: 通知クラスのテスト |
| `docs/architecture.md` | メタキー表・設定構造への追記 |
| `languages/payjp-for-wc.pot` / `payjp-for-wc-ja.po` / `.mo` | 新規文字列の追加（wp-i18n スキルの手順に従う） |

---

## 実装ステップ

### Step 1: 設定「異常通知メールアドレス」

#### 1-a. `class-payjp-admin-settings-page.php` — `get_settings()`

「Payment Methods」セクションの `sectionend`（`payjp_methods_settings`）の直後、
「Debug」セクションの前に追加:

```php
// ── Notifications ─────────────────────────────────────────────
array(
    'title' => __( 'Notifications', 'payjp-for-wc' ),
    'type'  => 'title',
    'id'    => 'payjp_notification_settings',
),
array(
    'title'       => __( 'Alert Email Address', 'payjp-for-wc' ),
    'type'        => 'email',
    'id'          => 'payjp_alert_email',
    'default'     => '',
    'placeholder' => get_option( 'admin_email' ),
    'desc_tip'    => false,
    'desc'        => __( 'Receives alerts when a payment anomaly is detected, such as a PAY.JP payment confirmed for an already-cancelled order. Leave blank to use the site administrator email address.', 'payjp-for-wc' ),
),
array(
    'type' => 'sectionend',
    'id'   => 'payjp_notification_settings',
),
```

#### 1-b. 同ファイル `output()` — `$option_map` に追加

```php
'payjp_alert_email' => (string) ( $current['alert_email'] ?? '' ),
```

#### 1-c. 同ファイル `save()` — `$settings` 配列に追加

```php
'alert_email' => sanitize_email( wp_unslash( is_string( $_POST['payjp_alert_email'] ?? '' ) ? $_POST['payjp_alert_email'] : '' ) ),
```

`sanitize_email()` は不正値を `''` にするので追加バリデーション不要（空 = admin_email フォールバック）。

#### 1-d. `class-payjp-settings.php`

クラス docblock の Settings structure に `'alert_email' => string,` を追記し、アクセサを追加:

```php
/**
 * Get the alert notification email address.
 * Falls back to the site administrator email when unset or invalid.
 */
public static function get_alert_email(): string {
    $email = (string) self::get( 'alert_email' );
    if ( $email && is_email( $email ) ) {
        return $email;
    }
    return (string) get_option( 'admin_email' );
}
```

### Step 2: `Payjp_Admin_Notifier`（新規ファイル）

`includes/gateways/payjp/class-payjp-admin-notifier.php`

既存クラスと同じ規約: ABSPATH ガード、`class_exists` ガード、file/class DocBlock、GPL ヘッダー。

```php
class Payjp_Admin_Notifier {

    /**
     * Send a payment-anomaly alert email to the store administrator.
     *
     * @param WC_Order             $order   Order the anomaly relates to.
     * @param string               $subject Translated subject line (without site-name prefix).
     * @param string[]             $lines   Translated body lines; imploded with "\n".
     * @return bool Whether wp_mail() reported success.
     */
    public static function send_alert( WC_Order $order, string $subject, array $lines ): bool {
```

仕様:

- 有効/無効: `apply_filters( 'payjp_for_wc_alert_email_enabled', true, $order )` が false なら送らず `false` を返す
- 宛先: `apply_filters( 'payjp_for_wc_alert_email_recipient', Payjp_Settings::get_alert_email(), $order )`
- 件名: `sprintf( '[%s] %s', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $subject )`
- 本文はプレーンテキスト。`$lines` の末尾に共通フッターとして注文編集 URL を追加:
  `$order->get_edit_order_url()`（WC 4.5+ で利用可。存在チェック不要）
- 送信: `wp_mail( $recipient, $subject, $body )` の戻り値をそのまま返す
- HTML ヘッダー指定はしない（プレーンテキスト）

本文組み立ての責務は呼び出し側（webhook handler）に置き、Notifier は送信のみ担当する。
これにより Notifier のテストが単純になる。

### Step 3: `handle_payment_succeeded()` の変更

`class-payjp-webhook-handler.php` の既存ガード（現 180 行付近）:

```php
if ( ! $order->has_status( array( 'pending', 'failed', 'on-hold' ) ) ) {
    return;
}
```

を次に置換:

```php
if ( ! $order->has_status( array( 'pending', 'failed', 'on-hold' ) ) ) {
    self::alert_succeeded_after_final( $order, $flow_id, $flow );
    return;
}
```

新規 private static メソッド `alert_succeeded_after_final( WC_Order $order, string $flow_id, array $flow ): void`:

1. **誤報抑止**: `'1' === (string) $order->get_meta( '_payjp_cancel_refund_processed' )` なら return
   （キャンセル時に自動返金済み = 不整合なし。D-2 参照）
2. **冪等ガード**: `$order->get_meta( '_payjp_alerted_late_succeeded' )` が truthy なら return
   （PAY.JP は非 2xx 時 3 回リトライ + ダッシュボード再送があるため）
3. `$order->update_meta_data( '_payjp_alerted_late_succeeded', '1' ); $order->save();`
4. 注文メモ追加（i18n。翻訳者コメント必須）:
   ```
   PAY.JP: Payment was confirmed for this order after it was already %1$s. The payment is captured on PAY.JP but is NOT reflected on this order. Review the PAY.JP dashboard and either refund the payment or handle the order manually. (Payment Flow ID: %2$s)
   ```
   `%1$s` = `$order->get_status()`、`%2$s` = flow ID
5. メール送信: `Payjp_Admin_Notifier::send_alert()`
   - subject: `__( 'PAY.JP payment confirmed for a closed order — action required', 'payjp-for-wc' )`
   - 本文 lines（各行 i18n + sprintf）:
     - 注文番号: `$order->get_order_number()`
     - 注文の現在ステータス: `$order->get_status()`
     - Payment Flow ID
     - 金額: `$flow['amount']`（`is_numeric()` チェックのうえ `number_format( (int) $flow['amount'] )`、無ければ行ごと省略）
     - 推奨アクション: 「PAY.JP ダッシュボードで該当決済を確認し、返金するか注文を手動で処理してください」の趣旨
6. ログ: `self::logger()->log_error( 'late succeeded webhook for closed order', $order->get_id(), null )`
   ※ `log_error()` のシグネチャは `( string $message, ?int $order_id = null, ?\Throwable $e = null )`

### Step 4: `handle_payment_capturable_updated()` の変更

既存ガード（現 222 行付近）:

```php
if ( ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
    return;
}
```

を次に置換:

```php
if ( ! $order->has_status( array( 'pending', 'on-hold' ) ) ) {
    self::alert_capturable_after_final( $order, $flow_id, $flow );
    return;
}
```

新規 private static メソッド `alert_capturable_after_final( WC_Order $order, string $flow_id, array $flow ): void`:

1. **重複イベント抑止（誤報防止）**: `$flow_id === $order->get_transaction_id()` かつ
   `$order->has_status( array( 'processing', 'completed' ) )` なら**無言で return**
   （同一イベントのリトライが正常処理済み注文に届いただけ）
2. **冪等ガード**: `_payjp_alerted_late_capturable` メタが truthy なら return。
   立てて save（Step 3 と同パターン）
3. **自動 void（D-4）**: 注文が `cancelled` または `failed` の場合のみ:
   - `self::get_api_for_flow( $flow )` で API クライアント取得（Step 5）
   - 取得できたら `POST /payment_flows/{$flow_id}/cancel`（body: `array( 'cancellation_reason' => 'requested_by_customer' )`。
     `rawurlencode( $flow_id )` を忘れない。既存 `cancel_payment_flow()` と同形式）
   - 成否を `$void_result` として本文・メモに反映。`RuntimeException` は catch して失敗扱い
   - API クライアントが取得できない（キー未設定）場合も「void 未実行」として通知
4. 注文メモ（i18n）:
   - void 成功: `PAY.JP: A PayPay authorization completed for this order after it was already %1$s. The authorization has been automatically voided. (Payment Flow ID: %2$s)`
   - void 失敗/未実行: `PAY.JP: A PayPay authorization completed for this order after it was already %1$s. Automatic void FAILED — the customer's funds remain reserved. Cancel the payment on the PAY.JP dashboard. (Payment Flow ID: %2$s)`
5. メール送信（Step 3 と同じ Notifier。件名は
   `__( 'PAY.JP authorization received for a closed order', 'payjp-for-wc' )`、
   本文に void 結果を含める）
6. ログ: void 失敗時は `log_error()`、成功時は `log_event( 'late_capturable_voided', ... )`

### Step 5: API クライアント取得ヘルパー + テスト用ファクトリ

`class-payjp-webhook-handler.php` に追加:

```php
/**
 * Test seam: when set, used instead of constructing Payjp_API directly.
 *
 * @var callable(string):Payjp_API|null
 */
private static $api_factory = null;

/**
 * Override the API factory (unit tests only).
 *
 * @param callable|null $factory Receives the secret key, returns a Payjp_API.
 */
public static function set_api_factory( ?callable $factory ): void {
    self::$api_factory = $factory;
}

/**
 * Build an API client using the secret key matching the event's livemode flag.
 * Returns null when the corresponding key is not configured.
 *
 * @param array<string, mixed> $flow Payment Flow object from the webhook payload.
 */
private static function get_api_for_flow( array $flow ): ?Payjp_API {
    $livemode = ! empty( $flow['livemode'] );
    $key      = $livemode
        ? (string) Payjp_Settings::get( 'live_secret_key' )
        : (string) Payjp_Settings::get( 'test_secret_key' );
    if ( '' === $key ) {
        return null;
    }
    if ( null !== self::$api_factory ) {
        return ( self::$api_factory )( $key );
    }
    return new Payjp_API( $key, self::logger() );
}
```

※ `Payjp_API` のコンストラクタは `( string $secret_key, ?JP4WC_Logger $logger = null )`。
※ `set_api_factory` は `@internal` DocBlock を付け、テスト以外で使わない旨明記。

### Step 6: ローダー登録

`includes/class-payjp-loader.php` の `require_once $dir . 'class-payjp-webhook-handler.php';`（41 行目付近）の直後に:

```php
require_once $dir . 'class-payjp-admin-notifier.php';
```

`Payjp_Admin_Notifier` は静的ユーティリティなので init 呼び出しは不要。

### Step 7: ドキュメント更新

`docs/architecture.md`:

- オーダーメタキー表に追加:
  - `_payjp_alerted_late_succeeded` — string `'1'` — 確定済み注文への遅延 succeeded webhook 通知済みフラグ（メール二重送信防止）
  - `_payjp_alerted_late_capturable` — string `'1'` — 同上（capturable イベント用）
- 設定構造（`payjp_settings`）の説明箇所に `alert_email` を追記
- アーキテクチャ決定事項に D-1〜D-5 の要約を 1 項目として追加（「遅延 webhook はステータスを変えず通知する」）

### Step 8: i18n

- 新規文字列はすべて `__()` / text domain `payjp-for-wc`、プレースホルダー付きは翻訳者コメント必須
- `languages/payjp-for-wc.pot` を再生成し、`payjp-for-wc-ja.po` に日本語訳を追加して `.mo` を再コンパイル
  （手順は `wp-i18n` スキル参照。JSON 翻訳は JS 文字列がないため今回不要）

---

## テスト計画

### `tests/Unit/WebhookHandlerTest.php` に追加

既存パターン（Brain Monkey + Mockery、`WC_Order` は `Mockery::mock( WC_Order::class )`）を踏襲。
`wp_mail` は `Functions\expect( 'wp_mail' )->once()->andReturn( true )` で検証。
`get_bloginfo` / `wp_specialchars_decode` / `is_email` / `sanitize_email` / `number_format` 等は
`Functions\when()` でスタブ。`tearDown` で `Payjp_Webhook_Handler::set_api_factory( null )` を必ず呼ぶ。

| # | テスト | 検証内容 |
|---|--------|---------|
| 1 | succeeded × cancelled 注文（返金フラグなし・通知フラグなし） | `add_order_note` 1 回、`wp_mail` 1 回、`update_meta_data( '_payjp_alerted_late_succeeded', '1' )` + `save`、`payment_complete` は呼ばれない |
| 2 | succeeded × cancelled 注文（`_payjp_cancel_refund_processed` = '1'） | メモ・メール・メタ書き込みなし（無言 return） |
| 3 | succeeded × cancelled 注文（`_payjp_alerted_late_succeeded` = '1'） | 2 回目はメモ・メールなし（冪等） |
| 4 | capturable × cancelled 注文、API factory がモックを返す | `POST /payment_flows/{id}/cancel` が 1 回呼ばれ、成功系メモ + メール |
| 5 | capturable × cancelled 注文、API が `RuntimeException` | 失敗系メモ + メール（void 失敗でも通知は送られる） |
| 6 | capturable × cancelled 注文、シークレットキー未設定（factory null・settings 空） | API 呼び出しなし、失敗系メモ + メール |
| 7 | capturable × processing 注文で `transaction_id` が flow ID と一致 | 完全に無言（メモ・メール・void なし）— 正常処理済みへのリトライ |
| 8 | capturable × cancelled 注文（`_payjp_alerted_late_capturable` = '1'） | 冪等: 2 回目は何もしない |

### `tests/Unit/AdminNotifierTest.php`（新規）

| # | テスト | 検証内容 |
|---|--------|---------|
| 1 | `alert_email` 設定あり | `wp_mail` の宛先が設定値 |
| 2 | `alert_email` 空 | 宛先が `admin_email`（`get_option( 'admin_email' )` スタブ値） |
| 3 | `payjp_for_wc_alert_email_recipient` フィルター | フィルター適用後の宛先で送信される（Brain Monkey の `Filters\expectApplied` 利用） |
| 4 | `payjp_for_wc_alert_email_enabled` → false | `wp_mail` が呼ばれず `false` を返す |
| 5 | 本文 | `$lines` が改行結合され、注文編集 URL が末尾に含まれる |

※ `tests/stubs/class-wc-order.php` に `get_edit_order_url()` / `get_order_number()` /
`get_transaction_id()` が無ければ追加する（既存スタブの形式に合わせる）。
※ `Payjp_Settings` はテスト間で静的キャッシュを持つため、`setUp` で `Payjp_Settings::flush_cache()`（既存パターン）。

---

## 受け入れ基準

1. キャンセル済み注文に succeeded webhook が届くと、注文メモとメールで管理者が検知できる（1 通のみ）
2. キャンセル時に自動返金済みの注文では通知が出ない（誤報ゼロ）
3. キャンセル済み注文に capturable webhook が届くと、オーソリが自動 void され、結果がメモ・メールに残る
4. void 失敗時も必ず通知され、顧客の与信枠拘束が管理者に伝わる
5. 正常処理済み注文への重複イベントでは一切通知が出ない
6. 通知メール宛先は PAY.JP 設定ページで変更でき、空欄なら管理者メールに届く
7. 注文ステータスは一切変更されない
8. `vendor/bin/phpcs --standard=phpcs.xml .` エラー・警告 0 件
9. `vendor/bin/phpstan analyse --memory-limit=1G` エラー 0 件
10. `vendor/bin/phpunit` 全件パス（既存テスト含む）

---

## 実装時の必須手順（CLAUDE.md 準拠）

1. 実装前に `payjp-v2-woocommerce` / `wc-development` / `wp-plugin-development` スキルを呼び出す
2. PHP 編集後: `vendor/bin/phpcbf` → `vendor/bin/phpcs` → `vendor/bin/phpstan` → `vendor/bin/phpunit`
3. `docs/review-checklist.md` の 10 項目セルフレビュー
   （特に: ABSPATH ガード / `class_exists` ガード / i18n 翻訳者コメント / エスケープ / Yoda 条件 / HPOS メタ API）
4. **`git commit` / `git push` は絶対に行わない**（ユーザーが手動で実施）。
   変更内容の説明を出力して終了する
