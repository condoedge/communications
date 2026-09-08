@component('mail::message')
@isset($preheader)
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#ffffff;opacity:0;">{{ $preheader }}</div>
@endisset
{!! $content !!}

{{-- Only present on suppressible mail: the header is minted alongside this, so the visible link
     and List-Unsubscribe always agree about whether the message can be stopped. --}}
@if (!empty($unsubscribeUrl))
<div style="margin-top:32px;padding-top:16px;border-top:1px solid #e4e7eb;font-size:12px;color:#616e7c;">
<a href="{{ $unsubscribeUrl }}" style="color:#616e7c;">{{ __('communications.unsubscribe-footer-link') }}</a>
</div>
@endif
@endcomponent
