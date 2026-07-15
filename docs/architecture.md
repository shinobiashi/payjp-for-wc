# アーキテクチャリファレンス

> CLAUDE.md から参照される詳細資料。アーキテクチャに関わる変更・調査の際に読むこと。

## ファイル構成

```
payjp-for-wc.php                     ← ブートストラップ・定数定義
uninstall.php                        ← プラグイン削除時のデータ削除
includes/
  class-payjp-loader.php             ← クラスロード・フック登録・支払い方法ガード
  admin/
    class-payjp-admin-settings-page.php ← 統合設定画面
  gateways/payjp/
    class-payjp-settings.php         ← 共有設定マネージャー（API キー等）
    class-payjp-api.php              ← PAY.JP API ラッパー（wp_remote_* 使用）
    class-wc-gateway-payjp.php       ← 抽象基底クラス（handle_return / do_refund / cancel_payment_flow 等の共通処理）
    class-wc-gateway-payjp-card.php  ← カード決済ゲートウェイ
    class-wc-gateway-payjp-paypay.php← PayPay 決済ゲートウェイ
    class-payjp-webhook-handler.php  ← Webhook 受信・検証・ルーティング
    class-payjp-pending-payment-monitor.php ← 処理中フローの自動キャンセル保留 + ポーリングフォールバック
    class-payjp-admin-notifier.php   ← 管理者向け異常通知メール送信
    class-payjp-blocks-integration.php        ← Block Checkout 統合の抽象基底
    class-payjp-blocks-integration-card.php   ← Block Checkout 統合（カード）
    class-payjp-blocks-integration-paypay.php ← Block Checkout 統合（PayPay）
    class-payjp-token-manager.php    ← WC Token API + Setup Flow 管理
    class-payjp-subscriptions.php    ← WooCommerce Subscriptions 対応
  jp4wc-framework/
    class-jp4wc-logger.php           ← JP4WC 共通ロガー
src/
  blocks/checkout/
    index.js                         ← registerPaymentMethod（カード・PayPay）
    payment-method-card.js           ← カード決済 React コンポーネント
    payment-method-paypay.js         ← PayPay 決済 React コンポーネント
  frontend/
    checkout-card.js                 ← クラシックチェックアウト（カード）
    checkout-paypay.js               ← クラシックチェックアウト（PayPay）
    setup-card.js                    ← マイアカウントのカード登録（Setup Flow）
  admin/settings/index.js            ← 管理画面 JS
build/                               ← コンパイル済み（git 管理外）
tests/
  Unit/                              ← PHPUnit + Brain\Monkey ユニットテスト
  stubs/                             ← WC_Order 等の最小スタブ
```

## 定数（`payjp-for-wc.php` で定義）

```php
defined( 'PAYJP_FOR_WC_VERSION' ) || define( 'PAYJP_FOR_WC_VERSION', '1.0.0' );
defined( 'PAYJP_FOR_WC_FILE' )    || define( 'PAYJP_FOR_WC_FILE',    __FILE__ );
defined( 'PAYJP_FOR_WC_DIR' )     || define( 'PAYJP_FOR_WC_DIR',     plugin_dir_path( __FILE__ ) );
defined( 'PAYJP_FOR_WC_URL' )     || define( 'PAYJP_FOR_WC_URL',     plugin_dir_url( __FILE__ ) );
defined( 'PAYJP_API_BASE' )       || define( 'PAYJP_API_BASE',       'https://api.pay.jp/v2' );
```

`defined() || define()` パターンは Japanized for WooCommerce への同梱時の二重読み込み防止のため必須。

## オーダーメタキー

