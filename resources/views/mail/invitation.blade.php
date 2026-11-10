<x-mail::message>
# Vous êtes invité·e sur la Gateway EBP

Bonjour {{ $name }},

Vous avez été invité·e à rejoindre le back-office d'administration de la **Gateway EBP**.
Cliquez sur le bouton ci-dessous pour **créer votre compte** en choisissant votre mot de passe.

<x-mail::button :url="$acceptUrl">
Créer mon compte
</x-mail::button>

Ce lien est **valable jusqu'au {{ $expiresAt }}** (24 h) et ne peut être utilisé qu'une fois.
Passé ce délai, demandez une nouvelle invitation à votre administrateur.

Une fois connecté·e, pensez à **activer la double authentification** depuis votre profil.

Merci,<br>
Gateway API EBP
</x-mail::message>
