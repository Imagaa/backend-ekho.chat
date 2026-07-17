<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Campaign extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'template_id', 'contact_group_id', 'name', 
        'scheduled_at', 'status', 'total_contacts', 'total_cost'
    ];

    public function template()
    {
        return $this->belongsTo(Template::class);
    }

    public function group()
    {
        return $this->belongsTo(ContactGroup::class, 'contact_group_id');
    }
}