#!/usr/bin/env bash
# Amorçage au démarrage du conteneur — idempotent, sûr à chaque (re)boot.
#
# Rôles :
#   - service `app`     : exécute l'amorçage complet (clé, migrations, caches)
#     puis lance la commande par défaut (supervisord → nginx + php-fpm).
#   - service `horizon` : NE touche PAS à la base ni aux caches (l'app s'en
#     charge) ; il attend juste que l'app ait fini, puis lance les workers.
#
# La distinction se fait sur la variable CONTAINER_ROLE (app|horizon),
# définie dans compose.prod.yaml.
set -euo pipefail

ROLE="${CONTAINER_ROLE:-app}"
cd /var/www/html

log() { echo "[entrypoint:${ROLE}] $*"; }

# --- 1. Attendre que MySQL réponde -------------------------------------------
wait_for_mysql() {
    log "Attente de la base de données ${DB_HOST:-mysql}:${DB_PORT:-3306}…"
    local tries=0
    until php -r '
        $h=getenv("DB_HOST")?:"mysql"; $p=getenv("DB_PORT")?:"3306";
        $c=@fsockopen($h,(int)$p,$e,$s,2); exit($c?0:1);
    '; do
        tries=$((tries + 1))
        if [ "$tries" -ge 60 ]; then
            log "ERREUR : base de données injoignable après 60 tentatives." >&2
            exit 1
        fi
        sleep 2
    done
    log "Base de données joignable."
}

# --- 2. APP_KEY : générer UNIQUEMENT si absente, jamais la régénérer ----------
# APP_KEY chiffre les secrets EBP stockés en base : la régénérer rendrait ces
# secrets illisibles. On écrit la clé générée dans le .env monté (source de
# vérité), ce qui la rend persistante sans dépendre d'un volume dédié.
#
# On lit la présence de la clé DIRECTEMENT dans le .env (pas via config(), qui
# pourrait lire un cache de config hérité d'un boot précédent et masquer la
# vraie valeur). optimize:clear en amont garantit un départ sans cache figé.
ensure_app_key() {
    if [ ! -f .env ]; then
        log "ERREUR : aucun .env monté." >&2
        log "Montez un .env (voir .env.docker.example)." >&2
        exit 1
    fi

    # Clé déjà présente et non vide dans le .env → on n'y touche pas.
    if grep -qE '^APP_KEY=.+' .env && ! grep -qE '^APP_KEY=$' .env; then
        log "APP_KEY déjà présente — inchangée."
        return 0
    fi

    if [ ! -w .env ]; then
        log "ERREUR : APP_KEY absente et le .env monté est en lecture seule." >&2
        log "Générez une clé hors conteneur et renseignez APP_KEY dans le .env." >&2
        exit 1
    fi

    log "APP_KEY absente — génération et écriture dans le .env monté."
    php artisan key:generate --force --ansi
}

# --- 3. Caches de config / routes / vues (perfs production) ------------------
build_caches() {
    log "Mise en cache config/routes/vues…"
    php artisan config:cache --ansi
    php artisan route:cache --ansi
    php artisan view:cache --ansi
}

# Purge tout cache de config/routes/vues hérité (image ou boot précédent) :
# key:generate et la lecture du .env doivent repartir d'un état propre.
php artisan optimize:clear >/dev/null 2>&1 || true

case "$ROLE" in
    app)
        wait_for_mysql
        ensure_app_key

        # Lien symbolique storage (idempotent : --force recrée si besoin).
        php artisan storage:link --force >/dev/null 2>&1 || true

        # Migrations : --force (non interactif) ; idempotent, ne rejoue que le neuf.
        log "Migrations…"
        php artisan migrate --force --ansi

        build_caches

        log "Amorçage terminé — démarrage de nginx + php-fpm."
        ;;

    horizon)
        wait_for_mysql
        ensure_app_key

        # Les workers ont besoin des caches mais ne doivent pas migrer :
        # on attend que l'app ait appliqué les migrations.
        build_caches

        log "Démarrage des workers Horizon."
        ;;

    *)
        log "Rôle inconnu : ${ROLE}. Utilisez 'app' ou 'horizon'." >&2
        exit 1
        ;;
esac

exec "$@"
