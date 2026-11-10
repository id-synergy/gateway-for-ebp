# Couleurs — Gateway EBP

Palette source : `resources/css/app.css` (variables de thème, format OKLCH). Les HEX
ci-dessous sont les équivalents de référence, à utiliser hors du web (mobile, print).

## Couleurs de marque

| Nom | HEX | OKLCH | Usage |
|-----|-----|-------|-------|
| **Bleu électrique** | `#0046FE` | `oklch(0.52 0.26 264)` | Accent, actions primaires, liens, focus, logo |
| **Marine** | `#002C52` | `oklch(0.255 0.055 250)` | Texte principal, barre latérale, profondeur |
| **Crème** | `#F6F2E4` | `oklch(0.955 0.018 92)` | Fond chaleureux de l'application |

Ces trois couleurs suffisent à identifier la gateway. Le bleu porte l'action, la marine
porte le texte et la structure, le crème porte le fond.

## Rôles (thème clair)

| Variable | Rôle | HEX approx. |
|----------|------|-------------|
| `--background` | Fond général | `#F6F2E4` (crème) |
| `--foreground` | Texte sur fond | `#002C52` (marine) |
| `--card` | Fond des cartes | `#FFFFFF` |
| `--card-foreground` | Texte sur carte | `#002C52` |
| `--primary` / `--accent` | Action, accent | `#0046FE` |
| `--primary-foreground` | Texte sur bleu | `#FFFFFF` |
| `--secondary` / `--muted` | Fond atténué | crème plus soutenu |
| `--muted-foreground` | Texte secondaire | marine désaturée |
| `--border` / `--input` | Bordures, champs | crème foncé léger |
| `--ring` | Anneau de focus | `#0046FE` |
| `--destructive` | Erreur / danger | rouge `oklch(0.577 0.245 27.325)` |
| `--sidebar` | Barre latérale | `#002C52` (marine) |

## Rôles (thème sombre)

Mode « marine profond » : le fond devient marine très sombre, le bleu s'éclaircit
légèrement pour rester lisible.

| Variable | HEX approx. |
|----------|-------------|
| `--background` | marine profond `oklch(0.19 0.045 250)` |
| `--foreground` | quasi-blanc `oklch(0.95 0.01 245)` |
| `--card` | marine `oklch(0.25 0.05 250)` |
| `--primary` / `--accent` | bleu clair `oklch(0.62 0.24 264)` |
| `--sidebar` | marine le plus sombre `oklch(0.15 0.04 250)` |

## Règles d'usage

- Le **bleu électrique** ne sert qu'à l'action et à l'accent — jamais en grande aplat de
  fond (fatigue visuelle). Une action primaire par écran de préférence.
- Le **texte courant** est en marine, pas en noir pur : la marine réchauffe et reste dans
  l'identité.
- Le **crème** est le fond par défaut des surfaces larges ; les cartes repassent en blanc
  pur pour créer la hiérarchie.
- **Contraste** : marine sur crème et blanc sur bleu dépassent largement AA. Le texte
  secondaire (marine désaturée) reste réservé aux libellés, pas aux contenus critiques.
- **Rouge destructif** uniquement pour suppression / erreur, jamais décoratif.
