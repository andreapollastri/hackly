<?php

namespace App\Filament\Resources\Scans\Pages;

use App\Filament\Resources\Scans\ScanResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;

class ViewScan extends ViewRecord
{
    protected static string $resource = ScanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
