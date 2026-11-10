<x-mail::message>
# Compte EBP {{ $reconnection ? 'reconnecté' : 'connecté' }}

@include('mail.partials.alert-banner', ['level' => 'success', 'label' => 'Connexion EBP réussie'])

@if ($reconnection)
Le compte EBP ci-dessous a été **reconnecté** à la gateway avec succès. L'accès à l'API EBP est de nouveau opérationnel.
@else
Le compte EBP ci-dessous vient d'être **connecté** à la gateway avec succès. Il est désormais opérationnel : la gateway peut relayer les appels vers l'API EBP pour ce compte.
@endif

- **Compte :** {{ $accountLabel }}
- **Connecté le :** {{ $connectedAt }}
- **Dossiers synchronisés :** {{ $foldersCount }}

<x-mail::button :url="$backofficeUrl">
Ouvrir le back-office
</x-mail::button>

Aucune action n'est requise. Ce message confirme simplement que la connexion s'est bien déroulée.

Merci,<br>
Gateway API EBP
</x-mail::message>
