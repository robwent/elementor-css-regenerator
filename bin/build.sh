#!/usr/bin/env bash
#
# Build a distributable plugin zip: runtime files only, top-level
# elementor-css-regenerator/ folder so WP's plugin uploader installs it
# cleanly. Output lands next to the plugin dir:
# wp-content/plugins/elementor-css-regenerator-<version>.zip
#
# The zip name is the release contract: the in-plugin GitHub updater
# (Elementor_CSS_Regenerator_Updater in the main file) looks for exactly
# this asset name on the release whose tag matches the plugin version.
#
# Usage: bin/build.sh   (from anywhere; paths resolve from the script location)

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="elementor-css-regenerator"
VERSION="$(sed -n 's/^ \* Version: *\([0-9][0-9a-zA-Z.-]*\)\r*$/\1/p' "$PLUGIN_DIR/$SLUG.php")"

if [[ -z "$VERSION" ]]; then
    echo "Could not read the Version header from $SLUG.php" >&2
    exit 1
fi

OUT_ZIP="$(dirname "$PLUGIN_DIR")/$SLUG-$VERSION.zip"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

echo "Staging $SLUG $VERSION ..."
mkdir -p "$STAGE/$SLUG"
cp "$PLUGIN_DIR/$SLUG.php" "$PLUGIN_DIR/README.md" "$STAGE/$SLUG/"

echo "Zipping ..."
rm -f "$OUT_ZIP"
php -r '
    [$stage, $out] = [$argv[1], $argv[2]];
    $zip = new ZipArchive();
    if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fwrite(STDERR, "Cannot create $out\n");
        exit(1);
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($files as $file) {
        if ($file->isFile()) {
            $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($stage) + 1));
        }
    }
    $zip->close();
' "$STAGE" "$OUT_ZIP"

SIZE="$(du -h "$OUT_ZIP" | cut -f1)"
echo "Built $OUT_ZIP ($SIZE)"
