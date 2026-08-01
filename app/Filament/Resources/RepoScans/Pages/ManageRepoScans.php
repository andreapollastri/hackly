<?php

namespace App\Filament\Resources\RepoScans\Pages;

use App\Filament\Resources\RepoScans\RepoScanResource;
use Filament\Resources\Pages\ManageRecords;

class ManageRepoScans extends ManageRecords
{
    protected static string $resource = RepoScanResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
