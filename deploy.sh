#!/usr/bin/env bash
# Script de déploiement Forge — Gateway EBP.
# À coller dans l'onglet "Deploy Script" du site Forge (adapter le chemin $FORGE_SITE_PATH).
set -euo pipefail

cd "$FORGE_SITE_PATH"

git pull origin "${FORGE_SITE_BRANCH:-main}"

composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# Assets front (Inertia/Vue) — nécessite node sur le serveur.
npm ci
npm run build

php artisan migrate --force

# Recharge la config/les routes en cache.
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Redémarre les workers Horizon pour prendre le nouveau code.
php artisan horizon:terminate

echo "Déploiement terminé."
