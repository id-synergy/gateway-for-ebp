# OpenAPI de la gateway + génération de SDK

`gateway.yaml` décrit l'API **exposée aux applications clientes** (pas l'API EBP).
C'est à partir de ce fichier qu'on génère les SDK que les apps utilisent.

La spec est aussi servie en ligne : `GET /api/v1/openapi.yaml`.

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
