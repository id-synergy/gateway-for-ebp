<?php

/**
 * Messages applicatifs renvoyés à l'interface : notifications flash (succès/erreur)
 * et libellés lisibles des actions d'audit. La locale est posée par le middleware
 * SetLocale (cookie du sélecteur > Accept-Language > fallback fr).
 */
return [

    'flash' => [
        'token_revoked_regenerate' => 'Ce jeton est révoqué : créez-en un nouveau plutôt que de le régénérer.',
        'paste_ebp_return' => 'Collez le retour EBP (URL complète ou code).',
        'cannot_deactivate_last_admin' => 'Impossible de désactiver le dernier admin actif.',
        'code_not_found_in_paste' => 'Impossible de trouver le code dans ce que vous avez collé.',
        'ebp_return_contains_error' => 'Le retour EBP collé contient une erreur, pas de code.',
        'state_mismatch' => 'Le state ne correspond pas à la session. Relancez « Reconnecter ».',
        'account_already_exists' => 'Un compte existe déjà avec cette adresse.',
        'admin_created' => 'Admin créé.',
        'admin_reactivated' => 'Admin réactivé.',
        'admin_deactivated' => 'Admin désactivé.',
        'welcome_enable_2fa' => 'Bienvenue ! Pensez à activer la double authentification depuis votre profil.',
        'account_created_connect' => 'Compte EBP créé. Connectez-le pour initier le flux OAuth.',
        'account_updated' => 'Compte EBP mis à jour.',
        'account_deleted' => 'Compte EBP supprimé.',
        'invitation_revoked' => 'Invitation révoquée.',
        'token_revoked' => 'Jeton révoqué.',
        'preference_saved' => 'Préférence enregistrée.',
        'cache_cleared_all' => 'Tout le cache de lecture a été vidé.',
        'authorization_code_missing' => "Code d'autorisation manquant.",
        'account_connected' => 'Compte EBP « :label » connecté.',
        'invitation_no_longer_valid' => "Cette invitation n'est plus valide.",
        'invitation_sent' => 'Invitation envoyée à :email.',
        'connect_account_before_sync' => "Connectez d'abord le compte à EBP avant de synchroniser les dossiers.",
        'folders_synced' => ':count dossier(s) synchronisé(s) depuis EBP.',
        'sync_failed' => 'Synchronisation échouée : :error',
        'cache_cleared_account' => 'Cache de lecture du compte « :label » vidé.',
        'ip_allowed' => "L'IP :ip est autorisée (débit illimité).",
        'ip_blocked' => "L'IP :ip est bloquée (403).",
        'ip_registered' => "L'IP :ip est enregistrée.",
        'oauth_access_denied' => 'Autorisation refusée sur le portail EBP.',
        'oauth_portal_error' => 'Erreur du portail EBP : :error.',
        'oauth_invalid_grant' => "Code d'autorisation invalide ou expiré. Le code EBP n'est valable que quelques instants et à usage unique : relancez « Reconnecter » et collez le retour aussitôt.",
        'oauth_redirect_uri' => "Le redirect_uri ne correspond pas à celui autorisé chez EBP. Vérifiez qu'il est identique au caractère près (mode de redirection du compte / EBP_LOCALHOST_REDIRECT).",
        'oauth_invalid_client' => 'ClientId ou ClientSecret EBP refusé. Vérifiez les identifiants du compte dans « Modifier ».',
        'oauth_invalid_scope' => 'Portées (scope) refusées par EBP. Vérifiez la configuration du scope (openid profile offline_access).',
        'oauth_exchange_failed' => 'Connexion EBP échouée : :error',
    ],

    'audit' => [
        'account.created' => 'Compte EBP créé',
        'account.updated' => 'Compte EBP modifié',
        'account.deleted' => 'Compte EBP supprimé',
        'account.folders_synced' => 'Dossiers synchronisés',
        'token.created' => 'Jeton créé',
        'token.revoked' => 'Jeton révoqué',
        'token.regenerated' => 'Clé régénérée',
        'token.rules_updated' => "Règles d'accès modifiées",
        'token.paired' => 'Jeton appairé',
        'ip.blocked' => 'IP bloquée',
        'ip.allowed' => 'IP autorisée',
        'ip.unclassified' => 'IP déclassée',
        'ip.removed' => 'IP retirée',
        'admin.created' => 'Admin créé',
        'admin.deactivated' => 'Admin désactivé',
        'admin.reactivated' => 'Admin réactivé',
        'invitation.created' => 'Invitation envoyée',
        'invitation.revoked' => 'Invitation révoquée',
        'cache.cleared_account' => 'Cache compte vidé',
        'cache.cleared_all' => 'Cache global vidé',
    ],

];
