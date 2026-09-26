<x-mail::message>
# Activate Your TradeWatch Account

An administrator has created a TradeWatch account for you.

<x-mail::button :url="$activationUrl">
Activate Account
</x-mail::button>

Use this link to set your password and activate your account. If you were not expecting this invitation, no further action is required.

Thanks,<br>
@if($createdBy)
{{ $createdBy }}<br>
@endif
{{ config('app.name') }}
</x-mail::message>
