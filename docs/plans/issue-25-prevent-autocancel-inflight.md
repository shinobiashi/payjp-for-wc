# Issue #25: PayPay 決済処理中の注文の自動キャンセル保留＋ポーリングフォールバック — 実装プラン

> **GitHub Issue**: https://github.com/shinobiashi/payjp-for-wc/issues/25
> **作業ブランチ**: `feature/issue-25-prevent-autocancel-inflight`（最新の main から切る）
> **PR 本文に `Fixes #25` を含めること**
> **⚠️ 前提: #23・#24 のマージ後に着手すること。** 本プランは #23 実装後の
> `class-payjp-webhook-handler.php` の形（`alert_*` メソッド追加済み）を前提に書かれている。
> 着手時に main の実際のコードと本プランの前提がずれていないか最初に確認し、
> ずれていれば本プランを先に修正すること。

---

## 背景

PayPay は非同期確定のため、顧客がサイトに戻った時点でフローが `processing` / `requires_action` のまま
注文が `pending` で webhook 待ちになる（#20）。この待ち時間中に WooCommerce の未払い注文
自動キャンセル（在庫保持時間、デフォルト 60 分）が発火する、あるいは webhook 自体が届かない
（エンドポイント不達・未設定）と、**顧客が支払った（支払おうとしている）注文がキャンセルされる**。

#23（アラート）と #24（返金フォールバック）は事後対応。本 Issue は**不整合の発生自体を予防**し、
webhook 不達時の回復経路（ポーリング）を追加する。

## スコープ

### やること

1. `handle_return()` が処理中フローを検知した注文に `_payjp_awaiting_webhook` フラグ（タイムスタンプ）を立てる
2. `woocommerce_cancel_unpaid_order` フィルターで、フラグが新しい注文の自動キャンセルをスキップ
3. Action Scheduler によるポーリングフォールバック（最大 3 回、5/10/15 分後）でフロー状態を照合して注文を確定
4. webhook / return handler / ポーラーのいずれかが注文を確定したらフラグとスケジュールをクリア
5. ポーラーが API キーを正しく選べるよう、フロー作成時に `_payjp_flow_livemode` メタを保存
6. ユニットテスト・`docs/architecture.md` 更新

### やらないこと

- 新しい通知メール（異常検知は #23 の責務。ポーラーは既存ガードを尊重して確定処理をするだけ）
- 手動キャンセル（管理者・顧客操作）の抑止 — フィルターは WC の**自動**クリーンアップのみに効く。
  手動キャンセル起因の不整合は #23/#24 がカバー済み
- readme.txt changelog / バージョン更新（リリース時）

## 設計判断（決定済み・実装時に再検討しない）

### D-1: 新規クラス `Payjp_Pending_Payment_Monitor`（静的クラス + `init()` パターン）

`Payjp_Webhook_Handler` と同じ構成（ABSPATH ガード / `class_exists` ガード / `init()` で hook 登録）。
フィルター・ポーリング・フラグ管理を 1 クラスに集約する。

### D-2: フラグは `_payjp_awaiting_webhook` = Unix タイムスタンプ（文字列）

`handle_return()` の in-flight 分岐（`requires_action` / `processing`）で `(string) time()` を保存。
判定側は「フラグが存在し、かつ経過時間が保留ウィンドウ内」のみ有効とみなす。
古いフラグは無効＝自動キャンセル解禁（クリア漏れがあってもフェイルセーフ）。

### D-3: 保留ウィンドウは 30 分（フィルターで変更可能）

```php
$hold = (int) apply_filters( 'payjp_for_wc_awaiting_webhook_hold', 30 * MINUTE_IN_SECONDS, $order );
```

PayPay の確定遅延は通常数秒〜数分であり、フローの有効期限も短いため 30 分で十分。
在庫保持時間（デフォルト 60 分）より短いこと自体は問題ない — フィルターは
`wc_cancel_unpaid_orders` が走るたびに評価されるため、ウィンドウ内だけスキップされる。

### D-4: ポーリングは Action Scheduler の単発ジョブ、最大 3 回（+5 分 / +10 分 / +15 分）

