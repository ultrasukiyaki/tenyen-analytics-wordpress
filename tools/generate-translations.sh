#!/usr/bin/env bash
set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
language_dir="$project_dir/languages"
pot="$language_dir/tenyen-analytics.pot"
po="$language_dir/tenyen-analytics-ja.po"

mkdir -p "$language_dir"

xgettext \
    --language=PHP \
    --from-code=UTF-8 \
    --keyword=__ \
    --keyword=_e \
    --keyword=_x:1,2c \
    --keyword=_n:1,2 \
    --keyword=esc_html__ \
    --keyword=esc_attr__ \
    --package-name='Tenyen Analytics for WordPress' \
    --package-version='0.6.2' \
    --msgid-bugs-address='https://www.10yendama.com/' \
    --copyright-holder='10yendama.com' \
    --output="$pot" \
    "$project_dir/tenyen-analytics.php" \
    "$project_dir/includes/class-tya-plugin.php" \
    "$project_dir/includes/class-tya-metadata.php" \
    "$project_dir/includes/admin/class-tya-dashboard-widget.php" \
    "$project_dir/includes/admin/class-tya-session-admin.php" \
    "$project_dir/assets/admin-charts.js" \
    "$project_dir/assets/admin-history.js" \
    "$project_dir/assets/admin-sessions.js" \
    "$project_dir/assets/admin-metadata.js" \
    "$project_dir/assets/dashboard-widget.js"

if [[ ! -f "$po" ]]; then
    msginit --no-translator --input="$pot" --locale=ja_JP.UTF-8 --output-file="$po"
else
    msgmerge --quiet --backup=none --update "$po" "$pot"
fi

merged="$(mktemp)"
trap 'rm -f "$merged"' EXIT
msgcat --use-first "$project_dir/tools/ja-overrides.po" "$po" --output-file="$merged"
msgmerge --quiet "$merged" "$pot" --output-file="$po"

msgfmt --check --output-file="$language_dir/tenyen-analytics-ja.mo" "$po"

php "$project_dir/tools/make-json-translations.php"
