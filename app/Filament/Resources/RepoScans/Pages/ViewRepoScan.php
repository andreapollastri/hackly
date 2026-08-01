<?php

namespace App\Filament\Resources\RepoScans\Pages;

use App\Filament\Resources\RepoScans\RepoScanResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRepoScan extends ViewRecord
{
    protected static string $resource = RepoScanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
