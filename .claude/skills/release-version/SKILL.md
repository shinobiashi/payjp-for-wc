---
name: release-version
description: PAY.JP for WooCommerce のバージョンを上げてリリース準備をする。バージョン番号を全箇所に反映し、前回リリース以降の変更から changelog / Upgrade Notice を作成し、必要なら Tested up to (WordPress / WooCommerce) も更新する。「バージョンを上げて」「0.9.6にアップデート」「リリース準備」等で使用する。
---

# バージョンリリーススキル（payjp-for-wc）

このプラグインをバージョンアップするたびに触る箇所は毎回同じ 4 ファイルで、
過去のリリースコミット（例: `f79d509` 0.9.4→、`d9ea11b`/`c38125b` 0.9.5→）が
そのままテンプレートになる。このスキルはその手順を再現する。

## 前提

- コミット・プッシュは**ユーザーが手動で行う**（CLAUDE.md 規約）。このスキルではコミットしない。
- ブランチは切って作業する（`release/<新バージョン>` 推奨）。
- PHP ファイル（`payjp-for-wc.php`）を編集するので、作業後は必ず PHPCS / PHPStan を実行する
  （CLAUDE.md の必須チェック）。
- `readme.txt` の `== Changelog ==` は最新版が一番上（降順）。過去の全エントリはそのまま残す。

## 手順

### 0. 新バージョン番号となる Tested up to の確認

- 現在のバージョンは `payjp-for-wc.php` の `Version:` ヘッダーから取得する。
- 新バージョン番号がユーザーから指定されていなければ、変更内容（バグ修正のみか新機能ありか）
  から patch/minor を判断して提案し、`AskUserQuestion` で確認する。
- WordPress / WooCommerce の Tested up to 更新も、ユーザーから具体的なバージョンが
  提示された場合のみ行う（自己判断で憶測のバージョンを書かない）。

### 1. 前回リリース以降の変更点を集める

前回のバージョンアップコミットを探して差分を取る:

```bash
git log --oneline --all --grep="^chore: \(update\|bump\) version" -i | head -5
git log --oneline <前回リリースコミット>..HEAD
```

ユーザー影響のある変更（`fix:` / `feat:`、PR番号付きのマージコミット）を中心に拾う。
`docs:` や内部リファクタのみのコミットは changelog に載せない。各コミットの詳細は
`git show <hash>` や `gh pr view <PR番号>` で内容を確認してから、readme.txt 既存エントリの
文体（`* Added: ...` / `* Fixed: ...` / `* Changed: ...`、エンドユーザー向けの平易な言葉、
内部の関数名やクラス名は出さない）に合わせて要約する。

### 2. バージョン番号を更新する

以下 4 箇所（`X.Y.Z` は新バージョン）:

```bash
# payjp-for-wc.php — 2箇所: ヘッダーコメントの Version と PAYJP_FOR_WC_VERSION 定数
# package.json — "version"
# package-lock.json — "version" 2箇所（トップレベルと packages[""]）
# readme.txt — Stable tag
```

### 3. readme.txt に changelog / Upgrade Notice を追記する

`== Changelog ==` の直後（既存の最新エントリの直前）に新バージョンのセクションを挿入:

```
= X.Y.Z =
* Added: ...
* Fixed: ...
```

`== Upgrade Notice ==` の直後にも 1〜3 文程度の要約を追記する
（過去の例: 「Recommended update.」「No functional changes. Safe to update.」等の締め）。

### 4. Tested up to を更新する（指定があれば）

ユーザーから新しい確認済みバージョンが提示された場合のみ、2箇所を更新:

```
readme.txt: Tested up to: <WPバージョン>
readme.txt: WC tested up to: <WCバージョン>
payjp-for-wc.php ヘッダーコメント: WC tested up to: <WCバージョン>
```

（`Requires at least` / `WC requires at least` / `Requires PHP` は最低動作要件なので、
Tested up to の更新とは別軸 — ユーザーから明示的な変更指示がない限り触らない）

### 5. 必須チェックを実行する

`payjp-for-wc.php` を編集したら必ず:

```bash
vendor/bin/phpcs --standard=phpcs.xml payjp-for-wc.php
vendor/bin/phpstan analyse --memory-limit=1G payjp-for-wc.php
```

### 6. i18n の更新漏れがないか確認する

前回リリース以降のコミットでユーザー向け文字列（`__()` 等）を追加・変更したのに
`languages/` が未更新なら、`update-i18n` スキルを先に実行してから続ける
（バージョン番号だけの変更なら不要）。

### 7. 差分をユーザーに提示する

コミットはせず、`git diff --stat` と変更内容の要約を出力して終了する。
ユーザーがコミット・プッシュした後、依頼があれば `gh pr create` で PR を作成する
（PR作成前にユーザーに確認する）。

## 検証

- [ ] 4ファイル全てで新バージョン番号が一致している
- [ ] `== Changelog ==` の新エントリが既存エントリの直前（最新が先頭）に入っている
- [ ] `== Upgrade Notice ==` にも新バージョンの要約がある
- [ ] Tested up to を更新した場合、readme.txt と payjp-for-wc.php ヘッダーの両方が揃っている
- [ ] PHPCS / PHPStan がエラー0件
- [ ] コミット・プッシュはしていない（ユーザーの手動作業として残す）

## 失敗モード

| 症状 | 原因 |
|------|------|
| package-lock.json の version が1箇所しか変わっていない | トップレベルと `packages[""]` の2箇所ある。両方 sed/Edit すること |
| changelog に内部実装の話（クラス名・メタキー名）が混ざる | エンドユーザー向けではない。既存エントリの文体を見て平易な言葉に言い換える |
| Tested up to を憶測で更新してしまう | ユーザーから明示されたバージョン以外は書かない。動作確認していない値を書くと wordpress.org 審査・利用者双方に対して不正確な申告になる |
| WC tested up to が readme.txt にしかない | `payjp-for-wc.php` のヘッダーコメントにも同じ値がある。片方だけ直すと不整合になる |
