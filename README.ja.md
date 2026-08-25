[English](README.md) | 日本語

# Tenyen Analytics for WordPress

## 概要

Tenyen Analyticsは、ページビュー、推定ユニーク訪問者、セッション、エンゲージメント、参照元、利用環境、GeoLite2の地域・ASN情報、Bot判定をWordPress内で集計するセルフホスト型プラグインです。外部のアクセス解析サービスは必要ありません。

![Tenyen Analyticsのダッシュボード](screenshot_dashboard.png)

## 機能

- ページビュー、推定UU、セッション、滞在時間、スクロール率、外部クリック、ダウンロード
- Human／Botフィルターとローカル描画のグラフ
- 生IPアドレスの暗号化保存とHMACによる完全一致検索
- GeoLite2 City／ASNのローカル参照と任意のMaxMind公式Reader
- 非同期アクセス履歴とコンパクトなWordPressダッシュボードウィジェット
- MaxMindの組織名を変更せずに表示する注目組織カテゴリー
- 管理者用の別名、プレーンテキストメモ、再利用可能なタグ、組織ウォッチリスト、非公開の保存ビュー
- 将来の収集を止める除外、履歴を削除しない分析除外、ルール診断
- 分割CSV／JSONエクスポート、設定可能な生データ保持、再開可能な削除、ストレージ診断
- 保持に耐えるUTC日次集計、再開可能な再構築、raw／aggregate混在の長期レポート

## 要件

- WordPress 6.2以上
- PHP 8.1以上

## インストール

「プラグイン → 新規追加 → プラグインのアップロード」からリリースZIPをアップロードするか、`tenyen-analytics-wordpress`ディレクトリを`wp-content/plugins/`へ配置して有効化します。

## GeoLite2の設定

