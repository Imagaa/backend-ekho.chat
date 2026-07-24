<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Allowlist email yang boleh dipakai login ke dashboard demo. Realm demo,
 * tidak terkait tabel users/admin_users. Lihat AGENTS.md §DEMO DASHBOARD.
 */
class DemoAllowedEmail extends Model
{
    protected $fillable = [
        'email',
        'label',
        'created_by',
    ];

    public function creator()
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }
}
