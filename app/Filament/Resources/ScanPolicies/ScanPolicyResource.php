<?php

namespace App\Filament\Resources\ScanPolicies;

use App\Filament\Resources\ScanPolicies\Pages\ManageScanPolicies;
use App\Models\ScanPolicy;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ScanPolicyResource extends Resource
{
    protected static ?string $model = ScanPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Policies';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            Toggle::make('is_default')->label('Default policy'),
            TextInput::make('per_target_per_minute')->numeric()->required()->minValue(1),
            TextInput::make('global_concurrent')->numeric()->required()->minValue(1),
            TextInput::make('jitter_seconds')->numeric()->required()->minValue(0),
            TextInput::make('task_spacing_seconds')->numeric()->required()->minValue(0),
            TextInput::make('deep_cooldown_hours')->numeric()->required()->minValue(0),
            Toggle::make('quiet_hours_enabled'),
            TextInput::make('quiet_hours_start')->numeric()->minValue(0)->maxValue(23),
            TextInput::make('quiet_hours_end')->numeric()->minValue(0)->maxValue(23),
            TextInput::make('timezone')->required()->maxLength(64),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                IconColumn::make('is_default')->boolean(),
                TextColumn::make('per_target_per_minute')->label('Per target/min'),
                TextColumn::make('global_concurrent')->label('Global concurrent'),
                TextColumn::make('jitter_seconds'),
                TextColumn::make('task_spacing_seconds')->label('Spacing'),
                IconColumn::make('quiet_hours_enabled')->boolean()->label('Quiet hours'),
            ])
            ->recordActions([
                EditAction::make(),
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
            'index' => ManageScanPolicies::route('/'),
        ];
    }
}
