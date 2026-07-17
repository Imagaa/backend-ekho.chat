<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Contact extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = ['tenant_id', 'contact_group_id', 'name', 'phone', 'dynamic_data'];

    protected $casts = [
        'dynamic_data' => 'encrypted:array',
    ];

    public function group()
    {
        return $this->belongsTo(ContactGroup::class, 'contact_group_id');
    }
}