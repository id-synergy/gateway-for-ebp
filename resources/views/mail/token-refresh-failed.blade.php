<x-mail::message>
# Renouvellement du token EBP échoué

@include('mail.partials.alert-banner', ['level' => 'danger', 'label' => 'Incident — connexion EBP interrompue'])

Le renouvellement automatique du jeton d'accès EBP a **échoué** pour un compte connecté à la gateway.

- **Compte concerné :** {{ $accountLabel }}
- **Dernier renouvellement réussi :** {{ $lastSuccessAt }}
- **Expiration du refresh token :** {{ $refreshExpiresAt }}
- **Raison :** {{ $errorMessage }}

Le refresh token EBP est valable **30 jours** et se renouvelle à chaque appel réussi. S'il n'est pas renouvelé avant son expiration, la connexion de ce compte sera **rompue** et une **reconnexion manuelle via le portail EBP** sera nécessaire.

<x-mail::button :url="$backofficeUrl">
Ouvrir le back-office
</x-mail::button>

Si l'échec persiste, reconnectez le compte **avant le {{ $refreshExpiresAt }}**.

Merci,<br>
Gateway API EBP
</x-mail::message>