GeoLite2のMMDBファイルは同梱されません。サイト管理者が[MaxMindの利用条件](https://www.maxmind.com/)に従って`GeoLite2-City.mmdb`と`GeoLite2-ASN.mmdb`を取得し、`wp-content/uploads/tenyen-analytics/`へ配置するか、設定画面でパスを指定してください。ファイルがなくても基本的な収集は動作します。

## ダッシュボードとレポート

Tenyen Analyticsメニューには、ダッシュボード、リアルタイム、アクセス履歴、セッション、コンテンツ、参照元、ASN／組織、ナレッジ、除外、データライフサイクル、利用環境、エンゲージメント、システム、設定があります。WordPress標準ダッシュボードのウィジェットは非同期で集計し、`manage_options`権限を持つ管理者だけに表示されます。

## プライバシーとセキュリティ

生IPアドレスは復号可能な方式で暗号化され、完全一致検索にはHMAC値を使います。保護鍵はWordPressのSaltから派生するため、Saltを変更すると過去の暗号化IPを復号できなくなります。GeoLite2はローカルで参照します。ASN組織名はアドレス範囲の登録組織を示すもので、訪問者の勤務先や所属を証明しません。管理者は収集内容と保持期間をプライバシーポリシーへ記載してください。アンインストールしても、管理者が手動で削除しない限り解析データは保持されます。

## 更新

WordPressをバックアップしてから、新しいプラグインをアップロードまたは上書きしてください。uploads内のGeoLite2ファイルは保持してください。v0.7.1は2つの集計tableを追加し、schemaを0.6.3から更新します。既存event row、注釈、tag、watchlist、保存view、除外、設定、GeoLite file、keyは維持します。

## エクスポート、保持、削除

管理者専用の「データライフサイクル」画面では、生のアクセス／イベントログ、セッション、コンテンツ概要、組織、流入元、キャンペーン、イベント概要をCSVまたはJSONで出力できます。エクスポートは一定件数ずつ取得し、適用可能な日付、Human／Bot、流入、キャンペーン、イベント、コンテンツ、国／地域、ASN／組織、タグ、ウォッチリスト、分析除外フィルターを反映します。CSVでは表計算ソフトの数式として解釈される先頭文字を無害化します。JSONは`schema`、`dataset`、`generated_at`、`columns`、`rows`を持つ`tenyen-analytics.export.v1`形式です。

IPアドレスは初期状態で出力しません。マスク出力ではIPv4の最終オクテットを0にし、IPv6は先頭48bitだけを保持します。復号した生IPの出力には`manage_options`権限、有効なnonce、生IPモードの選択、独立した明示確認チェックが必要です。

保持期間は無期限、30／90／180／365日、または1～3,650日の検証済みカスタム値に対応します。削除プレビューではUTC日境界のcutoff、対象event／session数、aggregate coverageを表示します。削除は1回最大1,000eventで、重複実行lockを使い、継続実行でも同じcutoffと削除済み件数を保持し、残りをWP-Cronへ予約します。対象となる全UTC日について、元件数、最大event ID、分析除外signatureがrawと一致する最新aggregateがなければ削除を遮断します。削除成功後は保存済みaggregate境界を固定します。設定、注釈、tag、保存view、除外rule、GeoLite file、keyは削除しません。

ストレージ診断には解析テーブル／データベース容量、生イベント／セッション数、最古／最新日時、最大24か月の月別件数、現在の保持期間、削除状態を表示します。

## 日次集計と長期レポート

WP-Cronは完了済みUTC日を段階的に集計し、遅延eventに備えて直近日も再確認します。管理者は「データライフサイクル」から1日または最大730日の検証済み期間を再構築できます。1回に1日を処理し、checkpointを記録し、削除とは別のlockを使ってWP-Cronで再開します。状態には対象期間、checkpoint、次回実行、失敗状態、rawとaggregateの元データ標本照合を表示します。

日次合計はpageview、event、推定visitor／session、bounce、entry、exit、engaged time、有効なscroll合計と標本数、Bot event、固定サイズで結合可能なvisitor／session sketchを保持します。上限付き日次dimensionはcontent、organization、traffic channel、referrer domain、campaign、event、country、browser、OS、deviceを対象にします。organizationは1日100件までとし、ほかの高カーディナリティdimensionにも明示的な上限があります。

reportは完了済みのaggregate日だけを選び、未集計または日途中の範囲をrawから取得します。両者は重複しません。rateとmeanは加算可能な分子／分母から再計算します。realtime、access history、個別session、個別visitor、生event／session exportは保持中のraw rowに依存します。削除後に分析除外を変更しても固定済みの過去aggregateは書き換えられず、coverage承認時の除外方針を維持します。

## 除外ルール

管理者専用の「除外」画面では、IPv4／IPv6完全一致、CIDR、パス完全一致、パス前方一致、管理者／自己アクセス、Bot、国、地域、ASN、組織／カテゴリー、ブラウザー、OS、端末、参照元ドメイン、UTM source／medium／campaignのルールを管理できます。収集ルールは一致する今後のリクエストを保存しません。分析ルールは保存済み行を削除せず、レポート、履歴、セッション、ウィジェットから非表示にします。CIDR、管理者、組織カテゴリーは、現在の履歴スキーマでは範囲を限定したSQL処理ができないため収集専用です。

ルールは種別の優先順位、続いてルールIDの順で決定します。診断フォームでは最初に一致したルール、優先順位、処理、理由を確認できます。ルール値とメモは長さを制限したプレーンテキストで、管理RESTルートには`manage_options`権限と有効なWordPress REST nonceが必要です。既存の「管理者アクセスを除外」と「Botも記録」設定は収集制御として維持します。除外管理が過去データを自動削除することはありません。

## 管理者ナレッジ

「ナレッジ」画面では、別名、メモ（最大4,000文字）、タグ（最大50文字、1対象あたり50件）、ウォッチ対象のASN組織、非公開の保存ビューを管理します。対象の安定キーには、数値ASN、既存の匿名訪問者ID、投稿IDまたは正規コンテンツパス、正規化した参照元ドメイン、ファーストタッチUTM 5項目の決定的ハッシュ、正規化した外部リンク先ドメインを使います。別名で生の値を上書きしません。元データがなくなった注釈も管理できます。

ウォッチはASNを印付けして絞り込むだけで、通知は送らず、解析事実も変更しません。表示されたASN／組織に登録されたIPアドレスからアクセスが観測されたことを示すもので、個人、勤務、所属、意図を特定または証明しません。保存ビューはWordPress管理者ごとの非公開データです。相対日付は読み込み時に再計算し、カスタム日付は絶対日付を保持します。ピン留めとレポートごとに1件の既定状態を保存/APIモデルで扱います。既存の保持方針に従い、アンインストールしてもメタデータテーブルと除外ルールテーブルを保持します。通知は今後の対象です。

## 流入帰属とイベント

各セッションは、最初に保存されたページビューのファーストタッチ帰属を使用します。UTMのsource、medium、campaignがあればCampaign、それ以外はDirect、Internal、Organic Search、Social、Referral、Unknownへ分類します。`utm_source`、`utm_medium`、`utm_campaign`、`utm_content`、`utm_term`に対応します。

外部リンクと設定済み拡張子のダウンロードを自動記録し、ダウンロードを外部クリックとして重複計上しません。内部リンクと一般ボタンの記録は初期状態で無効です。フォームは設定または`data-tenyen-track`で明示した場合のみ保守的に記録し、入力値は収集しません。WordPressの404では、曖昧なページビューではなく`not_found`を記録します。

独自連携では次のAPIを使用できます。

```javascript
window.TenyenAnalytics.trackEvent('radio_play', {station: 'example-station', server: 'primary'});
window.TenyenAnalytics.trackEvent('stream_server_change', {server: 'backup'});
window.TenyenAnalytics.trackEvent('feature_used', {area: 'header'});
```

戻り値は送信対象としてローカルで受理したかを示し、配送はベストエフォートです。名前、メタデータのキー数、scalar値、長さを制限し、関数、DOMノード、入れ子、循環値を拒否します。この例だけでラジオ連携が自動導入されることはありません。

## セッションと訪問経路

管理者専用の「セッション」画面では、保存済みセッションの一覧と、時系列のイベント経路を非同期で確認できます。保存済みの`session_id`を正規の識別子として使用し、セッションIDがない過去イベントはアクセス履歴には残りますが、推測によるセッション統合は行いません。匿名訪問者の概要はブラウザー依存の`visitor_id`を使用するため、実在する個人の同一性を証明するものではありません。

エンゲージ時間は、セッションとパスごとに累積送信された値の最大値を使用します。直帰はページビューが1件だけのセッションです。コンテンツの直帰率は「直帰した入口セッション数 ÷ 入口セッション数」、離脱率は「そのページで終了したセッション数 ÷ そのページのページビュー数」です。これらは推定指標で、分母が0の場合は0として表示します。

## トラブルシューティング

「Tenyen Analytics → システム」で収集エンドポイントとGeoLite2の状態を確認できます。地域やASNが空の場合はMMDBのパスと読み取り権限を確認してください。イベントが記録されない場合は、キャッシュやセキュリティ設定、およびブラウザのネットワーク画面で収集APIの応答を確認してください。

## 開発

`composer validate --strict`、PHP構文検査、全JavaScriptの`node --check`を実行してください。リリースは`tools/build-release.sh`で生成します。翻訳では英語の原文と安定した識別子を維持し、MaxMindのASN組織名を翻訳しないでください。

## ライセンス

Copyright © 10yendama.com. GPL-2.0-or-laterで提供します。[LICENSE](LICENSE)および任意コンポーネントの[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md)を参照してください。
