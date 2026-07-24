<?php

namespace App\Filament\Resources\AuditLogs\Pages;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use Filament\Resources\Pages\ManageRecords;

/**
 * Read-only — tidak ada CreateAction. Audit log hanya bisa terisi lewat
 * activity() helper di kode, bukan diinput manual dari panel.
 */
class ManageAuditLogs extends ManageRecords
{
    protected static string $resource = AuditLogResource::class;
}
