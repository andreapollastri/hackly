<?php

namespace App\Filament\Resources\GithubCredentials\Pages;

use App\Filament\Resources\GithubCredentials\GithubCredentialResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class ManageGithubCredentials extends ManageRecords
{
    protected static string $resource = GithubCredentialResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Read-only tokens used to clone repositories for scanning. They never leave this server.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add token')
                ->icon(Heroicon::OutlinedPlus)
                ->modalWidth(Width::Large)
                ->createAnother(false)
                ->using(fn (array $data): Model => GithubCredentialResource::createCredential($data)),
        ];
    }
}
