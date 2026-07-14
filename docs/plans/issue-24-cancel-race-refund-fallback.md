# Issue #24: フローキャンセルと決済成功のレース時に返金へフォールバック — 実装プラン

> **GitHub Issue**: https://github.com/shinobiashi/payjp-for-wc/issues/24
> **作業ブランチ**: `fix/issue-24-cancel-race-refund-fallback`（最新の main から切る）
> **PR 本文に `Fixes #24` を含めること**
> **#23 と変更ファイルが重ならないため並行作業可能**（#23: webhook handler / #24: gateway 基底クラス）

---

## 背景

注文キャンセル時、`cancel_payment_flow()`（`includes/gateways/payjp/class-wc-gateway-payjp.php:551`）は
フロー状態を取得し、未キャプチャなら cancel エンドポイント、`succeeded` なら自動全額返金を実行する。

レース窓: 状態取得では `processing` だったフローが、cancel API 呼び出しまでの間に `succeeded` へ遷移すると、
cancel は `invalid_status` で失敗する。現状は汎用の「Payment cancellation failed」メモを残すだけで、
**PAY.JP 側は課金済み・WC 側はキャンセルの不整合が放置される**。

Issue #23 は遅延 `payment_flow.succeeded` webhook の到着時にアラートを出すが、
本 Issue はキャンセル処理の時点でこのレースを**自動解決**する。

## スコープ

### やること

1. cancel エンドポイント失敗時にフローを再取得し、`succeeded` になっていたら既存の自動返金パスへフォールバック
2. `succeeded` → 自動返金のロジックをメソッド抽出して両呼び出し元で共有
3. ユニットテスト追加

### やらないこと

- webhook handler の変更（#23 のスコープ）
- 自動キャンセル予防・ポーリング（#25 のスコープ）

## 設計判断（決定済み）

### D-1: エラー種別を判定せず、失敗時は常に再取得する

`Payjp_API` は失敗を `RuntimeException` のメッセージ文字列で伝えるため、`invalid_status` かどうかの
文字列パースは脆い。cancel 失敗時は**理由を問わず** GET で再取得し、実際の状態で分岐する。
GET 1 回のコストで、ネットワークエラー起因の失敗でも正しい状態が得られる。

### D-2: 既存 `succeeded` 分岐をメソッド抽出して共有する

現在 `cancel_payment_flow()` 内にインラインで書かれている `succeeded` → 自動返金ブロック
（`_payjp_cancel_refund_processed` 冪等ガード + `do_refund()` + 失敗時メモ/ログ）を
private メソッドに抽出し、(a) 最初の状態取得で `succeeded` だった場合、(b) レースフォールバック、
の両方から呼ぶ。ロジックの二重実装を避ける。

### D-3: #23 との整合

このフォールバックで返金すると `_payjp_cancel_refund_processed` = `'1'` が立つため、
後から遅延 succeeded webhook が届いても #23 のアラートは抑止される（誤報にならない）。
両 Issue のガードメタが同一キーであることが整合の前提 — **キー名を変えないこと**。

### D-4: 再取得も失敗した場合・succeeded 以外だった場合は現状動作を維持

再取得の `RuntimeException`、または再取得結果が `succeeded` 以外（`canceled` 等に落ち着いた、
まだ `processing` のまま等）の場合は、既存の「Payment cancellation failed」メモ + `log_error()` を維持する。
再取得で得た状態はログの context に含める。

## 変更ファイル一覧

| ファイル | 変更 |
|---------|------|
| `includes/gateways/payjp/class-wc-gateway-payjp.php` | `cancel_payment_flow()` のリファクタ + フォールバック追加 |
| `tests/Unit/GatewayCancelTest.php` | レースフォールバック系テスト追加 |

## 実装ステップ

### Step 1: `succeeded` → 自動返金ブロックのメソッド抽出

`cancel_payment_flow()` の `if ( 'succeeded' === $status )` ブロック（現 601〜627 行付近）の中身を
private メソッドへ移動:

```php
/**
 * Issue the cancellation-time automatic full refund for a captured (succeeded) flow.
 *
 * Guarded by the `_payjp_cancel_refund_processed` order meta so repeated
 * cancellations (or the race fallback) never refund twice.
 *
 * @param WC_Order $order      WooCommerce order object.
 * @param string   $note_label Gateway-specific label for order notes, e.g. "PAY.JP".
 */
private function refund_succeeded_flow_on_cancel( WC_Order $order, string $note_label ): void {
```

中身は既存コードをそのまま移す（冪等ガード → `do_refund()` → 失敗時メモ + `log_error` /
成功時 `_payjp_cancel_refund_processed` 保存）。挙動変更はしない。
元の `if ( 'succeeded' === $status )` 分岐は `$this->refund_succeeded_flow_on_cancel( $order, $note_label ); return;` に置換。

