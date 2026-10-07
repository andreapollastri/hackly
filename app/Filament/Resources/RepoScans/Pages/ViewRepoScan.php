<?php

namespace App\Filament\Resources\RepoScans\Pages;

use App\Filament\Actions\ScanRunActions;
use App\Filament\Resources\RepoScans\RepoScanResource;
use App\Filament\Resources\Scans\ScanResource;
use App\Models\RepoScan;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewRepoScan extends ViewRecord
{
    protected static string $resource = RepoScanResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var RepoScan $scan */
        $scan = $this->getRecord();

        return $scan->subjectName();
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var RepoScan $scan */
        $scan = $this->getRecord();

        return $scan->profile->getLabel().' repository scan · '.substr($scan->id, 0, 8);
    }

    /**
     * Repository scans live under the "Scans" section in navigation.
     */
    public function getBreadcrumbs(): array
    {
        return [
            ScanResource::getUrl('index', ['scope' => 'repositories::tab']) => 'Scans',
            'Scan '.substr((string) $this->getRecord()->getKey(), 0, 8),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ScanRunActions::cancel()->outlined(),
            ScanRunActions::rerun(),
            ScanRunActions::export(),
            ActionGroup::make([
                DeleteAction::make()->label('Delete scan'),
            ])->tooltip('More'),
        ];
    }
}
