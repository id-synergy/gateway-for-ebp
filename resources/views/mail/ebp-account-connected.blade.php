<x-mail::message>
# Compte EBP {{ $reconnection ? 'reconnecté' : 'connecté' }}

@include('mail.partials.alert-banner', ['level' => 'success', 'label' => 'Connexion EBP réussie', 'labelEn' => 'EBP connection successful'])

@if ($reconnection)
Le compte EBP ci-dessous a été **reconnecté** à la gateway avec succès. L'accès à l'API EBP est de nouveau opérationnel.
@else
Le compte EBP ci-dessous vient d'être **connecté** à la gateway avec succès. Il est désormais opérationnel : la gateway peut relayer les appels vers l'API EBP pour ce compte.
@endif

- **Compte :** {{ $accountLabel }}
- **Connecté le :** {{ $connectedAt }}
- **Dossiers synchronisés :** {{ $foldersCount }}

<x-mail::button :url="$backofficeUrl">
Ouvrir le back-office / Open the back office
</x-mail::button>

Aucune action n'est requise. Ce message confirme simplement que la connexion s'est bien déroulée.

---

# EBP account {{ $reconnection ? 'reconnected' : 'connected' }}

@if ($reconnection)
The EBP account below has been successfully **reconnected** to the gateway. Access to the EBP API is operational again.
@else
The EBP account below has just been successfully **connected** to the gateway. It is now operational: the gateway can relay calls to the EBP API for this account.
@endif

- **Account:** {{ $accountLabel }}
- **Connected on:** {{ $connectedAt }}
- **Folders synced:** {{ $foldersCount }}

No action is required. This message simply confirms that the connection went through.

Merci / Thank you,<br>
Gateway API EBP
</x-mail::message>
