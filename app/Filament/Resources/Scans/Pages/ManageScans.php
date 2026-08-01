<?php

namespace App\Filament\Resources\Scans\Pages;

use App\Filament\Resources\Scans\ScanResource;
use App\Filament\Resources\Scans\Widgets\RepoScansTableWidget;
use App\Models\RepoScan;
use App\Models\Scan;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

class ManageScans extends ManageRecords
{
    protected static string $resource = ScanResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTitle(): string|Htmlable
    {
        return 'Scans';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()
                ->contained(false)
                ->persistTabInQueryString('scope')
                ->tabs([
                    Tab::make('Targets')
                        ->badge(fn (): int => Scan::query()->count())
                        ->schema([
                            EmbeddedTable::make(),
                        ]),
                    Tab::make('Repositories')
                        ->badge(fn (): int => RepoScan::query()->count())
                        ->schema([
                            Livewire::make(RepoScansTableWidget::class),
                        ]),
                ]),
        ]);
    }
}
