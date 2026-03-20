<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ContactController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'subject' => 'nullable|string|max:255',
            'message' => 'required|string|max:5000',
        ]);

        \Illuminate\Support\Facades\Mail::to('hello@offerra.click')->send(new \App\Mail\ContactMail($data));

        return response()->json(['message' => 'Your message has been sent successfully!']);
    }
}
