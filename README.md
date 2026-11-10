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

## Licence

MIT (à la publication).

---

*Projet en cours de développement — la documentation d'installation et de configuration arrivera avec la première version publique.*
