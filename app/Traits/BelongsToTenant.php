<?php

namespace App\Traits;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * @mixin \Illuminate\Database\Eloquent\Model
 * @method static void addGlobalScope($scope, \Closure $implementation)
 * @method static void creating(\Closure $callback)
 * @method \Illuminate\Database\Eloquent\Relations\BelongsTo belongsTo($related, $foreignKey = null, $ownerKey = null, $relation = null)
 */

trait BelongsToTenant
{
    protected static function bootBelongsToTenant()
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if (Auth::check()) {
                // Menambahkan perlindungan: jika user tidak punya tenant_id, paksa query kosong/gagal
                $tenantId = Auth::user()->tenant_id;
                $builder->where('tenant_id', $tenantId ?? 0); 
            }
        });

        static::creating(function ($model) {
            if (Auth::check() && Auth::user()->tenant_id) {
                $model->tenant_id = Auth::user()->tenant_id;
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}