### Step 2: cancel 失敗時のフォールバック

cancel POST の `catch ( RuntimeException $e )` ブロック（現 652 行付近）を次の構造に変更:

```php
} catch ( RuntimeException $e ) {
    // The flow may have transitioned to 'succeeded' between the status fetch and
    // the cancel call (PayPay confirms asynchronously). Re-fetch once and fall
    // back to the automatic refund so PAY.JP and WooCommerce stay consistent.
    $refetched_status = '';
    try {
        $refetched        = $this->get_api()->get( '/payment_flows/' . rawurlencode( $flow_id ), $order_id );
        $refetched_status = isset( $refetched['status'] ) && is_string( $refetched['status'] ) ? $refetched['status'] : '';
    } catch ( RuntimeException $refetch_e ) {
        // Fall through to the generic failure note below.
    }

    if ( 'succeeded' === $refetched_status ) {
        $order->add_order_note(
            sprintf(
                /* translators: %s: Gateway label (e.g. "PAY.JP"). */
                __( '%s: Payment completed while the order was being cancelled; issuing an automatic refund instead.', 'payjp-for-wc' ),
                esc_html( $note_label )
            )
        );
        $logger->log_event(
            'cancel_race_refund_fallback',
            $order_id,
            array( 'flow_id' => $flow_id )
        );
        $this->refund_succeeded_flow_on_cancel( $order, $note_label );
        return;
    }

    // 既存の失敗メモ + log_error（context に $refetched_status を追加）
    ...
}
```

既存の失敗時メモ文言・`log_error()` はそのまま維持し、ログ context に
`'refetched_status' => $refetched_status` を加える。

### Step 3: DocBlock 更新

`cancel_payment_flow()` の DocBlock「Status handling」に 1 行追加:

```
 *   - cancel failure → re-fetch once; if the flow raced to 'succeeded',
 *     fall back to the automatic full refund
```

## テスト計画（`tests/Unit/GatewayCancelTest.php` に追加）

既存パターンを踏襲: `Mockery::mock( WC_Gateway_Payjp_Card::class )->makePartial()` +
`get_api` モック + `make_order()` ヘルパー。

| # | テスト | 検証内容 |
|---|--------|---------|
| 1 | 初回 GET = `processing` → cancel POST が throw → 再 GET = `succeeded`（返金ガード空） | `/payment_refunds` が 1 回呼ばれ、`_payjp_cancel_refund_processed` が `'1'` で保存、レース説明メモ + 返金メモが追加される |
| 2 | 同上だが `_payjp_cancel_refund_processed` = `'1'` 済み | `/payment_refunds` は呼ばれない（冪等）。レース説明メモは追加される |
| 3 | cancel POST throw → 再 GET = `requires_action`（まだ未確定） | 返金なし。既存の失敗メモ 1 回のみ |
| 4 | cancel POST throw → 再 GET も throw | 返金なし。既存の失敗メモ 1 回のみ |
| 5 | 既存テスト全件 | リファクタ（メソッド抽出）後もグリーンであること |

注意: 既存テスト `cancel_adds_error_note_when_cancel_api_throws` は cancel throw 後に
再 GET が走るようになるため、`$this->api->shouldReceive( 'get' )` の期待回数を
1 → 2 回に更新する必要がある（1 回目 = 初回状態取得、2 回目 = フォールバック再取得）。

## 受け入れ基準

1. cancel とフロー成功がレースした場合、キャンセル処理内で全額返金まで完了し、返金 ID がメモに残る
2. その後に遅延 succeeded webhook や再キャンセルが走っても二重返金・誤アラート（#23）が発生しない
3. レース以外の cancel 失敗（ネットワークエラー等で再取得も未確定）は従来どおりの失敗メモ/ログ
4. 既存の `GatewayCancelTest` 全件がリファクタ後もパス
5. `vendor/bin/phpcs --standard=phpcs.xml .` エラー・警告 0 件
6. `vendor/bin/phpstan analyse --memory-limit=1G` エラー 0 件
7. `vendor/bin/phpunit` 全件パス

## 実装時の必須手順（CLAUDE.md 準拠）

1. 実装前に `payjp-v2-woocommerce` / `wc-development` スキルを呼び出す
2. PHP 編集後: `vendor/bin/phpcbf` → `vendor/bin/phpcs` → `vendor/bin/phpstan` → `vendor/bin/phpunit`
3. `docs/review-checklist.md` の 10 項目セルフレビュー
4. **`git commit` / `git push` は絶対に行わない**（ユーザーが手動で実施）。変更内容の説明を出力して終了する
