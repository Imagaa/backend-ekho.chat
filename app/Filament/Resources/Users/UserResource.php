<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Tenant Users';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            Select::make('tenant_id')
                ->label('Tenant')
                ->relationship('tenant', 'company_name')
                ->required()
                ->searchable()
                ->preload(),
            Select::make('role')
                ->options([
                    'Owner' => 'Owner',
                    'Admin' => 'Admin',
                    'CS' => 'CS',
                ])
                ->default('CS')
                ->required(),
            // User login via OTP email — password tidak pernah dipakai, cuma
            // memenuhi kolom NOT NULL. Tidak ditampilkan/diisi manual oleh admin.
            Hidden::make('password')
                ->default(fn () => Hash::make(Str::random(40)))
                ->dehydrated(fn (?string $state, string $operation) => $operation === 'create'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('tenant.company_name')
                    ->label('Tenant')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('role')->badge(),
                TextColumn::make('tokens_count')
                    ->label('Sesi Aktif')
                    ->counts('tokens'),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->options(['Owner' => 'Owner', 'Admin' => 'Admin', 'CS' => 'CS']),
                SelectFilter::make('tenant_id')
                    ->label('Tenant')
                    ->relationship('tenant', 'company_name')
                    ->searchable(),
            ])
            ->recordActions([
                self::revokeTokensAction(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    protected static function revokeTokensAction(): Action
    {
        return Action::make('revokeTokens')
            ->label('Revoke Token')
            ->icon(Heroicon::OutlinedKey)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('User ini akan langsung logout dari semua sesi/device dan wajib login ulang via OTP.')
            ->visible(fn (User $record) => $record->tokens()->exists())
            ->action(function (User $record) {
                $count = $record->tokens()->count();
                $record->tokens()->delete();

                activity('admin_audit')
                    ->causedBy(Auth::guard('admin')->user())
                    ->performedOn($record)
                    ->event('revoke_user_tokens')
                    ->log("Revoke {$count} token aktif milik user '{$record->email}'");

                Notification::make()
                    ->title("{$count} token dicabut")
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUsers::route('/'),
        ];
    }
}
