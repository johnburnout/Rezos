#!/usr/bin/env bash
#
# Construit une version distribuable du plugin dont les dépendances (Symfony, Twig, PSR)
# sont préfixées sous « RezosVendor\ » avec PHP-Scoper.
#
#   bin/build.sh            → build/rezos/ + build/rezos-<version>.zip
#   bin/build.sh --no-zip   → build/rezos/ seulement
#
set -euo pipefail

SCOPER_VERSION="0.18.20"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PHAR="tools/php-scoper.phar"
BUILD_DIR="build/rezos"
VERSION="$(sed -n "s/^ \* Version: *//p" rezos.php | tr -d '[:space:]')"

step() { printf '\n\033[1m→ %s\033[0m\n' "$*"; }

# 1. PHP-Scoper (PHAR, hors des dépendances du projet)
if [[ ! -f "$PHAR" ]] || ! php "$PHAR" --version 2>/dev/null | grep -q "$SCOPER_VERSION"; then
    step "Téléchargement de PHP-Scoper $SCOPER_VERSION"
    mkdir -p tools
    curl -fsSL -o "$PHAR" \
        "https://github.com/humbug/php-scoper/releases/download/${SCOPER_VERSION}/php-scoper.phar"
fi

# 2. Dépendances de production uniquement
step "composer install --no-dev"
composer install --no-dev --prefer-dist --no-interaction --no-progress --quiet

# 3. Préfixage
step "Préfixage des dépendances (RezosVendor\\)"
php "$PHAR" add-prefix --config=scoper.inc.php --output-dir="$BUILD_DIR" --force --no-interaction --quiet

# Le plugin charge vendor/autoload.php ; les alias globaux de scoper-autoload.php
# sont inutiles (et réintroduiraient des symboles globaux).
rm -f "$BUILD_DIR/vendor/scoper-autoload.php"

# 4. Autoloader Composer régénéré avec les noms préfixés
step "Régénération de l'autoloader"
composer dump-autoload --working-dir="$BUILD_DIR" --classmap-authoritative --no-dev --no-interaction --quiet
rm -f "$BUILD_DIR/composer.json"

# 5. Vérifications
step "Test de fumée"
php bin/smoke-test.php "$BUILD_DIR"

# 6. Archive
if [[ "${1:-}" != "--no-zip" ]]; then
    step "Archive build/rezos-${VERSION}.zip"
    rm -f "build/rezos-${VERSION}.zip"
    (cd build && zip -qr "rezos-${VERSION}.zip" rezos)
    echo "build/rezos-${VERSION}.zip"
fi
