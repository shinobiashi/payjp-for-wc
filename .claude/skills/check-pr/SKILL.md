---
name: check-pr
description: 指定した PR の head を scratchpad の worktree に取得し、PHPCS / PHPStan / PHPUnit の 3 点チェックを実行して結果を報告する。外部コントリビューターの PR など CI が走らない PR のレビュー時に使う。「PR #30 をチェックして」「/check-pr 30」等で使用する。
---

# PR ローカル検証スキル（payjp-for-wc）

外部コントリビューターの PR は CI が走らないため、マージ判断の前にローカルで
プロジェクト標準の 3 点チェック（PHPCS / PHPStan / PHPUnit）を実行する。
作業ツリーを汚さないよう、PR の head は scratchpad 配下の一時 worktree に取得する。

## 引数

- **PR 番号**（必須）— 省略された場合は `gh pr list --state open` で候補を提示して確認する。

## 前提

- ローカルブランチは切り替えない・汚さない（worktree 方式）。
- フォークからの PR でもブランチ名ではなく **`pull/<N>/head`** で取得できる。
- コミット・プッシュはしない（CLAUDE.md 規約）。チェック結果の報告まで。

## 手順

### 1. PR 情報の取得

```bash
gh pr view <N> --json title,author,state,headRefOid,files,baseRefName
```

- `state` が `MERGED` / `CLOSED` なら、その旨を報告して続行するか確認する。
- 変更ファイル一覧を控える（PHP 以外に composer.json / package.json / JS を触っているか確認）。

### 2. PR head を worktree に取得

```bash
git fetch origin pull/<N>/head
git worktree add <scratchpad>/pr<N> FETCH_HEAD
```

- `<scratchpad>` はセッションの scratchpad ディレクトリ（システムプロンプト参照）。
- 検証の再現性のため、`headRefOid` と `git rev-parse HEAD` が一致することを確認する。

### 3. vendor の用意

composer install は遅いので、メインリポジトリの vendor をコピーして使う:

```bash
cp -R /Users/shoheitanaka/Dev/payjp-for-wc/vendor <scratchpad>/pr<N>/
```

- **例外**: PR が `composer.json` / `composer.lock` を変更している場合はコピーせず、
  worktree 内で `composer install` を実行する。

### 4. 3 点チェックの実行

worktree 内で順に実行する（`cd` はコマンドごとにリセットされる点に注意。
絶対パスか `cd <worktree> && ...` の複合コマンドで実行する）:

```bash
cd <scratchpad>/pr<N> && vendor/bin/phpcs --standard=phpcs.xml .
cd <scratchpad>/pr<N> && vendor/bin/phpstan analyse --memory-limit=1G --no-progress
cd <scratchpad>/pr<N> && vendor/bin/phpunit
```

- PR が JS / CSS を変更している場合は `npm ci && npm run lint:js` / `npm run lint:css` も追加する。
- PHPCS が落ちた場合、自動修正可能かは `phpcbf` の dry-run で判断できるが、
  **worktree 内のコードは修正しない**（修正は suggestion コメント等で PR 作者に返す）。

### 5. diff の目視レビュー

```bash
git diff <baseRefName>...FETCH_HEAD
```

`docs/review-checklist.md` の 10 項目と CLAUDE.md の決定事項（特に Webhook / ステータス遷移
まわりの #5〜#9）に照らして確認する。

### 6. 片付けと報告

```bash
git worktree remove --force <scratchpad>/pr<N>
```

報告に含めるもの:

- 3 点チェックの結果一覧（✅ / ❌ と件数）
- ❌ があれば該当ルール・ファイル・行と、修正方針の提案
- diff レビューで気づいた点（あれば）
- 検証した head の SHA（レビュー後に PR が更新されたら再実行が必要なため）

## 注意事項

- PR head が更新されたら（suggestion 適用等）、必ず再フェッチして再実行する。
  `gh pr view <N> --json headRefOid` で SHA の変化を確認できる。
- チェック結果を PR 本文のチェックリストやコメントに反映するのは、
  ユーザーに求められた場合のみ行う。
