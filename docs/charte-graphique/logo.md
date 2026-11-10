# Logo — Gateway EBP

## Logo de la gateway (back-office)

Source : `resources/js/components/GatewayLogo.vue` et `public/favicon.svg`.

Concept : **deux nœuds reliés** (une application cliente → EBP), générique et sans
référence à EBP en toutes lettres.

```svg
<svg width="48" height="48" viewBox="0 0 48 48">
  <rect x="2" y="2" width="44" height="44" rx="10" fill="#0046FE"/>
  <line x1="16" y1="24" x2="32" y2="24" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/>
  <circle cx="16" cy="24" r="4.5" fill="#002C52" stroke="#fff" stroke-width="2.4"/>
  <circle cx="32" cy="24" r="4.5" fill="#F6F2E4"/>
</svg>
```

### Construction

- **Fond** : carré bleu électrique `#0046FE`, rayon `rx = 10` sur une grille 48 (~21 %).
- **Liaison** : trait blanc horizontal, `stroke-width 2.4`, bouts arrondis.
- **Nœud gauche** (l'app) : cercle marine `#002C52` cerclé de blanc.
- **Nœud droit** (EBP) : cercle crème `#F6F2E4`, plein.

### Zone de respiration

Garder autour du carré un vide d'au moins **la moitié du rayon d'angle**. Ne jamais
coller un autre élément au bord du carré.

### Déclinaisons

- **Pleine couleur** : fond bleu, sur fond clair ou sombre.
- **Monochrome** : tout le carré en marine, nœuds et trait en crème, pour impression une
  couleur.
- **Taille mini** : au-dessous de 24 px, retirer le cerclage blanc des nœuds (illisible).

### Interdits

- Ne pas changer les couleurs ni les proportions.
- Ne pas étirer, incliner, ajouter d'ombre portée dure.
- Ne pas ajouter de texte à l'intérieur du carré.

## Logo d'application mobile (distinct)

L'application mobile a **son propre logo**, dans le même esprit couleurs mais orienté
« base article / étiquette » — il ne reprend pas le logo de la gateway tel quel. Voir
[`application-mobile.md`](application-mobile.md).
