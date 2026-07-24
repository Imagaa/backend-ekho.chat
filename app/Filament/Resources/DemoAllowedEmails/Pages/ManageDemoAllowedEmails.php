<?php

namespace App\Filament\Resources\DemoAllowedEmails\Pages;

use App\Filament\Resources\DemoAllowedEmails\DemoAllowedEmailResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Auth;

class ManageDemoAllowedEmails extends ManageRecords
{
    protected static string $resource = DemoAllowedEmailResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tambah Email')
                ->mutateDataUsing(function (array $data): array {
                    $data['created_by'] = Auth::guard('admin')->id();

                    return $data;
                }),
        ];
    }
}
