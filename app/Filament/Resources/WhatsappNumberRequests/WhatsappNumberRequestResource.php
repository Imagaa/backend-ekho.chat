<?php

namespace App\Filament\Resources\WhatsappNumberRequests;

use App\Filament\Resources\WhatsappNumberRequests\Pages\ManageWhatsappNumberRequests;
use App\Models\WhatsappNumberRequest;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class WhatsappNumberRequestResource extends Resource
{
    protected static ?string $model = WhatsappNumberRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static ?string $navigationLabel = 'Pengajuan Nomor WA';

    protected static ?string $recordTitleAttribute = 'business_name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('business_name')->label('Nama Bisnis')->disabled(),
            TextInput::make('phone_number')->label('Nomor HP')->disabled(),
            Textarea::make('notes')->label('Catatan Tenant')->disabled()->columnSpanFull(),
            Select::make('status')
                ->label('Status')
                ->options([
                    'pending'    => 'Menunggu',
                    'processing' => 'Diproses',
                    'completed'  => 'Selesai',
                    'rejected'   => 'Ditolak',
                ])
                ->required(),
            Textarea::make('rejection_reason')
                ->label('Alasan Penolakan')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tenant.company_name')
                    ->label('Tenant')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('business_name')
                    ->label('Nama Bisnis')
                    ->searchable(),
                TextColumn::make('phone_number')
                    ->label('Nomor HP')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'pending'    => 'warning',
                        'processing' => 'info',
                        'completed'  => 'success',
                        'rejected'   => 'danger',
                        default      => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('Diajukan')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'pending'    => 'Menunggu',
                    'processing' => 'Diproses',
                    'completed'  => 'Selesai',
                    'rejected'   => 'Ditolak',
                ]),
            ])
            ->recordActions([
                self::markProcessingAction(),
                self::editAction(),
            ]);
    }

    protected static function markProcessingAction(): Action
    {
        return Action::make('markProcessing')
            ->label('Tandai Diproses')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('info')
            ->visible(fn (WhatsappNumberRequest $record) => $record->status === 'pending')
            ->action(function (WhatsappNumberRequest $record) {
                $record->update(['status' => 'processing']);

                activity('admin_audit')
                    ->causedBy(Auth::guard('admin')->user())
                    ->performedOn($record)
                    ->event('mark_processing_wa_request')
                    ->log("Tandai pengajuan nomor WA '{$record->business_name}' sebagai diproses");

                Notification::make()->title('Ditandai sebagai diproses')->success()->send();
            });
    }

    protected static function editAction(): Action
    {
        return \Filament\Actions\EditAction::make();
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageWhatsappNumberRequests::route('/'),
        ];
    }
}
