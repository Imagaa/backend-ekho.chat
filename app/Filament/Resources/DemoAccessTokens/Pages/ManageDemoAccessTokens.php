<?php

namespace App\Filament\Resources\DemoAccessTokens\Pages;

use App\Filament\Resources\DemoAccessTokens\DemoAccessTokenResource;
use App\Services\DemoTokenService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Auth;

class ManageDemoAccessTokens extends ManageRecords
{
    protected static string $resource = DemoAccessTokenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Buat Token Permanen')
                ->modalHeading('Buat Token Demo Permanen')
                ->mutateDataUsing(function (array $data): array {
                    $data['token']      = app(DemoTokenService::class)->generateTokenString();
                    $data['type']       = 'permanent';
                    $data['is_revoked'] = false;
                    $data['created_by'] = Auth::guard('admin')->id();

                    return $data;
                })
                ->after(function ($record) {
                    activity('admin_audit')
                        ->causedBy(Auth::guard('admin')->user())
                        ->performedOn($record)
                        ->event('create_demo_token')
                        ->log("Buat token demo permanen '{$record->token}'");
                }),
        ];
    }
}
