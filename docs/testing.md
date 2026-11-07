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

## i18n（.pot/.po 再生成）

ローカルに `wp` コマンドが無くても、起動中の wp-env CLI コンテナ経由で `wp i18n` 系コマンドを実行できる。

```bash
# CLI コンテナ名を確認（末尾が -cli-1。-tests-cli-1 ではない方）
docker ps --format '{{.Names}}' | grep -- '-cli-1$' | grep -v tests

# プラグインディレクトリ内で .pot を再生成
docker exec -w /var/www/html/wp-content/plugins/payjp-for-wc <cli-container-name> \
  wp i18n make-pot . languages/payjp-for-wc.pot --domain=payjp-for-wc --exclude=vendor,node_modules,tests,build
```

続けてローカルで `.po` を更新し `.mo` を再コンパイルする（`msgmerge`/`msgfmt` は Homebrew の `gettext` に含まれる）:

```bash
msgmerge --update --backup=none --no-fuzzy-matching languages/payjp-for-wc-ja.po languages/payjp-for-wc.pot
msgfmt -o languages/payjp-for-wc-ja.mo languages/payjp-for-wc-ja.po
```

`msgattrib --untranslated languages/payjp-for-wc-ja.po` で未翻訳エントリを確認できる。

## wp-env の注意点

- 開発環境は `http://localhost:8892`（テスト環境は `:8893`）。
- 新規インストールは `woocommerce_coming_soon` が `yes` になっており
  フロントエンドがブロックされる。
  `wp option update woocommerce_coming_soon no` で解除する。
- 開発補助プラグインを同梱している（`.wp-env.json` で自動インストール・有効化）:
  Plugin Check / WP Mail Logging / WP Crontrol / Debug Bar / Query Monitor。
  メール送信は WP Mail Logging、cron ジョブは WP Crontrol、
  クエリ・フックのデバッグは Query Monitor で確認できる。

## WC を特定バージョンに固定して確認する

`WC tested up to` を更新する前に使う。`.wp-env.json` の `woocommerce.zip` はベータ版を落とすことがあり、
`wp plugin install --force` はマウント先を壊すおそれがあるので、gitignore 済みの `.wp-env.override.json` で差し替える。

1. `https://downloads.wordpress.org/plugin/woocommerce.X.Y.Z.zip` を scratchpad に展開する
2. `.wp-env.override.json` の `plugins` に `.wp-env.json` の一覧を写し、WC の URL だけを展開先の絶対パスに替える
   （override は一覧を丸ごと置き換える。ディレクトリ名は `woocommerce` のままにする）
3. `npm run env:start` のあと、`wp plugin list` で WC のバージョンを確かめる
4. テストキーがダミーなら、`pre_http_request` で `PAYJP_API_BASE` 宛ての応答を返す一時 mu-plugin を
   `docker cp` で `wp-content/mu-plugins/` に置く
5. Store API で注文 → order-pay を表示 → `/?payjp-return=1&order_id=<ID>&key=<注文キー>` へアクセスし、
   注文が `processing` になること、`wp-content/debug.log` に新しいエラーが無いことを確かめる
6. テスト注文、mu-plugin、`.wp-env.override.json` を削除し、`npm run env:start` で元の WC に戻す

- `localhost` が IPv6 で別のプロセスに届く環境では `curl -4` を使う。
- 古い WC へ差し替えても DB は戻らず、`woocommerce_version` は新しい版のまま残る。結果を報告するときに書き添える。
- 影響の見当は、前後の ZIP の `src/StoreApi/` と `includes/class-wc-checkout.php` を `diff` すると付けやすい。

## PAY.JP v2 ドキュメント

| リソース | URL |
|---------|-----|
| ガイド | https://docs.pay.jp/v2/guide |
| API リファレンス | https://docs.pay.jp/v2/api |
| LLM 向け全文 | https://docs.pay.jp/v2/llms-full.txt |

個別ページ MDX: 各ページ URL に `.mdx` を付与（例: `https://docs.pay.jp/v2/guide/payments/checkout.mdx`）
