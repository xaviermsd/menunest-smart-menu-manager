#!/usr/bin/env bash
# Build the release zip for WordPress.org: copies the plugin without the files
# listed in .distignore into build/menunest-smart-menu-manager and zips it.
set -euo pipefail

SLUG="menunest-smart-menu-manager"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD="$ROOT/build"

rm -rf "$BUILD"
mkdir -p "$BUILD/$SLUG"

# Every line in .distignore names a file or folder at the top of the plugin.
EXCLUDES=()
while IFS= read -r line || [ -n "$line" ]; do
	line="${line%%#*}"
	line="$(echo "$line" | xargs)"
	[ -z "$line" ] && continue
	EXCLUDES+=("--exclude=./${line#/}")
done < "$ROOT/.distignore"

tar -C "$ROOT" --anchored "${EXCLUDES[@]}" -cf - . | tar -C "$BUILD/$SLUG" -xf -

(cd "$BUILD" && zip -rq "$SLUG.zip" "$SLUG")
echo "Built $BUILD/$SLUG.zip"
