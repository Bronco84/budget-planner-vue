<x-mail::message>
# Login to Budget Planner

Hello {{ $user->name }},

Click the button below to securely log in to your Budget Planner account. This link will expire in 15 minutes.

<x-mail::button :url="$url">
Log In
</x-mail::button>

If you didn't request this login link, you can safely ignore this email.

For security, this link can only be used once and will expire shortly.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>

