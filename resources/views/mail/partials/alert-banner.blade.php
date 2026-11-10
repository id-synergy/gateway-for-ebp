@props(['level' => 'info', 'label' => 'Information'])
@php
    // Couleurs par niveau, dans la charte de la gateway.
    $styles = [
        'danger'  => ['bg' => '#FBECEC', 'bar' => '#E24B4A', 'text' => '#8A2A29', 'icon' => '!'],
        'warning' => ['bg' => '#FDF4E0', 'bar' => '#FAC775', 'text' => '#633806', 'icon' => '△'],
        'info'    => ['bg' => '#E8EEF6', 'bar' => '#0046FE', 'text' => '#002C52', 'icon' => 'i'],
    ];
    $s = $styles[$level] ?? $styles['info'];
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 22px;">
<tr>
<td style="background-color: {{ $s['bg'] }}; border-left: 4px solid {{ $s['bar'] }}; border-radius: 10px; padding: 12px 16px;">
<span style="display:inline-block; width:22px; height:22px; line-height:22px; text-align:center; background-color: {{ $s['bar'] }}; color:#ffffff; border-radius:50%; font-weight:700; font-size:13px; margin-right:9px; vertical-align:middle;">{{ $s['icon'] }}</span>
<span style="color: {{ $s['text'] }}; font-weight:600; font-size:13px; letter-spacing:0.3px; vertical-align:middle;">{{ $label }}</span>
</td>
</tr>
</table>
