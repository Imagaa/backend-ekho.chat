<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ManageAuditLogs;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Spatie\Activitylog\Models\Activity;

/**
 * Read-only. Audit trail setiap aksi Superadmin — tidak bisa dibuat/diubah/
 * dihapus dari panel, hanya terisi otomatis via activity() helper di kode.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Audit Log';

    protected static ?string $recordTitleAttribute = 'description';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('event')->label('Aksi'),
            TextEntry::make('causer.name')->label('Dilakukan Oleh')->placeholder('System'),
            TextEntry::make('description'),
            TextEntry::make('subject_type')->label('Tipe Subjek'),
            TextEntry::make('created_at')->label('Waktu')->dateTime('d M Y H:i:s'),
            KeyValueEntry::make('properties')->label('Detail (before/after)'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i:s')
                    ->sortable(),
                TextColumn::make('causer.name')
                    ->label('Admin')
                    ->placeholder('System')
                    ->searchable(),
                TextColumn::make('event')
                    ->badge()
                    ->searchable(),
                TextColumn::make('description')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('subject_type')
                    ->label('Tipe Subjek')
                    ->formatStateUsing(fn (?string $state) => $state ? class_basename($state) : '—'),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->options(fn () => Activity::query()
                        ->whereNotNull('event')
                        ->distinct()
                        ->pluck('event', 'event')
                        ->toArray()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAuditLogs::route('/'),
        ];
    }
}
