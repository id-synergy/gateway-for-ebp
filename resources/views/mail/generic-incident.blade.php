<x-mail::message>
# {{ $title }}

Un incident d'exploitation a été détecté sur la Gateway API EBP.

- **Type :** {{ $type }}
- **Objet concerné :** {{ $subjectLabel }}
- **Détail :** {{ $detail }}

## Action attendue

{{ $actionExpected }}

Merci,<br>
Gateway API EBP
</x-mail::message>
