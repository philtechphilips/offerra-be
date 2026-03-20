<x-mail::message>
<div style="text-align: center; margin-bottom: 30px;">
    <img src="https://offerra.click/logo.png" alt="Offerra" style="width: 64px; height: auto; border-radius: 16px;">
    <div style="font-size: 24px; font-weight: 900; color: #1C4ED8; letter-spacing: -0.025em; text-transform: uppercase; margin-top: 12px;">Offerra</div>
</div>

# Payment Successful!

Hi {{ $transaction->user->name }},

Thank you for your purchase. We've successfully processed your payment for the **{{ $transaction->plan->name }}** plan.

Your account has been credited with **{{ $transaction->plan->credits }} credits**.

### Order Receipt
<div style="background-color: #f9f9f9; padding: 25px; border-radius: 12px; border: 1px solid #e2e2e2;">
    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 15px;">
        <img src="{{ config('app.frontend_url') }}/logo.png" alt="O" style="width: 24px; height: auto;">
        <span style="font-size: 14px; font-weight: 900; color: #1C4ED8; letter-spacing: -0.05em; text-transform: uppercase;">Payment Receipt</span>
    </div>
    <table style="width: 100%; font-size: 14px; color: #555;">
        <tr>
            <td style="padding-bottom: 8px;"><strong>Order ID:</strong></td>
            <td style="text-align: right; padding-bottom: 8px;"><code>{{ $transaction->reference }}</code></td>
        </tr>
        <tr>
            <td style="padding-bottom: 8px;"><strong>Plan:</strong></td>
            <td style="text-align: right; padding-bottom: 8px;">{{ $transaction->plan->name }}</td>
        </tr>
        <tr>
            <td style="padding-bottom: 8px;"><strong>Amount Paid:</strong></td>
            <td style="text-align: right; padding-bottom: 8px;">{{ $transaction->currency }} {{ number_format($transaction->amount, 2) }}</td>
        </tr>
        <tr>
            <td style="padding-bottom: 8px;"><strong>Credits Added:</strong></td>
            <td style="text-align: right; padding-bottom: 8px;">{{ number_format($transaction->plan->credits) }} credits</td>
        </tr>
        <tr>
            <td style="padding-bottom: 0;"><strong>Date:</strong></td>
            <td style="text-align: right; padding-bottom: 0;">{{ $transaction->created_at->format('M d, Y H:i') }}</td>
        </tr>
    </table>
</div>

<x-mail::button :url="config('app.frontend_url') . '/dashboard'">
Launch Dashboard
</x-mail::button>

If you have any questions or didn't make this purchase, please contact us immediately at [hello@offerra.click](mailto:hello@offerra.click).

Keep winning,<br>
The Offerra Team
</x-mail::message>
