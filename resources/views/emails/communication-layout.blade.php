@component('mail::message')
@isset($preheader)
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#ffffff;opacity:0;">{{ $preheader }}</div>
@endisset
{!! $content !!}
@endcomponent
