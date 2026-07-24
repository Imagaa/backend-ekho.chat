<?php

namespace App\Filament\Resources\WhatsappNumberRequests\Pages;

use App\Filament\Resources\WhatsappNumberRequests\WhatsappNumberRequestResource;
use Filament\Resources\Pages\ManageRecords;

class ManageWhatsappNumberRequests extends ManageRecords
{
    protected static string $resource = WhatsappNumberRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
