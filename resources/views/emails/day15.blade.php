<x-mail::message>
# Half a Month in with Offerra!

Congratulations on your persistence, {{ $user->name }}! You're clearly serious about your career growth. 

Did you know that **Offerra Pro** gives you unlimited CV optimizations and cover letter generations? If you're currently on our free plan and really want to squeeze every ounce of performance out of your search, Pro is the way to go.

<x-mail::button :url="config('app.frontend_url') . '/dashboard/billing'">
Check Out Pro
</x-mail::button>

Whether you're Pro or Free, we're here to help you get that offer!

The Offerra Team
</x-mail::message>
