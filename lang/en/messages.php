<?php

/**
 * Application messages surfaced in the UI: flash notifications (success/error)
 * and human-readable audit action labels. The locale is set by the SetLocale
 * middleware (switcher cookie > Accept-Language > fallback fr).
 */
return [

    'flash' => [
        'token_revoked_regenerate' => 'This token is revoked: create a new one instead of regenerating it.',
        'paste_ebp_return' => 'Paste the EBP response (full URL or code).',
        'cannot_deactivate_last_admin' => 'Cannot deactivate the last active admin.',
        'code_not_found_in_paste' => 'Could not find the code in what you pasted.',
        'ebp_return_contains_error' => 'The pasted EBP response contains an error, not a code.',
        'state_mismatch' => 'The state does not match the session. Start “Reconnect” again.',
        'account_already_exists' => 'An account already exists with this address.',
        'admin_created' => 'Admin created.',
        'admin_reactivated' => 'Admin reactivated.',
        'admin_deactivated' => 'Admin deactivated.',
        'welcome_enable_2fa' => 'Welcome! Remember to enable two-factor authentication from your profile.',
        'account_created_connect' => 'EBP account created. Connect it to start the OAuth flow.',
        'account_updated' => 'EBP account updated.',
        'account_deleted' => 'EBP account deleted.',
        'invitation_revoked' => 'Invitation revoked.',
        'token_revoked' => 'Token revoked.',
        'preference_saved' => 'Preference saved.',
        'cache_cleared_all' => 'The entire read cache has been cleared.',
        'authorization_code_missing' => 'Authorization code missing.',
        'account_connected' => 'EBP account “:label” connected.',
        'invitation_no_longer_valid' => 'This invitation is no longer valid.',
        'invitation_sent' => 'Invitation sent to :email.',
        'connect_account_before_sync' => 'Connect the account to EBP first before syncing folders.',
        'folders_synced' => ':count folder(s) synced from EBP.',
        'sync_failed' => 'Sync failed: :error',
        'cache_cleared_account' => 'Read cache for account “:label” cleared.',
        'ip_allowed' => 'IP :ip is allowed (unlimited rate).',
        'ip_blocked' => 'IP :ip is blocked (403).',
        'ip_registered' => 'IP :ip is registered.',
        'oauth_access_denied' => 'Authorization denied on the EBP portal.',
        'oauth_portal_error' => 'EBP portal error: :error.',
        'oauth_invalid_grant' => 'Invalid or expired authorization code. The EBP code is valid for only a few moments and single-use: start “Reconnect” again and paste the response right away.',
        'oauth_redirect_uri' => 'The redirect_uri does not match the one authorized at EBP. Check that it is identical to the character (account redirect mode / EBP_LOCALHOST_REDIRECT).',
        'oauth_invalid_client' => 'EBP ClientId or ClientSecret rejected. Check the account credentials under “Edit”.',
        'oauth_invalid_scope' => 'Scopes rejected by EBP. Check the scope configuration (openid profile offline_access).',
        'oauth_exchange_failed' => 'EBP connection failed: :error',
    ],

    'audit' => [
        'account.created' => 'EBP account created',
        'account.updated' => 'EBP account updated',
        'account.deleted' => 'EBP account deleted',
        'account.folders_synced' => 'Folders synced',
        'token.created' => 'Token created',
        'token.revoked' => 'Token revoked',
        'token.regenerated' => 'Key regenerated',
        'token.rules_updated' => 'Access rules updated',
        'token.paired' => 'Token paired',
        'ip.blocked' => 'IP blocked',
        'ip.allowed' => 'IP allowed',
        'ip.unclassified' => 'IP unclassified',
        'ip.removed' => 'IP removed',
        'admin.created' => 'Admin created',
        'admin.deactivated' => 'Admin deactivated',
        'admin.reactivated' => 'Admin reactivated',
        'invitation.created' => 'Invitation sent',
        'invitation.revoked' => 'Invitation revoked',
        'cache.cleared_account' => 'Account cache cleared',
        'cache.cleared_all' => 'Global cache cleared',
    ],

];
