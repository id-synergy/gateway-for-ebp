<x-mail::message>
# Purge automatique des logs échouée

@include('mail.partials.alert-banner', ['level' => 'danger', 'label' => 'Incident — maintenance des logs', 'labelEn' => 'Incident — log maintenance'])

La tâche planifiée de purge des logs et résultats de jobs au-delà de la rétention a **échoué**.

- **Étape :** {{ $stage }}
- **Raison :** {{ $errorMessage }}

Sans purge, l'espace disque occupé par les corps de réponse et les résultats de jobs continue de croître, au risque de saturer le serveur.

Vérifiez l'espace disque, puis relancez la purge :

<x-mail::panel>
php artisan ebp:logs:purge
</x-mail::panel>

---

# Automatic log purge failed

The scheduled task that purges logs and job results beyond retention has **failed**.

- **Stage:** {{ $stage }}
- **Reason:** {{ $errorMessage }}

Without the purge, the disk space taken up by response bodies and job results keeps growing, which may fill up the server.

Check the disk space, then run the purge again (see the command above).

Merci / Thank you,<br>
Gateway API EBP
</x-mail::message>
