#!/usr/bin/env bash
#
# Build a distributable, runtime-only ZIP of the plugin for WordPress.org.
# Excludes dev tooling (tests, composer, phpcs config, dotfiles) so the package
# contains only what ships to users. Output: build/link-guardian.zip
#
set -euo pipefail

SLUG="link-guardian"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/build"

rm -rf "$OUT"
mkdir -p "$OUT/$SLUG"

rsync -a \
  --exclude='.git' --exclude='.github' --exclude='.gitignore' \
  --exclude='.editorconfig' --exclude='.distignore' --exclude='.wordpress-org' \
  --exclude='bin' --exclude='build' \
  --exclude='composer.json' --exclude='composer.lock' \
  --exclude='node_modules' --exclude='phpcs.xml.dist' \
  --exclude='tests' --exclude='vendor' \
  --exclude='*.zip' --exclude='*.log' \
  "$ROOT/" "$OUT/$SLUG/"

( cd "$OUT" && zip -rq "$SLUG.zip" "$SLUG" )
echo "Built: $OUT/$SLUG.zip"
