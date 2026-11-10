<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion EBP (simulation)</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: #eef1f6; font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
            color: #1f2937; padding: 24px;
        }
        .card {
            width: 100%; max-width: 420px; background: #fff; border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 44, 82, 0.12); overflow: hidden;
        }
        .banner {
            background: #FAC775; color: #633806; font-size: 12px; font-weight: 700;
            letter-spacing: 0.4px; text-align: center; padding: 8px 12px;
        }
        .head { background: #002C52; color: #fff; padding: 26px 28px 22px; }
        .logo { font-size: 22px; font-weight: 800; letter-spacing: 1px; }
        .logo span { color: #FAC775; }
        .head p { margin: 6px 0 0; color: #9FB6D1; font-size: 13px; }
        .body { padding: 26px 28px 28px; }
        .body h1 { font-size: 17px; margin: 0 0 4px; color: #002C52; }
        .body .sub { margin: 0 0 18px; font-size: 13px; color: #6b7280; }
        label { display: block; font-size: 13px; font-weight: 600; margin: 14px 0 6px; color: #374151; }
        input[type=text], input[type=password] {
            width: 100%; height: 44px; border: 1px solid #d1d5db; border-radius: 10px;
            padding: 0 14px; font-size: 14px; background: #fff;
        }
        input:focus { outline: none; border-color: #0046FE; }
        .err {
            background: #FBECEC; border-left: 4px solid #E24B4A; color: #8A2A29;
            padding: 10px 14px; border-radius: 10px; font-size: 13px; margin-bottom: 14px;
        }
        .scope { font-size: 12px; color: #6b7280; margin: 16px 0 4px; }
        .actions { margin-top: 20px; display: flex; flex-direction: column; gap: 10px; }
        button {
            height: 46px; border: none; border-radius: 12px; font-size: 14px; font-weight: 700;
            cursor: pointer;
        }
        .btn-primary { background: #0046FE; color: #fff; }
        .btn-ghost { background: #eef1f6; color: #374151; }
        .btn-link { background: none; color: #6b7280; height: auto; padding: 6px; font-weight: 500; font-size: 12px; text-decoration: underline; }
        .note { margin-top: 18px; font-size: 11px; color: #9ca3af; text-align: center; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="card">
        <div class="banner">⚠ PORTAIL EBP SIMULÉ — ENVIRONNEMENT DE TEST</div>
        <div class="head">
            <div class="logo">EBP<span>|</span> Developer</div>
            <p>Connexion au compte développeur</p>
        </div>
        <div class="body">
            <h1>Identifiez-vous</h1>
            <p class="sub">Toute saisie est acceptée — ce portail sert à dérouler le flux OAuth en local.</p>

            @isset($error)
                <div class="err">{{ $error }}</div>
            @endisset

            <form method="POST" action="{{ url('/fake-ebp/connect/authorize') }}">
                @csrf
                <input type="hidden" name="redirect_uri" value="{{ $redirectUri }}">
                <input type="hidden" name="state" value="{{ $state }}">
                <input type="hidden" name="client_id" value="{{ $clientId }}">
                <input type="hidden" name="scope" value="{{ $scope }}">

                <label for="login">Identifiant EBP</label>
                <input type="text" id="login" name="login" placeholder="votre identifiant" autocomplete="off">

                <label for="password">Mot de passe</label>
                <input type="password" id="password" name="password" placeholder="••••••••" autocomplete="off">

                @if ($scope)
                    <p class="scope">L'application demande l'accès : <strong>{{ $scope }}</strong></p>
                @endif

                <div class="actions">
                    <button class="btn-primary" type="submit" name="decision" value="authorize">Autoriser l'accès</button>
                    <button class="btn-ghost" type="submit" name="decision" value="deny">Refuser</button>
                    <button class="btn-link" type="submit" name="decision" value="invalid">Simuler des identifiants invalides</button>
                </div>
            </form>

            <p class="note">
                Ceci est une page de test. Elle imite le portail d'identification EBP
                (api-login.ebp.com) pour développer sans identifiants réels.
            </p>
        </div>
    </div>
</body>
</html>
