<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function __invoke(Request $request)
    {
        return $this->safeCall(function () use ($request) {
            $data = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'subject' => 'nullable|string|max:255',
                'message' => 'required|string|max:5000',
            ]);

            \Illuminate\Support\Facades\Mail::to('hello@offerra.click')->send(new \App\Mail\ContactMail($data));

            return response()->json(['message' => 'Your message has been sent successfully!']);
        }, 'ContactController@__invoke');
    }
}
