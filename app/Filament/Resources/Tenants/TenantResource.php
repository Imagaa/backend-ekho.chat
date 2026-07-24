<?php

namespace App\Filament\Resources\Tenants;

use App\Filament\Resources\Tenants\Pages\ManageTenants;
use App\Models\Tenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $recordTitleAttribute = 'company_name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('company_name')
                ->label('Nama Perusahaan')
                ->required()
                ->maxLength(255),
            TextInput::make('waba_phone_id')
                ->label('WhatsApp Phone Number ID (api.co.id)')
                ->helperText('Didapat dari dashboard api.co.id setelah proses Embedded Signup untuk tenant ini selesai. Lihat documentation.md §7.')
                ->maxLength(255),
            Toggle::make('is_active')
                ->label('Tenant Aktif')
                ->default(true)
                ->helperText('Nonaktif = user tenant ini tidak bisa login sama sekali.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company_name')
                    ->label('Perusahaan')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('waba_phone_id')
                    ->label('WABA Phone ID')
                    ->toggleable(),
                TextColumn::make('wallet.balance')
                    ->label('Saldo')
                    ->money('IDR')
                    ->sortable(),
                TextColumn::make('users_count')
                    ->label('User')
                    ->counts('users'),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Status Aktif'),
            ])
            ->recordActions([
                self::toggleActiveAction(),
                EditAction::make(),
            ]);
    }

    protected static function toggleActiveAction(): Action
    {
        return Action::make('toggleActive')
            ->label(fn (Tenant $record) => $record->is_active ? 'Suspend' : 'Aktifkan')
            ->icon(fn (Tenant $record) => $record->is_active ? Heroicon::OutlinedNoSymbol : Heroicon::OutlinedCheckCircle)
            ->color(fn (Tenant $record) => $record->is_active ? 'danger' : 'success')
            ->requiresConfirmation()
            ->modalDescription(fn (Tenant $record) => $record->is_active
                ? 'Semua user tenant ini tidak akan bisa login, dan seluruh token aktif langsung dicabut.'
                : 'User tenant ini akan bisa login kembali.')
            ->action(function (Tenant $record) {
                $wasActive = $record->is_active;
                $record->update(['is_active' => ! $wasActive]);

                if ($wasActive) {
                    // Suspend: cabut semua Sanctum token aktif milik user tenant ini
                    \Laravel\Sanctum\PersonalAccessToken::whereIn(
                        'tokenable_id',
                        $record->users()->pluck('id')
                    )->where('tokenable_type', \App\Models\User::class)->delete();
                }

                activity('admin_audit')
                    ->causedBy(Auth::guard('admin')->user())
                    ->performedOn($record)
                    ->event($wasActive ? 'suspend_tenant' : 'reactivate_tenant')
                    ->log(($wasActive ? 'Suspend' : 'Aktifkan') . " tenant '{$record->company_name}'");

                Notification::make()
                    ->title($wasActive ? 'Tenant disuspend & token dicabut' : 'Tenant diaktifkan kembali')
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTenants::route('/'),
        ];
    }
}
