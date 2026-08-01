<?php

namespace App\Filament\Resources\Repositories\Pages;

use App\Filament\Resources\Repositories\RepositoryResource;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListRepositories extends ListRecords
{
    protected static string $resource = RepositoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add repository')
                ->modalWidth(Width::Large)
                ->mutateFormDataUsing(function (array $data): array {
                    try {
                        return RepositoryResource::hydrateFromGithub($data);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Cannot add repository')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        throw $e;
                    }
                }),
        ];
    }
}
