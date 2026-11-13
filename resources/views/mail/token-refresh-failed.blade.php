<x-mail::message>
# Renouvellement du token EBP échoué

@include('mail.partials.alert-banner', ['level' => 'danger', 'label' => 'Incident — connexion EBP interrompue', 'labelEn' => 'Incident — EBP connection interrupted'])

Le renouvellement automatique du jeton d'accès EBP a **échoué** pour un compte connecté à la gateway.

- **Compte concerné :** {{ $accountLabel }}
- **Dernier renouvellement réussi :** {{ $lastSuccessAt }}
- **Expiration du refresh token :** {{ $refreshExpiresAt }}
- **Raison :** {{ $errorMessage }}

Le refresh token EBP est valable **30 jours** et se renouvelle à chaque appel réussi. S'il n'est pas renouvelé avant son expiration, la connexion de ce compte sera **rompue** et une **reconnexion manuelle via le portail EBP** sera nécessaire.

<x-mail::button :url="$backofficeUrl">
Ouvrir le back-office / Open the back office
</x-mail::button>

Si l'échec persiste, reconnectez le compte **avant le {{ $refreshExpiresAt }}**.

---

# EBP token renewal failed

The automatic renewal of the EBP access token has **failed** for an account connected to the gateway.

- **Account:** {{ $accountLabel }}
- **Last successful renewal:** {{ $lastSuccessAt }}
- **Refresh token expiry:** {{ $refreshExpiresAt }}
- **Reason:** {{ $errorMessage }}

The EBP refresh token is valid for **30 days** and renews on each successful call. If it is not renewed before expiry, this account's connection will be **broken** and a **manual reconnection through the EBP portal** will be required.

If the failure persists, reconnect the account **before {{ $refreshExpiresAt }}**.

Merci / Thank you,<br>
Gateway API EBP
</x-mail::message>
