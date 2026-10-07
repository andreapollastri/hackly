<?php

namespace App\Filament\Resources\RepoScans\Pages;

use App\Filament\Resources\RepoScans\RepoScanResource;
use App\Filament\Resources\Scans\ScanResource;
use Filament\Resources\Pages\ManageRecords;

class ManageRepoScans extends ManageRecords
{
    protected static string $resource = RepoScanResource::class;

    /**
     * Repository scans are listed in the "Repositories" tab of the Scans page.
     */
    public function mount(): void
    {
        $this->redirect(ScanResource::getUrl('index', ['scope' => 'repositories::tab']), navigate: true);
    }
}
