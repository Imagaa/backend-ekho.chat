<?php

namespace App\Filament\Resources\DemoAllowedEmails;

use App\Filament\Resources\DemoAllowedEmails\Pages\ManageDemoAllowedEmails;
use App\Models\DemoAllowedEmail;
use BackedEnum;
use UnitEnum;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Allowlist email yang boleh dipakai login ke dashboard demo. Realm demo.
 * Lihat AGENTS.md §DEMO DASHBOARD.
 */
class DemoAllowedEmailResource extends Resource
{
    protected static ?string $model = DemoAllowedEmail::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Demo';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Email Demo';

    protected static ?string $recordTitleAttribute = 'email';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')
                ->label('Email')
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            TextInput::make('label')
                ->label('Catatan / Keterangan')
                ->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'asc')
            ->columns([
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Email disalin'),
                TextColumn::make('label')
                    ->label('Keterangan')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Ditambahkan')
                    ->dateTime('d M Y')
                    ->sortable(),
            ])
            ->recordActions([
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDemoAllowedEmails::route('/'),
        ];
    }
}
