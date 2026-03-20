<x-mail::message>
# Welcome to Offerra, {{ $user->name }}!

We're thrilled to have you join our community of over 10,000 job seekers using AI to land their dream roles. 

Offerra is your new AI command center for your career. Here's what you can do right now:
* **Upload your CV**: Let our AI analyze your profile.
* **Track your applications**: Stay organized with our dashboard.
* **Generate Proposals**: Create high-converting cover letters in seconds.

<x-mail::button :url="config('app.frontend_url') . '/dashboard'">
Go to Dashboard
</x-mail::button>

If you have any questions, just reply to this email. We're here to help!

Best regards,<br>
The Offerra Team
</x-mail::message>
