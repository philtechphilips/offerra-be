<x-mail::message>
# How's your first day with Offerra, {{ $user->name }}?

We saw you joined us yesterday, and we wanted to see how you're liking our AI assistant! 

Have you tried the **Job Application Detector**? It automatically extracts job details from any link so you don't have to copy-paste.

If you haven't uploaded your CV yet, today is a perfect day to start:

<x-mail::button :url="config('app.frontend_url') . '/dashboard/optimizer'">
Optimize Your CV
</x-mail::button>

We're rootting for your success!

The Offerra Team
</x-mail::message>