- フック名: `payjp_for_wc_poll_flow`、引数: `array( 'order_id' => int )`、グループ: `'payjp-for-wc'`
- 試行回数は `_payjp_flow_poll_attempts` メタで管理（int を文字列保存）
- Action Scheduler は WooCommerce コアに同梱されるため追加依存なし。ただし呼び出しは
  `function_exists( 'as_schedule_single_action' )` でガード（ガード不成立なら予防フィルターのみで動作）
- ポーリング上限到達後は `_payjp_awaiting_webhook` をクリアし、通常の自動キャンセルに戻す
  （在庫を無期限に確保しない）。その旨を注文メモに残す

### D-5: ポーラーの確定処理は既存のガードとステータス遷移を厳密に踏襲

`handle_return()` / webhook handler と同じ判定を使う:

- 事前ガード: `$order->is_paid()` なら何もせずクリア。
  `! $order->has_status( array( 'pending', 'on-hold' ) )` なら何もせずクリア（#22 の許可リスト思想）
- `succeeded` → `payment_complete( $flow_id )` + メモ「PAY.JP: Payment confirmed via status polling.」
- `requires_capture` → `set_transaction_id()` + `update_status( 'processing', ... )`（webhook handler の
  `handle_payment_capturable_updated()` と同文言・同処理）
- `payment_failed` / `canceled` → `update_status( 'failed', ... )` + メモ
- `requires_action` / `processing` / `requires_payment_method` / `requires_confirmation` →
  試行回数をインクリメントして次回を再スケジュール（上限まで）
- API エラー（`RuntimeException`）→ 1 試行としてカウントし再スケジュール

### D-6: API キーはフロー作成時のモードで選択（`_payjp_flow_livemode` メタ）

webhook（#23 の D-5）と異なり、ポーラーの手元にはフローオブジェクトがないため、
**フロー作成時点のモード**をメタに保存しておく:

- `class-wc-gateway-payjp-paypay.php` の `process_payment()`（メタ保存箇所、現 193〜196 行付近）と
  `class-wc-gateway-payjp-card.php` の同等箇所に
  `$order->update_meta_data( '_payjp_flow_livemode', Payjp_Settings::is_test_mode() ? '0' : '1' );` を追加
- ポーラーは `'1'` なら `live_secret_key`、それ以外（`'0'` および旧注文でメタ無し）なら
  `test_secret_key` … ではなく、**メタ無しの旧注文は `Payjp_Settings::get_secret_key()`（現在のアクティブキー）**
  にフォールバック
- 該当キーが空ならその試行はスキップ扱い（再スケジュール対象）

### D-7: フラグ・スケジュールのクリアは 1 箇所に集約

```php
Payjp_Pending_Payment_Monitor::clear( WC_Order $order ): void
```

- `_payjp_awaiting_webhook` / `_payjp_flow_poll_attempts` を削除して save
- `function_exists( 'as_unschedule_action' )` ガード付きで該当ジョブを解除

呼び出し箇所:
- ポーラー自身（確定 or 上限到達時）
- `Payjp_Webhook_Handler::handle_payment_succeeded()` / `handle_payment_capturable_updated()` /
  `handle_payment_failed()` の**確定処理成功後**
- `handle_return()` の `succeeded` / `requires_capture` 確定分岐

※ webhook handler → Monitor の依存は `class_exists( 'Payjp_Pending_Payment_Monitor' )` ガード付きで呼ぶ
（単体テストや部分ロード時の頑健性のため）。

### D-8: フィルターの対象判定

`woocommerce_cancel_unpaid_order` コールバックのシグネチャは `( bool $cancel, WC_Order $order ): bool`。

- `$order->get_payment_method()` が `payjp_` で始まらない → `$cancel` をそのまま返す
- `_payjp_awaiting_webhook` が無い/空 → そのまま返す
- タイムスタンプがウィンドウ内 → `false`（キャンセル抑止）
- ウィンドウ超過 → そのまま返す

このフィルター内では API 呼び出しをしない（`wc_cancel_unpaid_orders` は多数の注文を
ループするため、メタ参照のみで判定する）。

## 変更ファイル一覧

