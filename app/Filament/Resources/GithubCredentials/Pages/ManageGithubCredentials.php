<?php

namespace App\Filament\Resources\GithubCredentials\Pages;

use App\Filament\Resources\GithubCredentials\GithubCredentialResource;
use App\Models\GithubCredential;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;

class ManageGithubCredentials extends ManageRecords
{
    protected static string $resource = GithubCredentialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add token')
                ->modalWidth(Width::Medium)
                ->mutateFormDataUsing(function (array $data): array {
                    $data['created_by'] = auth()->id();
                    $data['token_hint'] = GithubCredential::hintFromToken((string) ($data['token'] ?? ''));
                    $data['validation_status'] = 'unknown';

                    return $data;
                }),
        ];
    }
}
