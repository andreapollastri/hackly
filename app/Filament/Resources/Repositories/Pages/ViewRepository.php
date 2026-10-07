<?php

namespace App\Filament\Resources\Repositories\Pages;

use App\Filament\Actions\StartRepositoryScanAction;
use App\Filament\Resources\Repositories\RepositoryResource;
use App\Models\Repository;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewRepository extends ViewRecord
{
    protected static string $resource = RepositoryResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->full_name;
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var Repository $repository */
        $repository = $this->getRecord();

        return collect([
            'Repository',
            $repository->is_private ? 'private' : 'public',
            $repository->language(),
            $repository->default_branch,
        ])->filter()->implode(' · ');
    }

    protected function getHeaderActions(): array
    {
        return [
            StartRepositoryScanAction::make(),
            RepositoryResource::openOnGithubAction()
                ->color('gray')
                ->button(),
            ActionGroup::make([
                EditAction::make(),
                DeleteAction::make(),
            ])->tooltip('More'),
        ];
    }

    public function getContentTabLabel(): ?string
    {
        return 'Overview';
    }
}
