<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AuthController extends Controller
{
    /**
     * TAHAP 1: Request OTP via Resend
     */
    public function requestOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'Kredensial tidak valid'], 404);
        }

        // Generate 6-digit OTP
        $otp = (string) rand(100000, 999999);

        // Simpan ke Redis Cache selama 5 menit (300 detik) menggunakan cache store bawaan
        Cache::put('otp_login_' . $user->email, $otp, 300);

        // Tembak ke API Resend
        Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.resend.key'),
            'Content-Type' => 'application/json'
        ])->post('https://api.resend.com/emails', [
            'from' => 'auth@ekho.imaga.site', // Pastikan domain ini sudah di-verify di dashboard Resend
            'to' => $user->email,
            'subject' => 'Kode OTP Ekho Chat Anda',
            'html' => "<p>Kode verifikasi Anda adalah: <strong>{$otp}</strong>. Berlaku selama 5 menit.</p>"
        ]);

        return response()->json(['message' => 'Kode OTP telah dikirim ke email Anda']);
    }

    /**
     * TAHAP 2: Verifikasi OTP & Login (Menggantikan Password)
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string|size:6'
        ]);

        $cachedOtp = Cache::get('otp_login_' . $request->email);

        // Komparasi mutlak
        if (!$cachedOtp || $cachedOtp !== $request->otp) {
            return response()->json(['message' => 'Kode OTP tidak valid atau sudah kedaluwarsa'], 401);
        }

        $user = User::where('email', $request->email)->first();

        // Hapus OTP dari memori setelah berhasil dipakai (Cegah Replay Attack)
        Cache::forget('otp_login_' . $request->email);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->load('tenant')
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logout berhasil']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->load('tenant'));
    }
}