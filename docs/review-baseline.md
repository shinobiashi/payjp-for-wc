# レビューベースライン(許容済み指摘リスト)
レビュー時、ここに記載された項目は指摘しないこと。

## 形式
- [カテゴリ] 対象範囲 — 許容理由

## 許容項目
<!--
- [WPCS] (例)Yoda condition 非適用 — チーム規約で不採用
- [DB] (例)$wpdb 直接クエリは Repository クラス内に限り許容 — 抽象化済みのため
-->
- [PO] `docs/wporg/*.po` のヘッダーに `Last-Translator` / `Language-Team` が無い — GlotPress のエクスポートをそのまま保存しているため（`msgfmt -c` の警告は取り込みに影響しない）
