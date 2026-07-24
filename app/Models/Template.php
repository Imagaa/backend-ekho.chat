<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Template extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'meta_template_id', 'apicoid_template_id', 'name', 'category',
        'language', 'components', 'status', 'rejection_reason',
    ];

    protected $casts = [
        'components' => 'array',
    ];
}