<?php

namespace App\Services;

use App\Models\DemoAccessToken;
use App\Models\DemoAllowedEmail;
use Illuminate\Support\Carbon;

/**
 * Logika token demo: generate, rotate, dan validasi login demo.
 * Realm terpisah — tidak menyentuh auth tenant/superadmin.
 * Lihat AGENTS.md §DEMO DASHBOARD.
 */
class DemoTokenService
{
    /** Interval rotasi token rotating (jam). */
    public const ROTATION_HOURS = 5;

    /** Karakter token — tanpa 0/O/1/I agar mudah dibacakan saat demo. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const TOKEN_LENGTH = 8;

    public function generateTokenString(): string
    {
        do {
            $token = '';
            for ($i = 0; $i < self::TOKEN_LENGTH; $i++) {
                $token .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (DemoAccessToken::where('token', $token)->exists());

        return $token;
    }

    /** Ambil token rotating aktif (dibuat kalau belum ada — fail-safe). */
    public function currentRotating(): DemoAccessToken
    {
        $rotating = DemoAccessToken::rotating()->latest('rotated_at')->first();

        if (! $rotating) {
            $rotating = DemoAccessToken::create([
                'token'      => $this->generateTokenString(),
                'type'       => 'rotating',
                'rotated_at' => now(),
            ]);
        }

        return $rotating;
    }

    /**
     * Ganti nilai token rotating & catat waktunya. Dipanggil oleh command
     * terjadwal (tiap 5 jam) dan saat ada logout manual sesi rotating.
     */
    public function rotate(): DemoAccessToken
    {
        $rotating = $this->currentRotating();
        $rotating->update([
            'token'      => $this->generateTokenString(),
            'rotated_at' => now(),
        ]);

        return $rotating->fresh();
    }

    /** Kapan token rotating saat ini akan kedaluwarsa. */
    public function rotatingExpiresAt(): Carbon
    {
        return $this->currentRotating()->rotated_at->copy()->addHours(self::ROTATION_HOURS);
    }

    public function isEmailAllowed(string $email): bool
    {
        return DemoAllowedEmail::where('email', $email)->exists();
    }

    /**
     * Validasi kombinasi email + token untuk login demo.
     * Return token yang cocok, atau null kalau tidak valid.
     */
    public function validate(string $email, string $token): ?DemoAccessToken
    {
        if (! $this->isEmailAllowed($email)) {
            return null;
        }

        $record = DemoAccessToken::where('token', $token)->first();

        if (! $record) {
            return null;
        }

        // Permanent yang sudah di-revoke tidak berlaku
        if ($record->type === 'permanent' && $record->is_revoked) {
            return null;
        }

        // Rotating hanya berlaku kalau memang token rotating terkini &
        // belum lewat jendela 5 jam
        if ($record->type === 'rotating') {
            $current = $this->currentRotating();
            if ($record->id !== $current->id || now()->greaterThan($this->rotatingExpiresAt())) {
                return null;
            }
        }

        return $record;
    }
}
