<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ContactImport extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'contact_group_id',
        'user_id',
        'file_name',
        'file_path',
        'status',
        'imported_count',
        'skipped_count',
        'error_message',
        'retention_policy',
        'retention_days',
        'expires_at',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function group()
    {
        return $this->belongsTo(ContactGroup::class, 'contact_group_id');
    }
}
