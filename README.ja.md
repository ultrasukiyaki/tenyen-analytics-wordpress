[English](README.md) | 日本語

# Tenyen Analytics for WordPress

## 概要

Tenyen Analyticsは、ページビュー、推定ユニーク訪問者、セッション、エンゲージメント、参照元、利用環境、GeoLite2の地域・ASN情報、Bot判定をWordPress内で集計するセルフホスト型プラグインです。外部のアクセス解析サービスは必要ありません。

## 機能

- ページビュー、推定UU、セッション、滞在時間、スクロール率、外部クリック、ダウンロード
- Human／Botフィルターとローカル描画のグラフ
- 生IPアドレスの暗号化保存とHMACによる完全一致検索
- GeoLite2 City／ASNのローカル参照と任意のMaxMind公式Reader
- 非同期アクセス履歴とコンパクトなWordPressダッシュボードウィジェット
- MaxMindの組織名を変更せずに表示する注目組織カテゴリー

## 要件

- WordPress 6.2以上
- PHP 8.1以上

## インストール

「プラグイン → 新規追加 → プラグインのアップロード」からリリースZIPをアップロードするか、`tenyen-analytics-wordpress`ディレクトリを`wp-content/plugins/`へ配置して有効化します。

## GeoLite2の設定

GeoLite2のMMDBファイルは同梱されません。サイト管理者が[MaxMindの利用条件](https://www.maxmind.com/)に従って`GeoLite2-City.mmdb`と`GeoLite2-ASN.mmdb`を取得し、`wp-content/uploads/tenyen-analytics/`へ配置するか、設定画面でパスを指定してください。ファイルがなくても基本的な収集は動作します。

## ダッシュボードとレポート

Tenyen Analyticsメニューには、ダッシュボード、リアルタイム、アクセス履歴、コンテンツ、参照元、ASN／組織、利用環境、エンゲージメント、システム、設定があります。WordPress標準ダッシュボードのウィジェットは非同期で集計し、`manage_options`権限を持つ管理者だけに表示されます。

## プライバシーとセキュリティ

生IPアドレスは復号可能な方式で暗号化され、完全一致検索にはHMAC値を使います。保護鍵はWordPressのSaltから派生するため、Saltを変更すると過去の暗号化IPを復号できなくなります。GeoLite2はローカルで参照します。ASN組織名はアドレス範囲の登録組織を示すもので、訪問者の勤務先や所属を証明しません。管理者は収集内容と保持期間をプライバシーポリシーへ記載してください。アンインストールしても、管理者が手動で削除しない限り解析データは保持されます。

## 更新

WordPressをバックアップしてから、新しいプラグインをアップロードまたは上書きしてください。uploads内のGeoLite2ファイルは保持してください。v0.6.1は帰属・イベント用のnullable項目を追加し、v0.6.0の既存行と識別子を維持します。

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
