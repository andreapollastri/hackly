<?php

namespace App\Filament\Resources\Scans\Pages;

use App\Filament\Actions\StartRepositoryScanAction;
use App\Filament\Actions\StartTargetScanAction;
use App\Filament\Resources\Scans\ScanResource;
use App\Filament\Resources\Scans\Widgets\RepoScansTableWidget;
use App\Models\RepoScan;
use App\Models\Scan;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ManageScans extends ManageRecords
{
    protected static string $resource = ScanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                StartTargetScanAction::make('scanTarget', pickTarget: true)
                    ->icon(Heroicon::OutlinedGlobeAlt),
                StartRepositoryScanAction::make('scanRepository', pickRepository: true)
                    ->icon(Heroicon::OutlinedCodeBracketSquare),
            ])
                ->label('New scan')
                ->icon(Heroicon::OutlinedPlay)
                ->color('primary')
                ->button(),
        ];
    }

    public function getTitle(): string|Htmlable
    {
        return 'Scans';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Live progress of attack-surface (DAST) and repository (SAST / SCA) scans.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()
                ->contained(false)
                ->persistTabInQueryString('scope')
                ->tabs([
                    Tab::make('Targets')
                        ->icon(Heroicon::OutlinedGlobeAlt)
                        ->badge(fn (): int => Scan::query()->count())
                        ->badgeColor('gray')
                        ->schema([
                            EmbeddedTable::make(),
                        ]),
                    Tab::make('Repositories')
                        ->icon(Heroicon::OutlinedCodeBracketSquare)
                        ->badge(fn (): int => RepoScan::query()->count())
                        ->badgeColor('gray')
                        ->schema([
                            Livewire::make(RepoScansTableWidget::class),
                        ]),
                ]),
        ]);
    }
}
