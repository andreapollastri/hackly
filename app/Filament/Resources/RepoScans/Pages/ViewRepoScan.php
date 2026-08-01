<?php

namespace App\Filament\Resources\RepoScans\Pages;

use App\Filament\Resources\RepoScans\RepoScanResource;
use App\Models\RepoScan;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewRepoScan extends ViewRecord
{
    protected static string $resource = RepoScanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportPdf')
                ->label('PDF')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('gray')
                ->action(function (): StreamedResponse {
                    /** @var RepoScan $scan */
                    $scan = $this->getRecord();

                    return RepoScanResource::downloadReport($scan);
                }),
            Action::make('exportMarkdown')
                ->label('MD')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                ->action(function (): StreamedResponse {
                    /** @var RepoScan $scan */
                    $scan = $this->getRecord();

                    return RepoScanResource::downloadMarkdownReport($scan);
                }),
            DeleteAction::make(),
        ];
    }
}
