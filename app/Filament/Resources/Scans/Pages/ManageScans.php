<?php

namespace App\Filament\Resources\Scans\Pages;

use App\Filament\Resources\Scans\ScanResource;
use Filament\Resources\Pages\ManageRecords;

class ManageScans extends ManageRecords
{
    protected static string $resource = ScanResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
