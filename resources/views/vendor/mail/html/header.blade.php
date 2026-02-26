@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if (trim($slot) === 'Laravel')
<span style="color: #1C4ED8; font-size: 24px; font-weight: 900; letter-spacing: -1px;">{{ config('app.name') }}</span>
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