| ファイル | 変更 |
|---------|------|
| `includes/gateways/payjp/class-payjp-pending-payment-monitor.php` | **新規**: フィルター + ポーラー + クリア API |
| `includes/gateways/payjp/class-wc-gateway-payjp.php` | `handle_return()`: in-flight 分岐でフラグ設置 + ポーリング予約、確定分岐でクリア |
| `includes/gateways/payjp/class-wc-gateway-payjp-paypay.php` | `process_payment()`: `_payjp_flow_livemode` メタ保存 |
| `includes/gateways/payjp/class-wc-gateway-payjp-card.php` | 同上 |
| `includes/gateways/payjp/class-payjp-webhook-handler.php` | 確定処理成功後に `Monitor::clear()` 呼び出し（3 箇所） |
| `includes/class-payjp-loader.php` | 新規クラスの `require_once` + `Payjp_Pending_Payment_Monitor::init()` |
| `tests/Unit/PendingPaymentMonitorTest.php` | **新規** |
| `tests/Unit/WebhookHandlerTest.php` / `GatewayCancelTest.php` ほか | クリア呼び出し追加に伴う既存テストの期待値更新 |
| `docs/architecture.md` | メタキー表（`_payjp_awaiting_webhook` / `_payjp_flow_poll_attempts` / `_payjp_flow_livemode`）+ 決定事項追記 |
| `languages/*` | 新規文字列の POT/PO/MO 更新 |

## 実装ステップ

### Step 1: `Payjp_Pending_Payment_Monitor` 新規作成

公開 API:

```php
class Payjp_Pending_Payment_Monitor {
    const POLL_HOOK      = 'payjp_for_wc_poll_flow';
    const POLL_GROUP     = 'payjp-for-wc';
    const MAX_ATTEMPTS   = 3;
    /** Delays (seconds) before each polling attempt: +5m, +10m, +15m. */
    const POLL_DELAYS    = array( 300, 600, 900 );

    public static function init(): void;                       // add_filter( 'woocommerce_cancel_unpaid_order', ..., 10, 2 ) + add_action( self::POLL_HOOK, ... )
    public static function hold_auto_cancel( $cancel, $order ); // D-8
    public static function start( WC_Order $order ): void;      // フラグ設置 + 初回ポーリング予約（handle_return から呼ぶ）
    public static function poll_flow( int $order_id ): void;    // D-5 の確定処理
    public static function clear( WC_Order $order ): void;      // D-7
}
```

- `start()`: `_payjp_awaiting_webhook` = `(string) time()` 保存 →
  `as_schedule_single_action( time() + self::POLL_DELAYS[0], self::POLL_HOOK, array( 'order_id' => $order->get_id() ), self::POLL_GROUP )`
  （`function_exists` ガード + 二重予約防止に `as_has_scheduled_action()` があれば使用）
- `poll_flow()`: D-5 / D-6 のとおり。API クライアントは `new Payjp_API( $key, $logger )`。
  テスト用に `set_api_factory()` シームを #23 の webhook handler と同形式で用意する
- ログ: 確定時 `log_event( 'poll_settled', ... )`、上限到達 `log_event( 'poll_exhausted', ... )`、
  API エラー `log_error()`

### Step 2: `handle_return()` の変更（`class-wc-gateway-payjp.php`）

- in-flight 分岐（`requires_action` / `processing`、現 380〜394 行付近）のリダイレクト前に
  `Payjp_Pending_Payment_Monitor::start( $order );`
- `succeeded` / `requires_capture` の確定分岐に `Payjp_Pending_Payment_Monitor::clear( $order );`
  （`payment_complete()` / `update_status()` の後）

### Step 3: webhook handler の変更

`handle_payment_succeeded()` / `handle_payment_capturable_updated()` / `handle_payment_failed()` の
確定処理（`payment_complete` / `update_status`）成功後に:

```php
if ( class_exists( 'Payjp_Pending_Payment_Monitor' ) ) {
    Payjp_Pending_Payment_Monitor::clear( $order );
}
```

※ #23 で追加される `alert_*` 経路（確定処理をしない経路）ではクリアしない。
フラグは D-2 のとおり時間経過で自然失効する。

### Step 4: `process_payment()` への livemode メタ追加（card / paypay 両方）

既存の `update_meta_data( '_payjp_capture_method', ... )` の直後に 1 行追加（D-6）。

### Step 5: ローダー登録

`require_once $dir . 'class-payjp-pending-payment-monitor.php';` を webhook handler の直後に追加し、
`Payjp_Webhook_Handler::init();` の並びで `Payjp_Pending_Payment_Monitor::init();` を呼ぶ。

