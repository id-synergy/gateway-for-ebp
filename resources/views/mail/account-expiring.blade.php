<x-mail::message>
# Connexion EBP à renouveler rapidement

@include('mail.partials.alert-banner', ['level' => 'warning', 'label' => 'Action requise — compte bientôt déconnecté'])

Le compte EBP **{{ $accountLabel }}** n'a pas été renouvelé avec succès depuis un certain temps. Son refresh token approche de l'expiration.

- **Compte concerné :** {{ $accountLabel }}
- **Dernier renouvellement réussi :** {{ $lastSuccessAt }}
- **Expiration du refresh token :** {{ $refreshExpiresAt }}
- **Jours restants :** {{ $daysLeft }}

Passé la date d'expiration, la connexion sera **rompue** et une reconnexion manuelle via le portail EBP sera obligatoire.

<x-mail::button :url="$backofficeUrl">
Ouvrir le back-office
</x-mail::button>

Merci,<br>
Gateway API EBP
</x-mail::message>
