# gateway-for-ebp

Une passerelle (gateway) open source pour consommer l'**API Développeurs EBP** (SaaS Gestion) depuis vos propres applications, sans réécrire à chaque fois l'authentification OAuth, la gestion du quota et la normalisation des réponses.

> **Statut : en construction.** Dépôt privé pour l'instant. Passage en public prévu une fois le projet stabilisé.

## Le problème que ça résout

L'API EBP impose plusieurs contraintes qui rendent le branchement direct de chaque application fastidieux :

- authentification **OAuth 2.0 Authorization Code** uniquement (pas de machine-to-machine), avec un *refresh token* à renouveler au moins tous les 30 jours ;
- **quota strict** : 1 appel/seconde, 10 000/jour, 100 éléments par page ;
- réponses **hétérogènes et peu documentées** ;
- une seule **URL de redirection** à faire autoriser par abonnement.

`gateway-for-ebp` centralise tout ça en un seul service : vos applications parlent à la gateway avec un simple jeton, la gateway s'occupe d'EBP.

## Ce que fait la gateway

- **Connexion OAuth centralisée**, multi-comptes EBP, avec renouvellement automatique des jetons.
- **Régulation du quota** EBP pour toutes les applications à la fois.
- **File d'attente** pour les requêtes lourdes (jobs chaînés, batch), sans coupure par timeout.
- **Journalisation** des échanges (audit).
- **Back-office** d'administration : connexion des comptes EBP, jetons d'applications, IP autorisées, journaux.
- **API propre et stable** exposée aux applications, indépendante des spécificités d'EBP.

## Ce que ce n'est pas

- Ce n'est **pas** un produit concurrent d'EBP. C'est un outil qui **facilite** l'usage de l'API EBP pour les développeurs et intégrateurs.
- Ce n'est **pas** affilié à EBP ni officiel. « EBP » est une marque de son éditeur ; ce projet est indépendant.

## Stack technique

- PHP / **Laravel**
- File d'attente : **Horizon** (Redis)
- Back-office : **Inertia.js** + **Vue 3** (composants shadcn-vue)

## Prérequis côté EBP

- Un abonnement à l'API Développeurs EBP (offre compatible — SaaS Gestion).
- `ClientId` / `ClientSecret` (reçus par mail d'EBP) et une clé d'abonnement (profil développeur).
- Faire autoriser l'URL de redirection de votre serveur auprès d'EBP.

## Documentation de l'API

Deux spécifications OpenAPI accompagnent le projet :

- **`openapi/gateway.yaml`** — l'API **de la gateway**, telle que vos applications l'appellent.
  C'est la référence à utiliser pour coder vos clients : chemins exposés par la gateway,
  authentification par **jeton d'application** (`Authorization: Bearer gw_…`), et les 7 modules EBP
  relayés (Gestion, Gestion Commerciale TPE, Comptabilité TPE, Bâtiment, Import, Export, Maintenance).
  Elle est servie en ligne sur `GET /api/v1/openapi.yaml` et explorable dans le back-office
  (**API & doc → Explorateur Swagger**).

  Particularités de la vue gateway :
  - le **FolderId est optionnel partout** (URL, query ou corps) : s'il est absent, la gateway le
    résout depuis le dossier par défaut du jeton. Les deux formes d'URL sont acceptées, par exemple
    `GET /gescom/api/v1/Clients` (FolderId implicite) **ou**
    `GET /gescom/api/v1/Folders/{FolderId}/Clients` (explicite) ;
  - cache des lectures contournable par `?noCache=1` ; bascule en job sous charge (`202` + `jobId`) ;
  - erreurs normalisées : `401` (jeton), `429` (quota), `502` (erreur EBP), `503` (compte à reconnecter).

- **`openapi/ebp-reference.yaml`** — l'API **d'EBP** elle-même, consolidée (les 7 modules
  + l'authentification OAuth), à titre de **référence**. Elle décrit ce qu'EBP expose en amont ;
  la gateway s'appuie dessus mais ne l'expose pas telle quelle. Document reconstitué à partir de la
  documentation et du portail officiels d'EBP — il peut évoluer côté EBP.

Les deux specs se téléchargent depuis le back-office (**API & doc**).

## Développement avec Docker (Laravel Sail)

Un environnement complet **PHP + MySQL + Redis** est fourni via Laravel Sail
(`compose.yaml`). Le nom du projet Docker se règle dans `.env` (`COMPOSE_PROJECT_NAME`).

```bash
# démarrer (app + MySQL + Redis)
./vendor/bin/sail up -d

# préparer la base
./vendor/bin/sail artisan migrate --seed

# file d'attente supervisée (Redis)
./vendor/bin/sail artisan horizon
```

L'app écoute sur `APP_PORT` (`.env`), le dashboard Horizon sur `/horizon` (accès admin).

### Tests

- **Par défaut : SQLite en mémoire** (rapide, pour la CI et le dev local) :
  ```bash
  php artisan test
  ```
- **Sur MySQL** (dans le conteneur Sail, pour vérifier la compatibilité SQL réelle) :
  ```bash
  ./vendor/bin/sail artisan test --configuration=phpunit.mysql.xml
  ```

La suite couvre le proxy (7 modules), le cache, l'authentification, et toute la partie
asynchrone (jobs, batch, compose) — exécutée en synchrone contre un mock EBP intégré.

## Licence

MIT (à la publication).

---

*Projet en cours de développement — la documentation d'installation et de configuration arrivera avec la première version publique.*
