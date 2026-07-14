# テスト環境リファレンス

> CLAUDE.md から参照される詳細資料。動作確認・決済テストの際に読むこと。

## PayPay テスト

- **テストアカウント**: 080-1111-5912 〜 080-1111-5921（10 アカウント）、
  パスワードは `Pay2test`、SMS認証コードは `1234` 固定。QRコードが出ても
  実機は不要 ―「QRコードをスキャンできない場合はこちら」リンクからブラウザ内で
  ログイン・決済まで完結できる。
- **テスト上限**: ¥100 / 回（テスト後に全額返金必須）

## カードテスト

- テスト API キー: `pk_test_xxx` / `sk_test_xxx`（v1 と共通）
- テストカード: `4242 4242 4242 4242`（Visa）、`5555 5555 5555 4444`（Mastercard）、
  `3530 1113 3330 0000`（JCB）。エラー用: `4000 0000 0000 0002` → `card_declined`
- 3DS: テストモードでは成否を選択できる専用認証画面が表示される

## Webhook テスト

- PAY.JP ダッシュボード > Webhook > テスト送信
- ローカル環境は外部からの Webhook 着信を受けられないため、非同期完了の検証は
  実際の Payment Flow に対しイベントを REST 経由で手動発火させる。

## wp-env の注意点

- 新規インストールは `woocommerce_coming_soon` が `yes` になっており
  フロントエンドがブロックされる。
  `wp option update woocommerce_coming_soon no` で解除する。

## PAY.JP v2 ドキュメント

| リソース | URL |
|---------|-----|
| ガイド | https://docs.pay.jp/v2/guide |
| API リファレンス | https://docs.pay.jp/v2/api |
| LLM 向け全文 | https://docs.pay.jp/v2/llms-full.txt |

個別ページ MDX: 各ページ URL に `.mdx` を付与（例: `https://docs.pay.jp/v2/guide/payments/checkout.mdx`）
