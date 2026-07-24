<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DemoAccessToken;
use App\Services\DemoTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Auth dashboard DEMO — realm ketiga, terisolasi total dari tenant & superadmin.
 *
 * Sesi demo BUKAN Sanctum token: ia hanya "kunci tampilan" untuk halaman demo
 * yang datanya 100% dummy di frontend. Secara desain sesi ini TIDAK bisa dipakai
 * untuk mengakses endpoint `/api/*` produksi (tidak lolos `auth:sanctum`).
 * Lihat AGENTS.md §DEMO DASHBOARD.
 */
class DemoAuthController extends Controller
{
    /** Umur sesi demo untuk login via token permanent (jam). */
    private const PERMANENT_SESSION_HOURS = 24;

    public function login(Request $request, DemoTokenService $service)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required|string|max:16',
        ]);

        $record = $service->validate($request->email, strtoupper(trim($request->token)));

        if (! $record) {
            return response()->json(['message' => 'Email atau token demo tidak valid.'], 401);
        }

        $isRotating = $record->type === 'rotating';
        $expiresAt = $isRotating
            ? $service->rotatingExpiresAt()
            : now()->addHours(self::PERMANENT_SESSION_HOURS);

        $session = $this->issueSession($record, $request->email, $expiresAt->getTimestamp());

        return response()->json([
            'session'     => $session,
            'is_rotating' => $isRotating,
            'expires_at'  => $isRotating ? $expiresAt->toIso8601String() : null,
        ]);
    }

    public function session(Request $request, DemoTokenService $service)
    {
        $payload = $this->resolveSession($request->header('X-Demo-Session'));

        if (! $payload) {
            return response()->json(['valid' => false], 401);
        }

        $isRotating = $payload['type'] === 'rotating';

        return response()->json([
            'valid'       => true,
            'is_rotating' => $isRotating,
            'expires_at'  => $isRotating ? $service->rotatingExpiresAt()->toIso8601String() : null,
        ]);
    }

    public function logout(Request $request, DemoTokenService $service)
    {
        $payload = $this->resolveSession($request->header('X-Demo-Session'));

        // Logout manual pada sesi rotating → token langsung di-rotate supaya
        // kode yang sudah dibagikan tidak berlaku lagi. Permanent tidak terpengaruh.
        if ($payload && $payload['type'] === 'rotating') {
            $service->rotate();
        }

        return response()->json(['message' => 'Sesi demo diakhiri.']);
    }

    private function issueSession(DemoAccessToken $record, string $email, int $exp): string
    {
        return Crypt::encryptString(json_encode([
            'email'       => $email,
            'token_id'    => $record->id,
            'type'        => $record->type,
            'fingerprint' => hash('sha256', $record->token),
            'exp'         => $exp,
        ]));
    }

    /**
     * Verifikasi sesi demo. Return payload kalau valid, null kalau tidak.
     * Rotating yang sudah di-rotate → fingerprint tak cocok → invalid.
     * Permanent yang di-revoke → invalid.
     */
    private function resolveSession(?string $session): ?array
    {
        if (! $session) {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($session), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($payload) || ! isset($payload['token_id'], $payload['exp'], $payload['fingerprint'], $payload['type'])) {
            return null;
        }

        if (now()->getTimestamp() > (int) $payload['exp']) {
            return null;
        }

        $record = DemoAccessToken::find($payload['token_id']);

        if (! $record) {
            return null;
        }

        if ($record->type === 'permanent' && $record->is_revoked) {
            return null;
        }

        if (! hash_equals(hash('sha256', $record->token), (string) $payload['fingerprint'])) {
            return null;
        }

        return $payload;
    }
}
