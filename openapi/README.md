# Spécifications OpenAPI

Ce dossier regroupe les deux specs OpenAPI du projet :

- **`gateway.yaml`** — l'API **exposée aux applications clientes** par la gateway
  (ping, whoami, jobs, batch, compose + les modules EBP relayés). C'est **notre**
  artefact : il sert à générer les SDK et il est servi en ligne sur
  `GET /api/v1/openapi.yaml`. C'est ce fichier qu'on fait évoluer.

- **`ebp-reference.yaml`** — l'API **d'EBP** elle-même, consolidée (les 7 modules +
  l'authentification), à partir de la documentation officielle EBP. C'est un
  **document de référence** : on ne l'invente pas, on le garde fidèle à EBP pour
  savoir quels endpoints le proxy relaie. Il n'alimente pas les SDK.

Les deux sont téléchargeables depuis le back-office (page « API & doc »).

## Générer les SDK (openapi-generator)

```bash
# PHP
npx @openapitools/openapi-generator-cli generate \
  -i openapi/gateway.yaml -g php -o sdk/php

# TypeScript (axios)
npx @openapitools/openapi-generator-cli generate \
  -i openapi/gateway.yaml -g typescript-axios -o sdk/typescript

# Go
npx @openapitools/openapi-generator-cli generate \
  -i openapi/gateway.yaml -g go -o sdk/go
```

Les apps utilisent ensuite le SDK avec :
- l'URL de la gateway (`https://gateway.example.com/api/v1`) ;
- leur **jeton d'app** en Bearer (`gw_...`), fourni par le back-office.

## Notes
- Les routes de lecture peuvent répondre `200` (synchrone) **ou** `202` (basculé en job) :
  le SDK doit gérer les deux et, sur `202`, interroger `/jobs/{jobId}` jusqu'à `done`/`partial`.
- Le jeton cible un compte EBP ; le dossier vient du paramètre `folderId` ou du défaut du jeton.
