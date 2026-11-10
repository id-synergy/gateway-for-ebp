# Formes et trames — Gateway EBP

## Rayons

Base : `--radius: 0.625rem` (10 px).

| Jeton | Valeur | Usage |
|-------|--------|-------|
| `--radius-sm` | 6 px | Petits éléments (badges, puces) |
| `--radius-md` | 8 px | Champs, boutons |
| `--radius` / `lg` | 10 px | Cartes |
| `--radius-xl` | 14 px | Grandes cartes, modales |

### Coupe d'angle décorative

Le back-office utilise une coupe d'angle signature (`.cut-tl`, `.cut-tr`) : un angle mis
à plat, l'angle opposé arrondi à `1.25rem`. Détail de marque, à employer avec parcimonie
(en-têtes, cartes mises en avant), jamais sur tous les blocs.

## Cartes

- Fond blanc (`--card`) sur fond crème général.
- Rayon `lg` (10 px), bordure fine `--border` ou ombre douce, pas les deux.
- Padding intérieur généreux (16 px et plus).

## Ombres

Ombres **douces et basses** seulement (diffusion large, faible opacité). Pas d'ombre dure
ni de contour noir. Une carte se détache par le contraste blanc/crème plus que par l'ombre.

## Boutons

| Type | Fond | Texte |
|------|------|-------|
| Primaire | bleu `#0046FE` | blanc |
| Secondaire | crème soutenu | marine |
| Discret / lien | transparent | bleu |
| Destructif | rouge | blanc |

- Une action **primaire** par écran de préférence.
- Coins en `--radius-md` (8 px), hauteur confortable, libellé en Medium.

## États

| État | Traitement |
|------|------------|
| Focus | anneau bleu `--ring` (`#0046FE`) |
| Survol | légère variation de luminosité, pas de changement de teinte |
| Désactivé | opacité réduite, pas de couleur grise ajoutée |
| Erreur | bordure + texte rouge `--destructive`, message court |
| Succès | accent bleu ou vert sobre, jamais criard |

## Icônes

Jeu d'icônes linéaires (lucide côté web). Trait régulier, même épaisseur que le texte
adjacent. Sur mobile : SF Symbols, même logique (linéaire, `.regular`/`.semibold`).
