<?php

namespace App\Filament\Resources\Findings\Pages;

use App\Filament\Resources\Findings\Actions\TriageActions;
use App\Filament\Resources\Findings\FindingResource;
use App\Models\Finding;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewFinding extends ViewRecord
{
    protected static string $resource = FindingResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Finding';
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var Finding $record */
        $record = $this->getRecord();

        return $record->subjectName();
    }

    protected function getHeaderActions(): array
    {
        return TriageActions::make();
    }
}
