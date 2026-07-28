<?php

namespace App\Filament\Resources\Findings;

use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Filament\Resources\Findings\Pages\ManageFindings;
use App\Models\Finding;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FindingResource extends Resource
{
    protected static ?string $model = Finding::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationLabel = 'Findings';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('status')
                ->options(collect(FindingStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value]))
                ->required()
                ->native(false),
            TextInput::make('title')->disabled(),
            TextInput::make('severity')->disabled(),
            TextInput::make('source')->disabled(),
            TextInput::make('category')->disabled(),
            TextInput::make('cve')->disabled(),
            Textarea::make('description')->disabled()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('severity')->badge()->sortable(),
                TextColumn::make('title')->searchable()->limit(60),
                TextColumn::make('asset.value')->label('Target')->searchable(),
                TextColumn::make('source')->badge(),
                TextColumn::make('category')->toggleable(),
                TextColumn::make('cve')->toggleable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('severity')->options(collect(FindingSeverity::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value])),
                SelectFilter::make('status')->options(collect(FindingStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value])),
                SelectFilter::make('source')->options([
                    'nmap' => 'nmap',
                    'nuclei' => 'nuclei',
                    'zap' => 'zap',
                    'dns' => 'dns',
                    'http' => 'http',
                ]),
            ])
            ->recordActions([
                EditAction::make()->label('Triage'),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFindings::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
