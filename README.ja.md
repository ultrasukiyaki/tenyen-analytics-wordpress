[English](README.md)

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

「プラグイン → 新規追加 → プラグインのアップロード」からリリースZIPをアップロードするか、`tenyen-analytics`ディレクトリを`wp-content/plugins/`へ配置して有効化します。既存環境はプラグインディレクトリを上書きして更新できます。v0.5.7にDB移行はありません。

## GeoLite2の設定

GeoLite2のMMDBファイルは同梱されません。サイト管理者が[MaxMindの利用条件](https://www.maxmind.com/)に従って`GeoLite2-City.mmdb`と`GeoLite2-ASN.mmdb`を取得し、`wp-content/uploads/tenyen-analytics/`へ配置するか、設定画面でパスを指定してください。ファイルがなくても基本的な収集は動作します。

## ダッシュボードとレポート

Tenyen Analyticsメニューには、ダッシュボード、リアルタイム、アクセス履歴、コンテンツ、参照元、ASN／組織、利用環境、エンゲージメント、システム、設定があります。WordPress標準ダッシュボードのウィジェットは非同期で集計し、`manage_options`権限を持つ管理者だけに表示されます。

## プライバシーとセキュリティ

生IPアドレスは復号可能な方式で暗号化され、完全一致検索にはHMAC値を使います。保護鍵はWordPressのSaltから派生するため、Saltを変更すると過去の暗号化IPを復号できなくなります。GeoLite2はローカルで参照します。ASN組織名はアドレス範囲の登録組織を示すもので、訪問者の勤務先や所属を証明しません。管理者は収集内容と保持期間をプライバシーポリシーへ記載してください。アンインストールしても、管理者が手動で削除しない限り解析データは保持されます。

## 更新

WordPressをバックアップしてから、新しいプラグインをアップロードまたは上書きしてください。uploads内のGeoLite2ファイルは保持してください。v0.5.7は既存のテーブル、オプション、ルート、ペイロード項目、表示設定、収集済みデータを維持します。

## トラブルシューティング

「Tenyen Analytics → システム」で収集エンドポイントとGeoLite2の状態を確認できます。地域やASNが空の場合はMMDBのパスと読み取り権限を確認してください。イベントが記録されない場合は、キャッシュやセキュリティ設定、およびブラウザのネットワーク画面で収集APIの応答を確認してください。

## 開発

`composer validate --strict`、PHP構文検査、全JavaScriptの`node --check`を実行してください。リリースは`tools/build-release.sh`で生成します。翻訳では英語の原文と安定した識別子を維持し、MaxMindのASN組織名を翻訳しないでください。

## ライセンス

Copyright © 10yendama.com. GPL-2.0-or-laterで提供します。[LICENSE](LICENSE)および任意コンポーネントの[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md)を参照してください。
