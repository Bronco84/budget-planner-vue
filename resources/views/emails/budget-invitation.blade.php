<x-mail::message>
# You're invited to a budget

{{ $inviterName }} invited you to the budget **{{ $budgetName }}** as {{ $role === 'editor' ? 'an' : 'a' }} **{{ $role }}**.

@if ($role === 'viewer')
Viewers can see the budget, its accounts and transactions, but can't change anything.
@elseif ($role === 'editor')
Editors can view and change the budget's transactions, accounts, categories and plans.
@else
Owners have full control, including settings, sharing and bank connections.
@endif

<x-mail::button :url="$acceptUrl">
{{ $hasAccount ? 'Review invitation' : 'Create your account' }}
</x-mail::button>

@if ($hasAccount)
Sign in with this email address to accept.
@else
You'll create a Budget Planner account with this email address and set up a passkey; no password needed.
@endif

This invitation expires on {{ $expiresAt->format('F j, Y') }} and can only be used once. If you weren't expecting it, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
