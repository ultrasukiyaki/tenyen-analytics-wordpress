# Changelog

## 0.6.0 - 2026-07-25

### Added
- Added an asynchronous administrator-only session list with ordered raw-event journeys.
- Added anonymous browser visitor summaries and historical session navigation.
- Added content entries, exits, bounces, bounce rate, exit rate, sessions, and pageviews-per-session metrics.

### Changed
- Added access-history navigation to related session and anonymous visitor details.
- Added responsive long-value wrapping and keyboard-accessible detail dialogs.
- Updated English and standard-Japanese documentation and interface translations.

### Security
- Restricted all session and visitor analytics REST routes to users with `manage_options`.

### Compatibility
- No schema or index migration is required; existing v0.5.7 events and settings are preserved.
- Events without a stored session ID remain available in Access History and are excluded from session analytics.

## 0.5.7 - 2026-07-24

### Fixed

- Fixed PHP translation expressions appearing as literal text in headings, filters, buttons, and help text throughout the access-history screen.
- Added a rendering regression check to prevent untranslated PHP expressions from leaking into the access-history HTML.

### 修正

- アクセス履歴画面の見出し、フィルター、ボタン、ヘルプ文にPHP翻訳関数の式がそのまま表示される問題を修正しました。
- PHP式がアクセス履歴のHTMLへ漏れ出す問題の再発を防ぐ描画テストを追加しました。

## 0.5.6 - 2026-07-23

### Fixed

- Fixed long User-Agent, URL, and identifier values overlapping adjacent fields in the expanded access-history detail view.
- Improved responsive wrapping for access-history detail content.

### 修正

- アクセス履歴の詳細表示で、長いUser-Agent、URL、識別子が隣接項目へ重なる問題を修正しました。
- 詳細表示の折り返しとレスポンシブ表示を改善しました。

## 0.5.5

- Renamed the main plugin class to `TYA_Plugin` and its file to `includes/class-tya-plugin.php`.
- Converted the administration UI and scripts to an English gettext source with bundled standard-Japanese translations.
- Added an asynchronous, administrator-only WordPress Dashboard widget with a private 60-second server cache and manual refresh.
- Added shared plugin-page and widget credits without adding output to the public frontend.
- Added English and Japanese documentation, WordPress.org readme, security and contribution policies, GPL v2 licensing, CI, and reproducible release tooling.
- Preserved existing tables, options, event payloads, routes, classification behavior, and uninstall behavior. No database migration.

## 0.5.2

- 従来の縦長ダッシュボードを、機能別のWordPressサブメニューへ分割。
- ダッシュボード、リアルタイム、アクセス履歴、コンテンツ、流入元、ASN・組織、ユーザー環境、エンゲージメント、システム、設定を追加。
- ダッシュボードを概要確認向けに短縮し、詳細分析を専用画面へ移動。
- 非同期アクセス履歴を独立画面化し、専用画面では初期状態から一覧を表示。
- ページごとに必要な管理画面アセットだけを読み込む構成へ変更。
- 内蔵MMDB Readerを追加し、Composerと公式MaxMind Readerを任意依存へ変更。
- 不完全または古い`vendor/autoload.php`があっても、本体クラスの読込みを継続できるよう改善。
- DBスキーマ変更なし。

## 0.5.0

- アクセス詳細履歴を非同期REST取得へ変更。
- 履歴セクションの折り畳み、コンパクト表示、25件初期表示を追加。
- 検索・日付・イベント・訪問者・国・ブラウザ・OS・端末・並び順の非同期フィルタを追加。
- 表示列、密度、折り返し、固定ヘッダー、自動更新をlocalStorageへ保存する設定ペインを追加。
- 行詳細を独立した展開行へ変更。
- DBスキーマ変更なし。

## 0.4.1

- WordPressの`wpdb::prepare()`と`DATE_FORMAT()`内の`%`が衝突し、期間推移が0表示になる不具合を修正。
- 時間・日・月バケット生成を`DATE`／`HOUR`／`YEAR`／`MONTH`式へ変更し、集計SQLを安定化。
- DBスキーマ変更なし。

## 0.4.0

- 記事・固定ページ・参照元・対象URLを安全な別タブリンクに変更。
- 今日、昨日、7日、30日、今月、カスタム期間の分析フィルターを追加。
- PV、推定UU、セッション、平均滞在、平均スクロール、Botイベントの期間集計を追加。
- 時間別・日別・月別のPV／UU／セッション推移グラフを追加。
- ブラウザ、OS、端末、国、ASN／組織の構成グラフを追加。
- グラフを外部依存のないローカルCanvas実装にした。
- 人間のみ、Botのみ、すべての分析対象切替を追加。
- DBスキーマ変更なし。

## 0.3.1

- Fixed an update issue where only the plugin version changed while the old dashboard class remained active.
- Added cache-busting unique PHP filenames for the organization insight UI.
- Added a visible UI build badge for deployment verification.

## 0.3.0

- ASN組織名を研究・教育、官公庁、企業、ISP、クラウド、VPN・プロキシ候補、Botへ自動分類。
- 管理画面へ組織カテゴリーの色分けバッジを追加。
- 研究機関・官公庁・企業を表示する「注目組織アクセス」を追加。
- pageviewとengagementを統合した「最近の閲覧」を追加。
- 人気記事・注目組織の直近7日ランキングを追加。
- 生ログ行へ滞在時間、スクロール率、セッションID等の展開詳細を追加。
- ASN単位で組織分類を上書きできる設定を追加。

## 0.2.0

- アクセス履歴を25／50／100件単位でページング。
- URL、記事名、参照元、地域、ASN組織名、ブラウザ・OS等の部分一致検索を追加。
- 保存済みHMACを使った生IPの完全一致検索を追加。
- イベント種別、人間／Bot、サイト設定タイムゾーン基準の日付範囲フィルターを追加。

## 0.1.0

- WordPress向け初回リリース。
