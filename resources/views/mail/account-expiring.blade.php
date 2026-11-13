<x-mail::message>
# Connexion EBP à renouveler rapidement

@include('mail.partials.alert-banner', ['level' => 'warning', 'label' => 'Action requise — compte bientôt déconnecté', 'labelEn' => 'Action required — account about to disconnect'])

Le compte EBP **{{ $accountLabel }}** n'a pas été renouvelé avec succès depuis un certain temps. Son refresh token approche de l'expiration.

- **Compte concerné :** {{ $accountLabel }}
- **Dernier renouvellement réussi :** {{ $lastSuccessAt }}
- **Expiration du refresh token :** {{ $refreshExpiresAt }}
- **Jours restants :** {{ $daysLeft }}

Passé la date d'expiration, la connexion sera **rompue** et une reconnexion manuelle via le portail EBP sera obligatoire.

<x-mail::button :url="$backofficeUrl">
Ouvrir le back-office / Open the back office
</x-mail::button>

---

# EBP connection to renew soon

The EBP account **{{ $accountLabel }}** has not been renewed successfully for a while. Its refresh token is approaching expiration.

- **Account:** {{ $accountLabel }}
- **Last successful renewal:** {{ $lastSuccessAt }}
- **Refresh token expiry:** {{ $refreshExpiresAt }}
- **Days left:** {{ $daysLeft }}

After the expiry date, the connection will be **broken** and a manual reconnection through the EBP portal will be required.

Merci / Thank you,<br>
Gateway API EBP
</x-mail::message>
