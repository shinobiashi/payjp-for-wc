# dev-cycle 最終報告: fix/jpy-only-currency

## 開発内容
- タスク: issue #38 JPY 以外の通貨で PAY.JP を隠し、決済を止める
- PR: #39 https://github.com/shinobiashi/payjp-for-wc/pull/39
- 承認された計画の要約: `is_available()`・`process_payment()`・定期更新の 3 箇所で非 JPY を止め、設定ページに通知を出し、readme の FAQ に明記する。決済確定時の照合は別 issue に分ける
- 計画からの追加: なし
- 設計ドキュメントからの逸脱: なし(`docs/architecture.md` に「通貨は JPY のみ(#38)」節を追加)

| sha | メッセージ |
|---|---|
| 47408e4 | fix: block PAY.JP payments when the currency is not JPY |
| e1ef525 | chore: add translations for the JPY-only messages |
| 274479d | docs: state that only JPY is supported |
| a4e100e | test: pin the store currency fallback and correct the guard rationale |
| 4b68cf1 | docs: record review-loop rounds for the JPY-only fix |
| 0a20a40 | docs: record dev-cycle gate round 1 |
| d59d917 | docs: record dev-cycle gate round 2 |

## review-loop(PR 前)
| ラウンド | 指摘 | 修正 | backlog |
|---|---|---|---|
| R1 | Critical 0 / High 0 / Medium 2 / Low 6 / 対象外 1 | 2 | 7 |
| R2 | 新規 0(R1 の Medium は解消) | 0 | 0 |

## Codex / Copilot ゲート
| ラウンド | bot | 新規指摘 | 修正 | 保留 | 状態 |
|---|---|---|---|---|---|
| G1 | Codex | 0 | 0 | 0 | 収束 |
| G1 | Copilot | 3 | 0 | 3 | 未収束 |
| G2 | Copilot | 1(本文のみ) | 0 | 1 | 未収束 |
| G3 | Copilot | 1(本文のみ) | 0 | 1 | 上限 |

### 修正した指摘
なし。

### 修正しなかった指摘
G1-1〜G1-3 は PR 上で未解決のまま残してある。G2-1・G3-1 はレビュー本文のみでスレッドが無い。

| ID | bot | 重大度 | 理由 | スレッド |
|---|---|---|---|---|
| G1-1 | Copilot | Low | 誤検知。`array()` はスタブの既存コードと同じで、プロジェクトの標準も `array()` | https://github.com/shinobiashi/payjp-for-wc/pull/39#discussion_r4490710094 |
| G1-2 | Copilot | Low | 誤検知。`[]` にすると PHPCS がエラーになる | https://github.com/shinobiashi/payjp-for-wc/pull/39#discussion_r4490745481 |
| G1-3 | Copilot | Low | G1-1 と同じ | https://github.com/shinobiashi/payjp-for-wc/pull/39#discussion_r4490745558 |
| G2-1 | Copilot | Low | 誤検知。`absint()` は Brain Monkey が定義している | https://github.com/shinobiashi/payjp-for-wc/pull/39#pullrequestreview-5798099895 |
| G3-1 | Copilot | Low | 欠陥ではなく、実際の WooCommerce Subscriptions での確認の推奨。環境が無く未確認 | https://github.com/shinobiashi/payjp-for-wc/pull/39#pullrequestreview-5798239638 |

## 品質ゲート
- CI: https://github.com/shinobiashi/payjp-for-wc/actions/runs/42194878789 green(d59d917)
- 品質チェック: PHPCS / PHPStan level 5 エラー 0、PHPUnit 124 件 green、`npm run build` 成功
- ミューテーション: 通貨ガード 13 件をすべてテストが検出
- wp-env(WC 11.3.0-beta.2)の実機確認: 受け入れ条件 1・2・4(PR 本文の表を参照)
- 未確認: WooCommerce Subscriptions の実環境での定期更新(単体テストのみ)

## 次にできること(人間の判断)
- WooCommerce Subscriptions の環境で非 JPY の更新が失敗することを確認する(G3-1)
- 決済確定時の金額・通貨の照合を別 issue として起票する(`docs/review-backlog.md` の X1)
- `.github/instructions/woocommerce-payjp.instructions.md:37` の「`[]` 必須」を `phpcs.xml` に合わせて直す。Copilot が同じ誤検知を繰り返す原因(backlog の G1)
- Block Checkout の保存カードの制限を直すか判断する(backlog の R1-L2。実機で再現済み)
- マージ(GitHub 上で人間が行う)→ マージ後は `/post-merge`
