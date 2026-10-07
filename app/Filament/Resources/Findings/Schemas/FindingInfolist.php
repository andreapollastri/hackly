<?php

namespace App\Filament\Resources\Findings\Schemas;

use App\Enums\Reachability;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\RepoScans\RepoScanResource;
use App\Filament\Resources\Repositories\RepositoryResource;
use App\Filament\Resources\Scans\ScanResource;
use App\Models\Finding;
use Filament\Infolists\Components\CodeEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Phiki\Grammar\Grammar;

class FindingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->schema([
                        TextEntry::make('title')
                            ->hiddenLabel()
                            ->size('lg')
                            ->weight('semibold'),
                        Grid::make(['default' => 2, 'md' => 4])
                            ->schema([
                                TextEntry::make('severity')->badge(),
                                TextEntry::make('status')->badge(),
                                TextEntry::make('source')
                                    ->badge()
                                    ->color('gray'),
                                TextEntry::make('category')
                                    ->placeholder('—')
                                    ->formatStateUsing(fn (?string $state): string => Finding::categoryLabel($state)),
                            ]),
                        TextEntry::make('description')
                            ->hiddenLabel()
                            ->placeholder('No description provided by the scanner.')
                            ->color('gray')
                            ->columnSpanFull(),
                    ]),

                Section::make('Where')
                    ->icon(Heroicon::OutlinedMapPin)
                    ->compact()
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextEntry::make('subject')
                            ->label(fn (Finding $record): string => $record->isRepositoryFinding() ? 'Repository' : 'Target')
                            ->state(fn (Finding $record): string => $record->subjectName())
                            ->icon(fn (Finding $record): Heroicon => $record->isRepositoryFinding() ? Heroicon::OutlinedCodeBracketSquare : Heroicon::OutlinedGlobeAlt)
                            ->weight('medium')
                            ->url(fn (Finding $record): ?string => match (true) {
                                $record->asset_id !== null && $record->asset !== null => AssetResource::getUrl('view', ['record' => $record->asset_id]),
                                $record->repository_id !== null && $record->repository !== null => RepositoryResource::getUrl('view', ['record' => $record->repository_id]),
                                default => null,
                            }),
                        TextEntry::make('detected_by')
                            ->label('Last detected by')
                            ->state(fn (Finding $record): ?string => $record->scan_id || $record->repo_scan_id ? 'Scan '.substr((string) ($record->scan_id ?? $record->repo_scan_id), 0, 8) : null)
                            ->placeholder('—')
                            ->fontFamily('mono')
                            ->url(fn (Finding $record): ?string => match (true) {
                                $record->scan_id !== null => ScanResource::getUrl('view', ['record' => $record->scan_id]),
                                $record->repo_scan_id !== null => RepoScanResource::getUrl('view', ['record' => $record->repo_scan_id]),
                                default => null,
                            }),
                        TextEntry::make('created_at')
                            ->label('First seen')
                            ->since()
                            ->dateTimeTooltip(),
                        TextEntry::make('updated_at')
                            ->label('Last updated')
                            ->since()
                            ->dateTimeTooltip(),
                        TextEntry::make('cve')
                            ->label('CVE / advisory')
                            ->placeholder('—')
                            ->copyable()
                            ->url(fn (?string $state): ?string => $state && str_starts_with(strtoupper($state), 'CVE-')
                                ? 'https://nvd.nist.gov/vuln/detail/'.strtoupper($state)
                                : null, shouldOpenInNewTab: true)
                            ->visible(fn (Finding $record): bool => filled($record->cve)),
                        TextEntry::make('reachability')
                            ->badge()
                            ->placeholder('—')
                            ->helperText('Is the vulnerable code or package actually used by the application?')
                            ->visible(fn (Finding $record): bool => $record->reachability instanceof Reachability),
                        TextEntry::make('confidence')
                            ->suffix('%')
                            ->placeholder('—')
                            ->visible(fn (Finding $record): bool => $record->confidence !== null),
                        TextEntry::make('noise_filtered')
                            ->label('Noise filter')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Likely noise' : 'Kept as signal')
                            ->color(fn (bool $state): string => $state ? 'warning' : 'success')
                            ->visible(fn (Finding $record): bool => $record->isRepositoryFinding()),
                    ]),

                Section::make('Evidence')
                    ->icon(Heroicon::OutlinedCommandLine)
                    ->compact()
                    ->schema([
                        CodeEntry::make('evidence_text')
                            ->hiddenLabel()
                            ->state(fn (Finding $record): ?string => static::evidenceText($record))
                            ->grammar(Grammar::Txt)
                            ->copyable()
                            ->copyMessage('Evidence copied')
                            ->placeholder('No evidence captured.'),
                    ]),
            ]);
    }

    public static function evidenceText(Finding $record): ?string
    {
        $lines = $record->evidenceDetailLines();

        if ($lines !== []) {
            return implode("\n", $lines);
        }

        $evidence = $record->evidence;

        if ($evidence === null || $evidence === []) {
            return null;
        }

        return json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null;
    }
}
