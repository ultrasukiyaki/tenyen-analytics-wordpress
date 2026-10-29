# 訪問者情報とホスト名表示（v0.9.0）

ダッシュボードの最近の閲覧とリアルタイムでは、保存済みの国コード／国名、地域、市区町村をASN・登録組織とともに表示します。セッション一覧、セッション詳細、匿名訪問者詳細も同じ書式を使います。値がない場合は`—`を表示します。ASN登録情報やPTRホスト名はネットワーク基盤を示すもので、訪問者の勤務先や本人性を証明しません。

アクセス履歴では復号済みIPの代わりに逆引きDNSホスト名を表示します。キャッシュがない場合は「ホスト名の検索待ち」、PTRがない場合や失敗時は「ホスト名を取得できません」と表示します。既存ブラウザーの列設定は維持し、表示していたIP列はホスト名列へ自動移行します。地域列の表示状態は保存済み設定に従います。

## ホスト名更新

WordPressは`tya_hostname_refresh`をWP-Cronで予約します。1回につき新しいIP hashから最大20件を1件ずつ処理し、DNS timeoutは500ms、全体の処理時間は15秒以内です。短時間のoption lockで重複実行を防ぎ、最近のイベントを優先します。成功は24時間、PTRなしは6時間、resolver失敗は1時間後に再試行します。

WP-Cronはアクセスを契機に動作します。WP-Cronを無効化しているサイトで迅速な更新が必要な場合は、ホスティング側のschedulerから`wp-cron.php`を1分ごとに実行してください。

workerは`/etc/resolv.conf`の最初の有効なnameserverを使い、外部の検索APIは呼びません。公開IPだけをresolverへ送り、private、loopback、link-local、documentationなどの非公開アドレスは除外します。収集処理と管理画面表示では逆引きDNSを実行しません。

## 更新と保持

通常の冪等migrationで、既存IP hashを主キーとし期限indexを持つprefix付き`tya_hostname_cache` tableを追加します。既存event列は変更しません。raw log cleanupでは、元eventが残っていないcache entryも削除します。hostnameは管理者専用で、既存exportには追加しません。
