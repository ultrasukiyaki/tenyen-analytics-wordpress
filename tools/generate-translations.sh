#!/usr/bin/env bash
set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
language_dir="$project_dir/languages"
pot="$language_dir/tenyen-analytics.pot"
po="$language_dir/tenyen-analytics-ja.po"
php_pot="$(mktemp)"
js_pot="$(mktemp)"
merged="$(mktemp)"
trap 'rm -f "$php_pot" "$js_pot" "$merged"' EXIT

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
    --package-version='0.7.1' \
    --msgid-bugs-address='https://www.10yendama.com/' \
    --copyright-holder='10yendama.com' \
    --output="$php_pot" \
    "$project_dir/tenyen-analytics.php" \
    "$project_dir/includes/class-tya-plugin.php" \
    "$project_dir/includes/class-tya-metadata.php" \
    "$project_dir/includes/class-tya-exclusions.php" \
    "$project_dir/includes/class-tya-aggregation.php" \
    "$project_dir/includes/class-tya-lifecycle.php" \
    "$project_dir/includes/admin/class-tya-dashboard-widget.php" \
    "$project_dir/includes/admin/class-tya-session-admin.php"

xgettext \
    --language=JavaScript \
    --from-code=UTF-8 \
    --keyword=__ \
    --package-name='Tenyen Analytics for WordPress' \
    --package-version='0.7.1' \
    --msgid-bugs-address='https://www.10yendama.com/' \
    --copyright-holder='10yendama.com' \
    --output="$js_pot" \
    "$project_dir/assets/admin-charts.js" \
    "$project_dir/assets/admin-history.js" \
    "$project_dir/assets/admin-sessions.js" \
    "$project_dir/assets/admin-metadata.js" \
    "$project_dir/assets/admin-exclusions.js" \
    "$project_dir/assets/admin-lifecycle.js" \
    "$project_dir/assets/dashboard-widget.js"

msgcat --use-first "$php_pot" "$js_pot" --output-file="$pot"

if [[ ! -f "$po" ]]; then
    msginit --no-translator --input="$pot" --locale=ja_JP.UTF-8 --output-file="$po"
else
    msgmerge --quiet --backup=none --update "$po" "$pot"
fi

msgcat --use-first "$project_dir/tools/ja-overrides.po" "$po" --output-file="$merged"
msgmerge --quiet "$merged" "$pot" --output-file="$po"
msgattrib --no-obsolete --output-file="$po" "$po"

msgfmt --check --output-file="$language_dir/tenyen-analytics-ja.mo" "$po"

php "$project_dir/tools/make-json-translations.php"
