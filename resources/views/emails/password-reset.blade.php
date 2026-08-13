<x-mail::message>
# Reset Your Password

You requested a password reset for your TradeWatch account.

<x-mail::button :url="$resetUrl">
Reset Password
</x-mail::button>

This link expires in {{ $expiresInMinutes }} minutes. If you did not request a password reset, no further action is required.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