| キー | 型 | 説明 |
|------|-----|------|
| `_payjp_payment_flow_id` | string | Payment Flow ID (`pfw_xxx`) |
| `_payjp_payment_method` | string | `card` または `paypay` |
| `_payjp_capture_method` | string | `automatic` または `manual` |
| `_payjp_refund_processed_{id}` | string `'1'` | 処理済み返金 ID ごとに 1 エントリ（複数部分返金対応）|
| `_payjp_cancel_refund_processed` | string `'1'` | 注文キャンセル時の自動返金済みフラグ（二重返金防止）|
| `_payjp_customer_id` | string | PAY.JP Customer ID（トークン保存・Subscriptions 用）|
| `_payjp_payment_method_id` | string | 保存カードの PaymentMethod ID |
| `_payjp_alerted_late_succeeded` | string `'1'` | 確定済み注文への遅延 `payment_flow.succeeded` webhook 通知済みフラグ（メール二重送信防止）|
| `_payjp_alerted_late_capturable` | string `'1'` | 同上（`payment_flow.amount_capturable_updated` イベント用）|
| `_payjp_awaiting_webhook` | string | Webhook 確定待ちフラグ（`handle_return()` の in-flight 分岐で設置する Unix タイムスタンプ）。保留ウィンドウ内は未払い自動キャンセルを抑止 |
| `_payjp_flow_poll_attempts` | string | ポーリング試行回数（int を文字列保存、最大 3）|
| `_payjp_flow_livemode` | string `'0'`/`'1'` | Payment Flow 作成時のモード（`'1'` = live）。ポーラーの API キー選択に使用 |

**HPOS 必須**: オーダーメタは `$order->get_meta()` / `$order->update_meta_data()` + `$order->save()` を使う。`get_post_meta()` / `update_post_meta()` は禁止。

## 重要な決定事項（詳細）

### 共有設定 (`class-payjp-settings.php`)

API キー・テストモード・Webhook シークレット・異常通知メールアドレス（`alert_email`）は
**全決済手段で共有**。`Payjp_Settings::OPTION_KEY = 'payjp_settings'` に一元管理。
個別ゲートウェイの設定画面はタイトル・説明文などの表示設定のみ。

### payments.js の CDN 読み込み

`https://js.pay.jp/payments.js` を CDN から読み込む。
PCI 準拠が目的であり、wordpress.org 審査で認められているパターン（Stripe の `js.stripe.com` と同様）。
`readme.txt` の `== External Services ==` セクションへの開示が必須。

### `is_available()` の連動

`Payjp_Settings::is_method_enabled( 'card' )` が `false` を返した場合、
`WC_Gateway_Payjp_Card::is_available()` も `false` を返し、チェックアウトから非表示になる。

### 支払い方法変更の正当性判定（`class-payjp-loader.php`）

PAY.JP の Payment Flow は非同期に確定するため、注文の `payment_method` を
横取りする複数のガード（Hydration バグ対策・Blocks cart-sync 対策）が
`class-payjp-loader.php` に存在する。これらのガードが「WooCommerce 内部の
勝手な上書き」と「顧客による正当なゲートウェイ切り替え」を区別する基準は、
WooCommerce がチェックアウト処理時にセッションへ書き込む
`chosen_payment_method`。これと一致する変更は正当な切り替えとして許可し、
そうでなければ `_payjp_payment_method` メタを根拠に巻き戻す。

正当な切り替えを許可する際は、`_payjp_*` メタだけでなく **`transaction_id`
（注文の独立したフィールド）も必ずクリアする**こと。
`Payjp_Webhook_Handler::find_order_by_flow_id()` はメタへのフォールバック
より先に `transaction_id` を検索するため、片方だけ消すと放置された
Payment Flow が後から非同期に完了した際に誤って別ゲートウェイの注文に
マッチしてしまう。

### `payment_complete()` を呼ぶ全経路に必要なステータス許可リストガード

WooCommerce コアの `payment_complete()` はデフォルトで `cancelled` も
「支払い完了に遷移してよい」許可リストに含めている。そのため
`$order->is_paid()`（`cancelled` では `false`）だけをガードにすると、
注文キャンセル後に `payment_complete()` へ到達する経路（`handle_return()`
の return URL 再訪問、遅延・再送された `payment_flow.succeeded` Webhook）で
キャンセル済み注文が `processing` に復活してしまう。`do_refund()` /
`cancel_payment_flow()` による自動返金後も PAY.JP の Payment Flow
ステータス自体は `succeeded` のまま変わらないため、この穴は悪用可能
（#22）。`payment_complete()` を呼ぶ前は必ず
`$order->has_status( array( 'pending', 'failed', 'on-hold' ) )` の
明示的な許可リストで守ること。

