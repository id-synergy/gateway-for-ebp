<x-mail::message>
# {{ $title }}

@include('mail.partials.alert-banner', ['level' => in_array($type, ['quota', 'info']) ? 'info' : 'danger', 'label' => "Incident d'exploitation", 'labelEn' => 'Operational incident'])

Un incident d'exploitation a été détecté sur la Gateway API EBP.

- **Type :** {{ $type }}
- **Objet concerné :** {{ $subjectLabel }}
- **Détail :** {{ $detail }}

## Action attendue

{{ $actionExpected }}

---

# {{ $title }}

An operational incident has been detected on the EBP Gateway API.

- **Type:** {{ $type }}
- **Subject:** {{ $subjectLabel }}
- **Detail:** {{ $detail }}

## Expected action

{{ $actionExpected }}

Merci / Thank you,<br>
Gateway API EBP
</x-mail::message>
