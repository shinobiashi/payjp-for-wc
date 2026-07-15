---
name: update-i18n
description: PAY.JP for WooCommerce の翻訳ファイル（POT / ja.po / ja.mo / JSON）を更新する。ユーザー向け文字列を追加・変更した PR で必ず実行する。POT 再生成 → msgmerge → 日本語訳追加 → MO 再生成 → 検証まで行う。
---

# i18n 更新スキル（payjp-for-wc）

ユーザー向け文字列（`__()` / `esc_html__()` / `_e()` 等でラップされた文字列）を
追加・変更したら、**同じ PR 内で** `languages/` 配下を更新する（CLAUDE.md 規約）。
POT 更新漏れは過去に実際に発生している（#24 の 2 文字列が #25 対応まで未収録だった）。

## 前提

- テキストドメイン: `payjp-for-wc`
- 対象ファイル: `languages/payjp-for-wc.pot` / `payjp-for-wc-ja.po` / `payjp-for-wc-ja.mo`
- `msgmerge` / `msgattrib` / `msgfmt` は homebrew の gettext で導入済み
- **この開発機に wp-cli は未インストール**。`npm run i18n:pot` は `wp` コマンド前提のため
  そのままでは動かない → 手順 1 の phar フォールバックを使う
- コミットはユーザーが手動で行う（このスキルではコミットしない）

## 手順

### 1. POT 再生成

`wp` コマンドがあれば:

```bash
npm run i18n:pot
```

無ければ scratchpad に wp-cli.phar を取得して実行（リポジトリ内に phar を置かないこと）:

```bash
curl -sL -o <scratchpad>/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
php <scratchpad>/wp-cli.phar i18n make-pot . languages/payjp-for-wc.pot \
  --domain=payjp-for-wc --exclude=vendor,node_modules,tests,build
```

`git diff languages/payjp-for-wc.pot | grep '^+msgid'` で追加された msgid を確認する。
**今回の変更分以外の msgid が現れた場合は過去の更新漏れ**なので、それらも今回まとめて
翻訳対象に含める（見送る場合はユーザーに報告する）。

### 2. ja.po へマージ

```bash
msgmerge --update --backup=none --no-fuzzy-matching languages/payjp-for-wc-ja.po languages/payjp-for-wc.pot
msgattrib --untranslated languages/payjp-for-wc-ja.po | grep '^msgid'
```

`--no-fuzzy-matching` は必須（fuzzy 訳の混入防止）。

### 3. 日本語訳を追加

未翻訳エントリの `msgstr` を埋める。既存訳のトーンに合わせること:

- 注文メモ・管理者向け: 「PAY.JP: 〜されました。」調（例: `PAY.JP: Webhook経由で決済が確認されました。`）
- プレースホルダー（`%s` / `%1$s`）は訳文にも必ず残す
- 80 桁前後で PO の複数行文字列に折り返す（既存エントリの体裁に合わせる）
- 次のエントリは**プラグインメタデータなので未翻訳のまま残す**:
  プラグイン URL・作者名（`Shohei Tanaka`）・作者 URL

### 4. MO 再生成

```bash
msgfmt --check -o languages/payjp-for-wc-ja.mo languages/payjp-for-wc-ja.po
```

`--check` でフォーマット検証を兼ねる。エラーが出たら PO を修正して再実行。

### 5. JS 翻訳 JSON（JS 文字列を変更した場合のみ）

`src/` 配下の JS で `@wordpress/i18n` の文字列を追加・変更した場合のみ:

```bash
npm run i18n:json   # wp i18n make-json + scripts/merge-block-translations.js
```

PHP 文字列のみの変更では実行しない（不要なファイル変更をしない）。

## 検証

- [ ] `git diff languages/payjp-for-wc.pot` に今回の新規文字列がすべて含まれる
- [ ] `msgattrib --untranslated` の残りがプラグインメタデータのみ
- [ ] `msgfmt --check` がエラーなしで完了し、`.mo` が更新されている
- [ ] `msgattrib --only-fuzzy languages/payjp-for-wc-ja.po` が空（fuzzy 訳なし）

## 失敗モード

| 症状 | 原因 |
|------|------|
| `npm run i18n:pot` が `wp: command not found` | wp-cli 未導入 → 手順 1 の phar フォールバック |
| msgmerge 後に訳が `#, fuzzy` になる | `--no-fuzzy-matching` を付け忘れ → PO を戻してやり直す |
| 翻訳したのに表示されない | `.mo` 未再生成（手順 4 漏れ）、または JS 文字列で JSON 未更新（手順 5） |
| POT に想定外の msgid 差分が大量に出る | 行番号参照の更新のみなら正常。msgid 自体の増減は文字列変更の反映 |
