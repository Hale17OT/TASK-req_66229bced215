#!/bin/sh
# Fetch the Layui 2.x distribution at Docker BUILD time so the runtime is
# fully offline. After this script runs, public/static/layui/ contains
# layui.js, layui.css, modules/, and font assets.
#
# If you have no internet at build time either, place a pre-downloaded
# layui.zip at scripts/cache/layui.zip and this script will use it.

set -eu

LAYUI_VERSION="${LAYUI_VERSION:-2.9.16}"
TARGET_DIR="/var/www/html/public/static/layui"
CACHE_FILE="/var/www/html/scripts/cache/layui.zip"

if [ -d "$TARGET_DIR" ] && [ -f "$TARGET_DIR/layui.js" ]; then
  echo "[layui] already vendored at $TARGET_DIR — skipping"
  exit 0
fi

mkdir -p "$TARGET_DIR"
TMPZIP="/tmp/layui.zip"

if [ -f "$CACHE_FILE" ]; then
  echo "[layui] using cached zip: $CACHE_FILE"
  cp "$CACHE_FILE" "$TMPZIP"
else
  echo "[layui] downloading layui ${LAYUI_VERSION} from GitHub"
  curl -fsSL -o "$TMPZIP" \
    "https://github.com/layui/layui/releases/download/v${LAYUI_VERSION}/layui-v${LAYUI_VERSION}.zip" \
    || curl -fsSL -o "$TMPZIP" \
        "https://codeload.github.com/layui/layui/zip/refs/tags/v${LAYUI_VERSION}"
fi

cd /tmp
unzip -q "$TMPZIP" -d /tmp/layui-extract
# The zip layout varies by source; find layui.js and lift its parent dir.
LAYUI_ROOT="$(dirname "$(find /tmp/layui-extract -name layui.js -type f | head -n1)")"
if [ -z "$LAYUI_ROOT" ]; then
  echo "[layui] FAILED to locate layui.js inside zip" >&2
  exit 1
fi

cp -R "$LAYUI_ROOT"/* "$TARGET_DIR/"
rm -rf /tmp/layui-extract "$TMPZIP"

echo "[layui] vendored to $TARGET_DIR"
ls -la "$TARGET_DIR" | head -20
