<?php

namespace App\Filament\Resources\Findings;

use App\Enums\FindingSeverity;
use App\Filament\Resources\Findings\Pages\ListFindings;
use App\Filament\Resources\Findings\Pages\ViewFinding;
use App\Filament\Resources\Findings\Schemas\FindingInfolist;
use App\Filament\Resources\Findings\Tables\FindingsTable;
use App\Filament\Support\NavigationGroup;
use App\Models\Finding;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class FindingResource extends Resource
{
    protected static ?string $model = Finding::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBugAnt;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Security;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    protected static int $globalSearchResultsLimit = 8;

    public static function getNavigationBadge(): ?string
    {
        $count = static::openHighCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Open high-severity findings';
    }

    public static function openHighCount(): int
    {
        return once(fn (): int => Finding::query()
            ->issues()
            ->open()
            ->where('severity', FindingSeverity::High)
            ->count());
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'cve'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->issues()->with(['asset', 'repository']);
    }

    /**
     * @param  Finding  $record
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Severity' => $record->severity->getLabel(),
            'Asset' => $record->subjectName(),
            'Status' => $record->status->getLabel(),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return FindingInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FindingsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFindings::route('/'),
            'view' => ViewFinding::route('/{record}'),
        ];
    }
}
