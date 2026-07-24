<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Token akses dashboard demo. Realm terpisah — TIDAK terhubung ke User/Tenant.
 * Lihat AGENTS.md §DEMO DASHBOARD.
 *
 * type = 'rotating'  → hanya ada 1 baris aktif, di-shuffle tiap 5 jam &
 *                      saat ada logout manual (DemoTokenService::rotate()).
 * type = 'permanent' → dibuat manual Superadmin, tidak pernah rotate,
 *                      bisa di-revoke kapan saja (is_revoked).
 */
class DemoAccessToken extends Model
{
    protected $fillable = [
        'token',
        'type',
        'label',
        'is_revoked',
        'rotated_at',
        'created_by',
    ];

    protected $casts = [
        'is_revoked' => 'boolean',
        'rotated_at' => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function scopeRotating($query)
    {
        return $query->where('type', 'rotating');
    }

    public function scopePermanent($query)
    {
        return $query->where('type', 'permanent');
    }
}
