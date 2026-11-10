<x-mail::message>
# Purge automatique des logs échouée

La tâche planifiée de purge des logs et résultats de jobs au-delà de la rétention a **échoué**.

- **Étape :** {{ $stage }}
- **Raison :** {{ $errorMessage }}

Sans purge, l'espace disque occupé par les corps de réponse et les résultats de jobs continue de croître, au risque de saturer le serveur.

Vérifiez l'espace disque, puis relancez la purge :

<x-mail::panel>
php artisan ebp:logs:purge
</x-mail::panel>

Merci,<br>
Gateway API EBP
</x-mail::message>