### Step 6: ドキュメント / i18n

- `docs/architecture.md`: メタキー 3 件追加 + 決定事項「処理中フローの注文は自動キャンセルを保留し、
  ポーリングで確定する」を追記
- 新規文字列の POT 再生成・ja 翻訳追加（`wp-i18n` スキル参照）

## テスト計画

### `tests/Unit/PendingPaymentMonitorTest.php`（新規）

Brain Monkey + Mockery。`as_*` 関数は `Functions\when()` / `Functions\expect()` でスタブ。
`time()` 依存は「フラグ値を過去時刻に設定したモック注文」で制御（`time()` 自体はスタブしない）。

| # | テスト | 検証内容 |
|---|--------|---------|
| 1 | `hold_auto_cancel`: フラグが 5 分前の PayPay 注文 | `false` を返す（キャンセル抑止） |
| 2 | `hold_auto_cancel`: フラグが 31 分前 | 入力 `$cancel` をそのまま返す |
| 3 | `hold_auto_cancel`: フラグ無し / 他ゲートウェイの注文 | 入力をそのまま返す。メタ参照以外の副作用なし |
| 4 | `start()` | フラグ保存 + `as_schedule_single_action` が 1 回呼ばれる |
| 5 | `poll_flow`: フロー `succeeded`、注文 pending | `payment_complete` + メモ + クリア（メタ削除 + unschedule） |
| 6 | `poll_flow`: フロー `requires_capture` | `set_transaction_id` + `update_status('processing')` + クリア |
| 7 | `poll_flow`: フロー `payment_failed` | `update_status('failed')` + クリア |
| 8 | `poll_flow`: フロー `processing`、attempts=0 | attempts が `'1'` に更新され、`POLL_DELAYS[1]` 後に再スケジュール |
| 9 | `poll_flow`: フロー `processing`、attempts=2（最終試行） | 再スケジュールなし。フラグクリア + メモ（自動キャンセル解禁の説明） |
| 10 | `poll_flow`: 注文が既に paid / cancelled | API 呼び出しなし、クリアのみ |
| 11 | `poll_flow`: API throw | attempts インクリメント + 再スケジュール |
| 12 | `poll_flow`: `_payjp_flow_livemode` = `'1'` / `'0'` / 無し | それぞれ live / test / 現在のアクティブキーが選ばれる（api factory 引数で検証） |

### 既存テストの更新

- `WebhookHandlerTest`: 確定系テストで `Payjp_Pending_Payment_Monitor::clear()` が呼ばれることの検証を追加
  （または Monitor をロードせず `class_exists` ガードで素通りすることの確認 — bootstrap の構成に合わせて選択）
- `handle_return` に単体テストは現状存在しないため、`start()` / `clear()` の呼び出し検証は
  Monitor 側テストでカバーする（handle_return のテスト新設はスコープ外）

## 受け入れ基準

1. フローが処理中の注文は、保留ウィンドウ内は WC の未払い自動キャンセルでキャンセルされない
2. webhook 不達でも、遅延成功はポーリング（最大 3 回）で `payment_complete` され、失敗フローは `failed` になる
3. ポーリング上限に達した注文はフラグが解除され、通常の自動キャンセル対象に戻る（在庫の無期限確保なし）
4. webhook・return handler・ポーラーが重複しても `payment_complete` は 1 回しか走らない（既存ガード + クリア）
5. 手動キャンセルは従来どおり可能（フィルターは自動クリーンアップのみに作用）
6. Action Scheduler が利用できない環境でも fatal にならない（`function_exists` ガード）
7. PHPCS / PHPStan (level 5) / PHPUnit 全件パス

## 実装時の必須手順（CLAUDE.md 準拠）

1. **最初に #23・#24 マージ後の main と本プランの前提の差分を確認**（冒頭の ⚠️ 参照）
2. 実装前に `payjp-v2-woocommerce` / `wc-development` / `wp-plugin-development` スキルを呼び出す
3. PHP 編集後: `vendor/bin/phpcbf` → `vendor/bin/phpcs` → `vendor/bin/phpstan` → `vendor/bin/phpunit`
4. `docs/review-checklist.md` の 10 項目セルフレビュー
5. **`git commit` / `git push` は絶対に行わない**（ユーザーが手動で実施）。変更内容の説明を出力して終了する
