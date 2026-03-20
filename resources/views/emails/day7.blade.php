<x-mail::message>
# Ready to Power Up, {{ $user->name }}?

You've been using Offerra for a week now! Have you tried our **Browser Extension**? 

It's the easiest way to add jobs directly from LinkedIn, Indeed, and Google Jobs without ever leaving the page. 10,000+ users already love it.

<x-mail::button :url="config('app.frontend_url') . '/#extension'">
Install the Companion
</x-mail::button>

Let Offerra do the heavy lifting while you focus on the interview!

The Offerra Team
</x-mail::message>
