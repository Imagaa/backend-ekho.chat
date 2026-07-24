<?php

namespace App\Filament\Resources\AdminUsers;

use App\Filament\Resources\AdminUsers\Pages\ManageAdminUsers;
use App\Models\AdminUser;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * SENGAJA tidak ada aksi "register diri sendiri" di mana pun di panel ini.
 * Akun admin baru hanya bisa dibuat oleh admin yang sudah login (lewat resource
 * ini) atau via `php artisan admin:create` untuk bootstrap pertama.
 */
class AdminUserResource extends Resource
{
    protected static ?string $model = AdminUser::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Superadmin';

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
            TextInput::make('password')
                ->password()
                ->revealable()
                ->minLength(12)
                ->helperText('Kosongkan saat edit jika tidak ingin mengubah password. 2FA disetup sendiri oleh admin bersangkutan saat login pertama — wajib, tidak bisa dilewati.')
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn (?string $state) => filled($state))
                ->dehydrateStateUsing(fn (string $state) => Hash::make($state)),
            Toggle::make('is_active')
                ->label('Aktif')
                ->default(true),
            Hidden::make('created_by')
                ->default(fn () => Auth::guard('admin')->id())
                ->dehydrated(fn (string $operation) => $operation === 'create'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable(),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('creator.name')
                    ->label('Dibuat Oleh')
                    ->placeholder('— (bootstrap CLI)'),
                TextColumn::make('last_login_at')
                    ->label('Login Terakhir')
                    ->dateTime('d M Y H:i')
                    ->placeholder('Belum pernah login'),
                TextColumn::make('last_login_ip')->label('IP Terakhir'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (AdminUser $record) => $record->id !== Auth::guard('admin')->id())
                    ->before(function (AdminUser $record) {
                        activity('admin_audit')
                            ->causedBy(Auth::guard('admin')->user())
                            ->performedOn($record)
                            ->event('delete_admin')
                            ->log("Hapus akun admin '{$record->email}'");
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAdminUsers::route('/'),
        ];
    }
}
