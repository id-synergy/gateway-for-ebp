# Application mobile — Gateway EBP

Adaptation de la charte à l'application mobile compagnon (impression d'étiquettes à partir
des articles EBP), avec le style **Liquid Glass** d'iOS.

## Principe

L'app reste **native iOS** : on ne plaque pas le design web. On emprunte à la charte la
**couleur** et le **logo**, pas les composants web ni la police.

- **Accent (`AccentColor`)** = bleu électrique `#0046FE`. Il colore boutons primaires,
  liens, sélections, focus — comme sur le back-office.
- **Fond** : on garde les fonds système iOS (groupés), pour le natif et le Liquid Glass.
  La touche de marque vient de l'accent bleu et du logo, pas d'un aplat crème plaqué.
- **Police** : San Francisco (système), Dynamic Type respecté.
- **Matériaux** : cartes et feuilles en matériaux translucides iOS (`.regularMaterial`),
  dans l'esprit Liquid Glass ; l'accent bleu ressort par-dessus.

## Couleurs à reporter dans l'app

| Rôle iOS | Valeur |
|----------|--------|
| `AccentColor` | `#0046FE` |
| Teinte logo / pastille d'accueil | dégradé de `#0046FE` |
| Texte fort ponctuel (titres de marque) | marine `#002C52` |

Le reste (fonds, séparateurs, textes secondaires) suit les couleurs système iOS en
clair et sombre — pas de valeurs figées, pour que le sombre reste correct.

## Logo d'application « base article »

Logo **dédié à l'app**, distinct du logo gateway (deux nœuds). Même famille : carré bleu
`#0046FE` arrondi, nœuds crème/marine — mais le motif évoque une **étiquette d'article**
(le cœur de l'app) plutôt que la liaison réseau.

Concept retenu : une **étiquette** (tag) crème avec son œillet, posée sur le carré bleu,
barrée d'un petit code-barres marine — l'étiquette produit issue d'EBP.

```svg
<svg width="48" height="48" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
  <rect x="2" y="2" width="44" height="44" rx="10" fill="#0046FE"/>
  <!-- étiquette (tag) crème, inclinée -->
  <path d="M14 20 h14 a3 3 0 0 1 2.1 0.9 l7 7 a3 3 0 0 1 0 4.2 l-6.8 6.8 a3 3 0 0 1-4.2 0 l-7-7 A3 3 0 0 1 18 38.9 V22 a2 2 0 0 1 2-2 Z" fill="#F6F2E4"/>
  <!-- œillet -->
  <circle cx="22.5" cy="26.5" r="2.2" fill="#002C52"/>
  <!-- code-barres marine -->
  <g stroke="#002C52" stroke-width="1.4" stroke-linecap="round">
    <line x1="28" y1="27" x2="33" y2="32"/>
    <line x1="30.5" y1="25.5" x2="35.5" y2="30.5"/>
    <line x1="33" y1="24.5" x2="37" y2="28.5"/>
  </g>
</svg>
```

> Ce SVG est une **base de travail** : la géométrie exacte (angle de l'étiquette, nombre
> de barres) sera ajustée au rendu. L'important est la lecture immédiate « étiquette » et
> la parenté de couleur avec la gateway.

### Déclinaison icône d'app iOS

- **Fond plein** bleu `#0046FE` (pas de transparence : l'icône d'app iOS est opaque).
- Motif étiquette crème centré, marges confortables (l'iOS masque arrondit déjà le carré :
  prévoir le motif dans la zone sûre centrale).
- Fournir les tailles requises par l'`AppIcon` (ou un asset 1024×1024 unique si l'asset
  catalog est en « single size »).

### Écran d'accueil (appairage)

L'auréole / pastille d'accueil reprend le dégradé de l'accent bleu, avec au centre une
icône d'étiquette (SF Symbol `barcode.viewfinder` aujourd'hui, à remplacer par le logo
dédié une fois l'asset intégré).

## À faire côté app

1. Définir `AccentColor` = `#0046FE` dans l'asset catalog.
2. Intégrer l'`AppIcon` (logo base article) en PNG aux tailles requises.
3. Remplacer la pastille d'accueil provisoire par le logo dédié.
4. Garder fonds et matériaux système (Liquid Glass) ; ne pas plaquer le crème.
