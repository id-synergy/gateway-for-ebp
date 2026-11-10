# Typographie — Gateway EBP

## Police

**Instrument Sans** (`--font-sans: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif`).

Sans-serif contemporaine, lisible en petite taille, caractère légèrement technique qui
colle à un outil d'intermédiation. Repli système si la police n'est pas chargée.

Sur mobile iOS, on n'impose pas Instrument Sans : on garde **San Francisco** (police
système) pour rester natif et profiter du rendu Dynamic Type. L'identité passe par la
couleur et le logo, pas par la police. Voir [`application-mobile.md`](application-mobile.md).

## Échelle

| Usage | Taille (web) | Graisse |
|-------|--------------|---------|
| Titre d'écran | ~1.25rem (`text-xl`) | Medium (500) |
| Titre de section | ~1rem | Medium / Semibold |
| Corps | ~0.9375rem | Regular (400) |
| Libellé secondaire | ~0.8125rem | Regular, couleur atténuée |
| Micro (hint, légende) | ~0.75rem | Regular |

## Règles

- **Graisses** : Regular pour le corps, Medium pour les titres. Éviter le Bold massif —
  la hiérarchie passe par la taille et la couleur (marine vs marine atténuée).
- **Casse** : pas de capitales forcées sur les titres. Les libellés de section peuvent
  être en gris atténué sans majuscules systématiques.
- **Nombres** : chiffres tabulaires pour les prix et les quantités quand ils s'alignent
  en colonne (listes d'articles, montants).
