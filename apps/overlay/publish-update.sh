#!/bin/bash

set -e

SRC_DIR="/var/www/rp-mdt/electron-overlay"
DIST_DIR="$SRC_DIR/dist"
PUBLISH_DIR="/var/www/rp-mdt/main/downloads/updates"
LATEST_DIR="/var/www/rp-mdt/main/downloads"

cd "$SRC_DIR"

VERSION=$(node -p "require('./package.json').version")
echo ""
echo "=========================================="
echo "  RP MDT Dispatch - Publication v$VERSION"
echo "=========================================="

echo ""
echo "[1/4] Build de l'app..."
npm run dist 2>&1 | tail -8

INSTALLER="$DIST_DIR/MDT-Dispatch-Setup-$VERSION.exe"
BLOCKMAP="$DIST_DIR/MDT-Dispatch-Setup-$VERSION.exe.blockmap"
LATEST="$DIST_DIR/latest.yml"

if [ ! -f "$INSTALLER" ]; then
    echo "ERREUR: $INSTALLER introuvable"
    exit 1
fi

echo ""
echo "[2/4] Copie vers $PUBLISH_DIR..."
mkdir -p "$PUBLISH_DIR"
cp "$INSTALLER" "$PUBLISH_DIR/"
cp "$BLOCKMAP" "$PUBLISH_DIR/" 2>/dev/null || echo "  (blockmap absent, skip)"
cp "$LATEST" "$PUBLISH_DIR/"

echo ""
echo "[3/4] Mise a jour du DL initial..."
cp "$INSTALLER" "$LATEST_DIR/MDT-Dispatch-Setup.exe"

echo ""
echo "[4/4] Cleanup anciennes versions..."
cd "$PUBLISH_DIR"
ls -t MDT-Dispatch-Setup-*.exe 2>/dev/null | tail -n +6 | xargs -r rm -v
ls -t MDT-Dispatch-Setup-*.exe.blockmap 2>/dev/null | tail -n +6 | xargs -r rm -v

echo ""
echo "=========================================="
echo "  Publication v$VERSION terminee !"
echo "=========================================="
echo ""
echo "Fichiers publies :"
ls -lh "$PUBLISH_DIR/"
echo ""
echo "URL de mise a jour : https://exemple.tld/downloads/updates/latest.yml"
echo "URL DL initial : https://exemple.tld/downloads/MDT-Dispatch-Setup.exe"
echo ""
echo "Les utilisateurs verront la maj au prochain lancement de l'app (sous 4s)."