### 遅延 Webhook はステータスを変えず通知する（#23）

`payment_flow.succeeded` / `payment_flow.amount_capturable_updated` が
確定済み（`cancelled` 等）の注文に届いた場合、上記ガードにより無言で
破棄されると PAY.JP 側は課金・オーソリ済みなのに WC 側の記録が
食い違ったまま管理者に気づかれない。そのため
`Payjp_Webhook_Handler::alert_succeeded_after_final()` /
`alert_capturable_after_final()` が注文メモ・管理者メール（`Payjp_Admin_Notifier`）・
ログで可視化する。要点:

- **注文ステータスは変更しない**（`processing` 復活は過剰販売リスク、`refunded`
  遷移は `wc_create_refund()` の帳簿と矛盾するため）。返金や手動対応の判断は
  管理者に委ねる
- `_payjp_cancel_refund_processed` フラグが立っている注文（キャンセル時に
  既に自動返金済み）は誤報として扱い通知しない
- 通知メールは `wp_mail()` 直送（`WC_Email` サブクラス化しない）。宛先は
  PAY.JP 設定ページの `alert_email` に一元管理し、WooCommerce > 設定 > メール
  との二重管理を避ける
- `amount_capturable_updated`（未キャプチャの PayPay オーソリ）のみ、
  対象注文が `cancelled`/`failed` の場合に限り自動 void を実行する
  （金銭移動を伴わないため自動化リスクが低い）
- Webhook イベントの `livemode` フラグに応じて live/test の API シークレット
  キーを選択する（プラグインの現在のテストモード設定とは独立）

詳細な設計判断（D-1〜D-5）は `docs/plans/issue-23-late-webhook-alert.md` を参照。

### 処理中フローの注文は自動キャンセルを保留し、ポーリングで確定する（#25）

PayPay は非同期確定のため、顧客が戻った時点でフローが `requires_action` /
`processing` のまま注文が `pending` で webhook 待ちになる。この待ち時間中に
WooCommerce の未払い注文自動クリーンアップ（`wc_cancel_unpaid_orders`）が
発火する、あるいは webhook が届かないと、支払い済み注文がキャンセルされうる。
`Payjp_Pending_Payment_Monitor` が発生自体を予防する:

- `handle_return()` の in-flight 分岐が `start()` を呼び、
  `_payjp_awaiting_webhook`（タイムスタンプ）を設置 + 初回ポーリングを予約
- `woocommerce_cancel_unpaid_order` フィルターで、フラグが保留ウィンドウ
  （デフォルト 30 分、`payjp_for_wc_awaiting_webhook_hold` フィルターで変更可）内の
  注文の**自動**キャンセルのみスキップ。手動キャンセルには影響しない。
  フィルター内では API を呼ばずメタ参照のみで判定する
- Action Scheduler の単発ジョブ（`payjp_for_wc_poll_flow`、+5/+10/+15 分の最大
  3 回）がフローを API 照合し、webhook handler と同じガード・遷移で注文を確定
  （`succeeded` → `payment_complete`、`requires_capture` → `processing`、
  `payment_failed`/`canceled` → `failed`）。上限到達後はフラグをクリアして
  通常の自動キャンセル対象に戻す（在庫を無期限に確保しない）
- ポーラーの API キーはフロー作成時に保存した `_payjp_flow_livemode` メタで
  選択（メタ無しの旧注文は現在のアクティブキーにフォールバック）
- クリアは `Payjp_Pending_Payment_Monitor::clear()` に集約。webhook handler・
  `handle_return()`・ポーラーの確定処理成功後に呼ばれ、フラグ・試行回数・
  未実行ジョブを削除する。クリア漏れがあってもフラグは時間経過で自然失効する
  （フェイルセーフ）
- Action Scheduler が利用できない環境では予防フィルターのみで動作し fatal に
  ならない（`function_exists` ガード）

詳細な設計判断（D-1〜D-8）は `docs/plans/issue-25-prevent-autocancel-inflight.md` を参照。
