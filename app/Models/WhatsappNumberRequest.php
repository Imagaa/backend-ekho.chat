<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappNumberRequest extends Model
{
    protected $fillable = [
        'tenant_id',
        'business_name',
        'phone_number',
        'notes',
        'status',
        'rejection_reason',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
