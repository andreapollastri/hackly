<?php

namespace App\Filament\Resources\Assets\RelationManagers;

use App\Enums\FindingSeverity;
use App\Enums\ScanProfile;
use App\Enums\ScanStatus;
use App\Filament\Resources\Scans\ScanResource;
use App\Models\Scan;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScansRelationManager extends RelationManager
{
    protected static string $relationship = 'scans';

    protected static ?string $title = 'Scans';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('3s')
            ->modifyQueryUsing(fn ($query) => $query
                ->with(['tasks'])
                ->withCount([
                    'findings as high_findings_count' => fn ($q) => $q->where('severity', FindingSeverity::High),
                    'findings as medium_findings_count' => fn ($q) => $q->where('severity', FindingSeverity::Medium),
                    'findings as low_findings_count' => fn ($q) => $q->where('severity', FindingSeverity::Low),
                ]))
            ->recordUrl(fn (Scan $record): string => ScanResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('id')
                    ->label('UUID')
                    ->copyable()
                    ->limit(8)
                    ->tooltip(fn (Scan $record) => $record->id)
                    ->searchable(),
                TextColumn::make('profile')
                    ->badge()
                    ->color('info'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (ScanStatus $state): string => match ($state) {
                        ScanStatus::Completed => 'success',
                        ScanStatus::Running => 'warning',
                        ScanStatus::Failed => 'danger',
                        ScanStatus::Cancelled => 'gray',
                        default => 'gray',
                    }),
                ViewColumn::make('progress')
                    ->label('Progress')
                    ->view('filament.tables.columns.scan-progress'),
                ViewColumn::make('findings_summary')
                    ->label('Findings')
                    ->view('filament.tables.columns.scan-findings-summary')
                    ->state(fn (Scan $record) => $record->findingsSeveritySummary()),
                TextColumn::make('created_at')->since()->sortable()->label('Started'),
            ])
            ->filters([
                SelectFilter::make('profile')->options(collect(ScanProfile::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value])),
                SelectFilter::make('status')->options(collect(ScanStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->value])),
            ])
            ->headerActions([])
            ->recordActions([
                Action::make('exportPdf')
                    ->label('PDF')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->color('gray')
                    ->action(fn (Scan $record): StreamedResponse => ScanResource::downloadReport($record)),
                Action::make('exportMarkdown')
                    ->label('MD')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->color('gray')
                    ->action(fn (Scan $record): StreamedResponse => ScanResource::downloadMarkdownReport($record)),
                ViewAction::make()
                    ->url(fn (Scan $record): string => ScanResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
