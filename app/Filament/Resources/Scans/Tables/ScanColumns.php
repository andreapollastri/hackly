<?php

namespace App\Filament\Resources\Scans\Tables;

use App\Enums\ScanProfile;
use App\Enums\ScanStatus;
use App\Models\RepoScan;
use App\Models\Scan;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;

/**
 * Columns and filters shared by every scan table (target scans, repository scans, relation managers).
 */
class ScanColumns
{
    /**
     * @return list<Column>
     */
    public static function columns(?Column $subject = null): array
    {
        return array_values(array_filter([
            $subject,
            TextColumn::make('status')
                ->badge()
                ->sortable(),
            TextColumn::make('profile')
                ->badge()
                ->color('gray'),
            ViewColumn::make('progress')
                ->label('Progress')
                ->view('filament.tables.columns.scan-progress'),
            ViewColumn::make('findings_summary')
                ->label('Issues')
                ->view('filament.tables.columns.scan-findings-summary')
                ->state(fn (Scan|RepoScan $record): array => $record->findingsSeveritySummary()),
            TextColumn::make('created_at')
                ->label('Started')
                ->since()
                ->dateTimeTooltip()
                ->sortable(),
            TextColumn::make('duration')
                ->label('Duration')
                ->state(fn (Scan|RepoScan $record): ?string => $record->durationForHumans())
                ->placeholder('—')
                ->color('gray')
                ->toggleable(),
            TextColumn::make('id')
                ->label('ID')
                ->fontFamily('mono')
                ->limit(8, end: '')
                ->copyable()
                ->copyMessage('Scan ID copied')
                ->tooltip(fn (Scan|RepoScan $record): string => $record->id)
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
        ]));
    }

    /**
     * @return list<SelectFilter>
     */
    public static function filters(): array
    {
        return [
            SelectFilter::make('status')
                ->options(ScanStatus::class)
                ->multiple(),
            SelectFilter::make('profile')
                ->options(ScanProfile::class),
        ];
    }
}
