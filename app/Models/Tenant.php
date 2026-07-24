<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tenant extends Model
{
    use HasFactory;

    // Model reseller 1-akun-master (lihat documentation.md §7) — API key & base
    // URL api.co.id ada di config('services.apicoid'), BUKAN per-tenant.
    // waba_phone_id = whatsapp_phone_number_id milik api.co.id (bukan raw Meta ID).
    protected $fillable = ['company_name', 'is_active', 'waba_phone_id'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }
}