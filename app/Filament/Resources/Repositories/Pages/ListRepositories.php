<?php

namespace App\Filament\Resources\Repositories\Pages;

use App\Filament\Resources\Repositories\RepositoryResource;
use App\Models\Repository;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ListRepositories extends ListRecords
{
    protected static string $resource = RepositoryResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'GitHub repositories scanned for vulnerable code, dependencies, secrets and misconfigurations.';
    }

    protected function getHeaderActions(): array
    {
        return [
            static::createAction(),
        ];
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->label('Add repository')
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading('Add a GitHub repository')
            ->modalWidth(Width::Large)
            ->createAnother(false)
            ->mutateDataUsing(function (array $data, CreateAction $action): array {
                try {
                    return RepositoryResource::hydrateFromGithub($data);
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Cannot add repository')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    $action->halt();

                    return $data;
                }
            })
            ->successRedirectUrl(fn (Repository $record): string => RepositoryResource::getUrl('view', ['record' => $record]));
    }
}
