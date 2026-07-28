<?php

namespace App\Filament\Resources\Scans\Pages;

use App\Filament\Resources\Scans\ScanResource;
use App\Models\Scan;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewScan extends ViewRecord
{
    protected static string $resource = ScanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportPdf')
                ->label('PDF')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('gray')
                ->action(function (): StreamedResponse {
                    /** @var Scan $scan */
                    $scan = $this->getRecord();

                    return ScanResource::downloadReport($scan);
                }),
            Action::make('exportMarkdown')
                ->label('MD')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                ->action(function (): StreamedResponse {
                    /** @var Scan $scan */
                    $scan = $this->getRecord();

                    return ScanResource::downloadMarkdownReport($scan);
                }),
            DeleteAction::make(),
        ];
    }
}
