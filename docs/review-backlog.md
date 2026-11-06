# レビューバックログ(今回対応しない指摘)
| 追記日 | ID | 重大度 | 場所 | 内容 | 起票状況 |
|---|---|---|---|---|---|
| 2026-11-05 | docs/readme-client-description R1-X1 | Medium | `includes/gateways/payjp/class-wc-gateway-payjp-card.php:312`, `:374` / `class-wc-gateway-payjp-paypay.php:179` / `class-wc-gateway-payjp.php:132` | 通貨が `jpy` 固定で、`is_available()` がストアの通貨を確認しない。JPY 以外のストアでも表示され、金額がそのまま円として送られうる。readme にも JPY のみとは書かれていない | #38（`fix/jpy-only-currency` で対応） |
| 2026-11-05 | docs/readme-client-description R1-X2 | Low | 運用（`docs/wporg/payjp-for-wc-stable-readme-ja.po`） | Stable Readme の新しい原文が GlotPress に現れるのは次のリリース以降。PO の取り込みはその後に行う。現行 1.0.0 用の訳は 6912bec の版 | 未起票 |
| 2026-11-05 | docs/readme-client-description R1-L1 | Low | `readme.txt:30` | 「Payment form embedded in your checkout page」は、新しいカードは order-pay ページで入力し、PayPay は外部へ遷移する実際の流れより狭く読める | 未起票 |
| 2026-11-05 | docs/readme-client-description R1-L2 | Low | `readme.txt:29`, `:33-36` | 英語の言い回しの改善案（Who Is It For? / Merchants … / Billing models） | 未起票 |
| 2026-11-05 | docs/readme-client-description R1-L3 | Low | `docs/wporg/payjp-for-wc-stable-readme-ja.po` | 「一部返金」（クライアント原文）と既存訳の「部分返金」の表記揺れ | 未起票 |
| 2026-11-05 | docs/readme-client-description R1-L4 | Low | `readme.txt` | 旧 Requirements の削除で「WooCommerce 9.0 以上」「PAY.JP アカウントが必要」が Description から消えた | 未起票 |
| 2026-11-05 | docs/readme-client-description R1-L5 | Low | `readme.txt:19` | タグラインの英訳「Making payment integration simple」は公式の英語コピーか未確認 | 未起票 |
| 2026-11-06 | docs/readme-client-description Copilot-1 | Low | `docs/wporg/payjp-for-wc-stable-readme-ja.po:258` | 訳の「基本運用操作に連携」が日本語として不自然という Copilot の指摘。クライアント指定の文面なので維持した。見直す場合は「基本的な運用操作に対応」などをクライアントに確認する | 未起票 |
| 2026-11-06 | fix/jpy-only-currency X1 | Medium | `includes/gateways/payjp/class-payjp-webhook-handler.php` / `class-wc-gateway-payjp.php` の `handle_return()` / `class-payjp-pending-payment-monitor.php` | 決済確定時にフローの `amount` / `currency` を注文と照合していない。#38 で入口は閉じたので JPY 以外のフローは新規に作られないが、改ざんや注文編集による金額不一致は確定経路で検知できない。不一致時の扱いを #22 / #23 / #25 のガードと合わせて設計する必要があるので別 issue に分けた | 未起票 |
