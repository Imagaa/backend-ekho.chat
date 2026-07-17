<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = ['company_name', 'waba_api_key', 'waba_endpoint', 'waba_phone_id'];

    // Fase 1: Enkripsi Kredensial Meta
    protected $casts = [
        'waba_api_key' => 'encrypted',
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