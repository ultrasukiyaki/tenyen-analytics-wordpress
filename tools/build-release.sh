#!/usr/bin/env bash
set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
version="$(sed -n "s/^define('TYA_VERSION', '\\([^']*\\)');$/\\1/p" "$project_dir/tenyen-analytics.php")"
expected_version="0.6.2"

if [[ "$version" != "$expected_version" ]]; then
    echo "Expected version $expected_version, found ${version:-none}." >&2
    exit 1
fi

stage_dir="$(mktemp -d)"
trap 'rm -rf "$stage_dir"' EXIT
plugin_dir="$stage_dir/tenyen-analytics-wordpress"
archive="$project_dir/dist/tenyen-analytics-wordpress-v${version}-stable.zip"
checksums="$project_dir/dist/tenyen-analytics-wordpress-v${version}-SHA256SUMS.txt"

mkdir -p "$plugin_dir" "$project_dir/dist"

(
    cd "$project_dir"
    find . -type f \
        ! -path './.git/*' \
        ! -path './.github/*' \
        ! -path './dist/*' \
        ! -path './tests/*' \
        ! -path './tools/*' \
        ! -path './vendor/*' \
        ! -path './node_modules/*' \
        ! -name '.gitignore' \
        ! -name '.gitattributes' \
        ! -name '.editorconfig' \
        ! -name 'composer.lock' \
        ! -name '*.mmdb' \
        ! -name '*.log' \
        ! -name '*~' \
        ! -name '*.zip' \
        -print0 |
    while IFS= read -r -d '' file; do
        mkdir -p "$plugin_dir/$(dirname "$file")"
        cp "$file" "$plugin_dir/$file"
    done
)

rm -f "$archive" "$checksums"
(
    cd "$stage_dir"
    zip -q -r "$archive" tenyen-analytics-wordpress
)

unzip -t "$archive" >/dev/null

top_levels="$(unzip -Z1 "$archive" | cut -d/ -f1 | sort -u)"
if [[ "$top_levels" != 'tenyen-analytics-wordpress' ]]; then
    echo "Archive must contain only the tenyen-analytics-wordpress top-level directory." >&2
    exit 1
fi

if unzip -Z1 "$archive" | grep -E '(^|/)(\.git|\.github|tests|tools|vendor|node_modules|dist)(/|$)|\.mmdb$|\.log$|composer\.lock$|(^|/)\.(gitignore|gitattributes|editorconfig)$' >/dev/null; then
    echo "Archive contains a forbidden development or local file." >&2
    exit 1
fi

(
    cd "$project_dir/dist"
    sha256sum "$(basename "$archive")" >"$(basename "$checksums")"
)

echo "$archive"
echo "$checksums"
