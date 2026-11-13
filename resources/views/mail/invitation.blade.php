<x-mail::message>
# Vous êtes invité·e sur la Gateway EBP

Bonjour {{ $name }},

Vous avez été invité·e à rejoindre le back-office d'administration de la **Gateway EBP**.
Cliquez sur le bouton ci-dessous pour **créer votre compte** en choisissant votre mot de passe.

<x-mail::button :url="$acceptUrl">
Créer mon compte / Create my account
</x-mail::button>

Ce lien est **valable jusqu'au {{ $expiresAt }}** (24 h) et ne peut être utilisé qu'une fois.
Passé ce délai, demandez une nouvelle invitation à votre administrateur.

Une fois connecté·e, pensez à **activer la double authentification** depuis votre profil.

---

# You have been invited to the EBP Gateway

Hello {{ $name }},

You have been invited to join the admin back office of the **EBP Gateway**.
Click the button above to **create your account** by choosing your password.

This link is **valid until {{ $expiresAt }}** (24 h) and can only be used once.
After that, ask your administrator for a new invitation.

Once signed in, remember to **enable two-factor authentication** from your profile.

Merci / Thank you,<br>
Gateway API EBP
</x-mail::message>
