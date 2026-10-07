<?php

namespace App\Filament\Resources\Scans\Pages;

use App\Filament\Actions\ScanRunActions;
use App\Filament\Resources\Scans\ScanResource;
use App\Models\Scan;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewScan extends ViewRecord
{
    protected static string $resource = ScanResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var Scan $scan */
        $scan = $this->getRecord();

        return $scan->subjectName();
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var Scan $scan */
        $scan = $this->getRecord();

        return $scan->profile->getLabel().' target scan · '.substr($scan->id, 0, 8);
    }

    public function getBreadcrumb(): string
    {
        return 'Scan '.substr((string) $this->getRecord()->getKey(), 0, 8);
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
