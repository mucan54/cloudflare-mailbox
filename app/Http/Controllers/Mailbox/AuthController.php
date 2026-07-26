<?php

namespace App\Http\Controllers\Mailbox;

use App\Http\Controllers\Controller;
use App\Models\Mailbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device' => ['nullable', 'string'],
        ]);

        $mailbox = Mailbox::where('email', $data['email'])->first();

        if (! $mailbox || ! $mailbox->login_enabled || ! $mailbox->password || ! Hash::check($data['password'], $mailbox->password)) {
            throw ValidationException::withMessages([
                'email' => ['E-posta veya şifre hatalı ya da giriş kapalı.'],
            ]);
        }

        $mailbox->forceFill(['last_login_at' => now()])->save();

        $token = $mailbox->createToken($data['device'] ?? 'mailbox')->plainTextToken;

        return response()->json([
            'token' => $token,
            'mailbox' => $this->present($mailbox),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['mailbox' => $this->present($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['nullable', 'string', 'max:255'],
            'signature' => ['nullable', 'string', 'max:5000'],
        ]);

        $request->user()->update($data);

        return response()->json(['mailbox' => $this->present($request->user()->fresh())]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $mailbox = $request->user();

        if (! Hash::check($data['current_password'], $mailbox->password)) {
            throw ValidationException::withMessages(['current_password' => ['Mevcut şifre hatalı.']]);
        }

        $mailbox->update(['password' => $data['password']]);

        return response()->json(['ok' => true]);
    }

    private function present(Mailbox $mailbox): array
    {
        return [
            'id' => $mailbox->id,
            'email' => $mailbox->email,
            'display_name' => $mailbox->display_name,
            'signature' => $mailbox->signature,
            'unread' => $mailbox->emails()->whereNull('read_at')->count(),
            'calendar_feed_url' => url('/calendar/'.$mailbox->calendarToken().'.ics'),
        ];
    }
}
