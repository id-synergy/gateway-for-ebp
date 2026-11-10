<x-mail::message>
# {{ $title }}

@include('mail.partials.alert-banner', ['level' => in_array($type, ['quota', 'info']) ? 'info' : 'danger', 'label' => 'Incident d\'exploitation'])

Un incident d'exploitation a été détecté sur la Gateway API EBP.

- **Type :** {{ $type }}
- **Objet concerné :** {{ $subjectLabel }}
- **Détail :** {{ $detail }}

## Action attendue

{{ $actionExpected }}

Merci,<br>
Gateway API EBP
</x-mail::message>
