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
        $otp = (string) random_int(100000, 999999);

        // Simpan ke Redis Cache selama 5 menit (300 detik) menggunakan cache store bawaan
        Cache::put('otp_login_' . $user->email, $otp, 300);

        // Reset counter percobaan gagal setiap kali OTP baru dikirim
        Cache::forget('otp_attempts_' . $user->email);
        Cache::forget('otp_locked_' . $user->email);

        // Tembak ke API Resend
        Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.resend.key'),
            'Content-Type' => 'application/json'
        ])->post('https://api.resend.com/emails', [
            'from' => config('services.resend.from_address'),
            'to' => $user->email,
            'subject' => 'Kode OTP Ekho Chat Anda',
            'html' => "<p>Kode verifikasi Anda adalah: <strong>{$otp}</strong>. Berlaku selama 5 menit.</p>"
        ]);

        return response()->json(['message' => 'Kode OTP telah dikirim ke email Anda']);
    }

    /**
     * TAHAP 2: Verifikasi OTP & Login (Menggantikan Password)
     */
    // SESUDAH
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|string|size:6'
        ]);

        // Patch 3C: Cek apakah akun sedang dikunci karena terlalu banyak percobaan gagal
        if (Cache::has('otp_locked_' . $request->email)) {
            return response()->json([
                'message' => 'Akun sementara dikunci karena terlalu banyak percobaan gagal. Silakan request OTP baru.'
            ], 429);
        }

        $attemptKey   = 'otp_attempts_' . $request->email;
        $attempts     = (int) Cache::get($attemptKey, 0);
        $cachedOtp    = Cache::get('otp_login_' . $request->email);

        // Komparasi mutlak
        if (!$cachedOtp || $cachedOtp !== $request->otp) {
            $newAttempts = $attempts + 1;

            if ($newAttempts >= 3) {
                // Hancurkan OTP & kunci akun 10 menit, paksa user request OTP baru
                Cache::forget('otp_login_' . $request->email);
                Cache::put('otp_locked_' . $request->email, true, 600);
                Cache::forget($attemptKey);
                return response()->json([
                    'message' => 'Terlalu banyak percobaan gagal. OTP dibatalkan. Silakan request OTP baru.'
                ], 429);
            }

            Cache::put($attemptKey, $newAttempts, 600);
            $remaining = 3 - $newAttempts;
            return response()->json([
                'message' => "Kode OTP tidak valid atau sudah kedaluwarsa. Sisa percobaan: {$remaining}"
            ], 401);
        }

        $user = User::with('tenant')->where('email', $request->email)->first();

        // Tenant di-suspend oleh Superadmin — tolak login meski OTP valid
        if ($user->tenant && ! $user->tenant->is_active) {
            return response()->json([
                'message' => 'Akun tenant Anda sedang dinonaktifkan. Hubungi tim support.'
            ], 403);
        }

        // Hapus OTP & semua state percobaan setelah berhasil (Cegah Replay Attack)
        Cache::forget('otp_login_' . $request->email);
        Cache::forget($attemptKey);
        Cache::forget('otp_locked_' . $request->email);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => $user->load('tenant')
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