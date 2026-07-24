<?php

namespace App\Filament\Resources\DemoAccessTokens;

use App\Filament\Resources\DemoAccessTokens\Pages\ManageDemoAccessTokens;
use App\Models\DemoAccessToken;
use App\Services\DemoTokenService;
use BackedEnum;
use UnitEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Superadmin kelola token akses dashboard demo. Realm terpisah dari tenant.
 * Lihat AGENTS.md §DEMO DASHBOARD.
 */
class DemoAccessTokenResource extends Resource
{
    protected static ?string $model = DemoAccessToken::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Demo';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Token Demo';

    protected static ?string $recordTitleAttribute = 'token';

    public static function form(Schema $schema): Schema
    {
        // Hanya dipakai untuk membuat token PERMANENT (rotating tidak dibuat manual).
        return $schema->components([
            TextInput::make('label')
                ->label('Catatan / Keterangan')
                ->helperText('Mis. "Tim Sales", "Webinar Agustus" — memudahkan mengenali token permanen ini.')
                ->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('type', 'asc')
            ->columns([
                TextColumn::make('type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'rotating' ? 'Rotating (5 jam)' : 'Permanen')
                    ->color(fn (string $state) => $state === 'rotating' ? 'info' : 'success'),
                TextColumn::make('token')
                    ->label('Token')
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->copyMessage('Token disalin')
                    ->copyMessageDuration(1500),
                TextColumn::make('label')
                    ->label('Keterangan')
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('is_revoked')
                    ->label('Dicabut')
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedNoSymbol)
                    ->falseIcon(Heroicon::OutlinedCheckCircle)
                    ->trueColor('danger')
                    ->falseColor('success'),
                TextColumn::make('rotated_at')
                    ->label('Terakhir Di-shuffle')
                    ->dateTime('d M Y H:i')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('creator.name')
                    ->label('Dibuat Oleh')
                    ->placeholder('System')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->label('Jenis')->options([
                    'rotating'  => 'Rotating',
                    'permanent' => 'Permanen',
                ]),
            ])
            ->recordActions([
                self::rotateNowAction(),
                self::toggleRevokeAction(),
                DeleteAction::make()
                    ->visible(fn (DemoAccessToken $record) => $record->type === 'permanent'),
            ]);
    }

    protected static function rotateNowAction(): Action
    {
        return Action::make('rotateNow')
            ->label('Shuffle Sekarang')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('info')
            ->visible(fn (DemoAccessToken $record) => $record->type === 'rotating')
            ->requiresConfirmation()
            ->modalDescription('Token rotating akan diganti dengan kode baru. Sesi demo yang sedang aktif dengan token lama akan langsung tidak berlaku.')
            ->action(function () {
                $new = app(DemoTokenService::class)->rotate();

                activity('admin_audit')
                    ->causedBy(Auth::guard('admin')->user())
                    ->event('rotate_demo_token')
                    ->log('Shuffle manual token demo rotating');

                Notification::make()
                    ->title("Token rotating baru: {$new->token}")
                    ->success()
                    ->send();
            });
    }

    protected static function toggleRevokeAction(): Action
    {
        return Action::make('toggleRevoke')
            ->label(fn (DemoAccessToken $record) => $record->is_revoked ? 'Aktifkan' : 'Cabut')
            ->icon(fn (DemoAccessToken $record) => $record->is_revoked ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedNoSymbol)
            ->color(fn (DemoAccessToken $record) => $record->is_revoked ? 'success' : 'danger')
            ->visible(fn (DemoAccessToken $record) => $record->type === 'permanent')
            ->requiresConfirmation()
            ->modalDescription(fn (DemoAccessToken $record) => $record->is_revoked
                ? 'Token permanen ini akan bisa dipakai login demo kembali.'
                : 'Token permanen ini langsung tidak berlaku. Sesi demo yang memakainya akan terputus.')
            ->action(function (DemoAccessToken $record) {
                $wasRevoked = $record->is_revoked;
                $record->update(['is_revoked' => ! $wasRevoked]);

                activity('admin_audit')
                    ->causedBy(Auth::guard('admin')->user())
                    ->performedOn($record)
                    ->event($wasRevoked ? 'reactivate_demo_token' : 'revoke_demo_token')
                    ->log(($wasRevoked ? 'Aktifkan' : 'Cabut') . " token demo permanen '{$record->token}'");

                Notification::make()
                    ->title($wasRevoked ? 'Token diaktifkan kembali' : 'Token dicabut')
                    ->success()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDemoAccessTokens::route('/'),
        ];
    }
}
