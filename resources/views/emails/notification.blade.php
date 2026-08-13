<x-mail::message>
{{ $message }}

@if($link)
<x-mail::button :url="$link">
View in TradeWatch
</x-mail::button>
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
