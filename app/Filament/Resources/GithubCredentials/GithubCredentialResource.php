<?php

namespace App\Filament\Resources\GithubCredentials;

use App\Domain\RepoScanning\Services\GithubClient;
use App\Filament\Resources\GithubCredentials\Pages\ManageGithubCredentials;
use App\Models\GithubCredential;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GithubCredentialResource extends Resource
{
    protected static ?string $model = GithubCredential::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'GitHub tokens';

    protected static ?string $modelLabel = 'GitHub token';

    protected static ?string $pluralModelLabel = 'GitHub tokens';

    protected static ?string $slug = 'github-tokens';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')
                    ->label('Label')
                    ->required()
                    ->maxLength(120)
                    ->helperText('e.g. Personal scanning PAT'),
                TextInput::make('token')
                    ->label('GitHub token')
                    ->password()
                    ->revealable()
                    ->required(fn (?GithubCredential $record): bool => $record === null)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText('Classic PAT: repo (private) or public_repo. Fine-grained: Contents Read + Metadata Read on selected repos.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('token_hint')->label('Token')->placeholder('—'),
                TextColumn::make('validation_status')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'valid' => 'success',
                        'invalid' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('last_validated_at')->since()->label('Validated')->placeholder('Never'),
                TextColumn::make('repositories_count')->counts('repositories')->label('Repos'),
                TextColumn::make('updated_at')->since()->label('Updated'),
            ])
            ->recordActions([
                Action::make('validate')
                    ->label('Validate')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->visible(fn (GithubCredential $record): bool => $record->validation_status !== 'valid')
                    ->action(function (GithubCredential $record) {
                        try {
                            $info = app(GithubClient::class)->validateToken($record);

                            Notification::make()
                                ->title('Token valid')
                                ->body('Authenticated as '.$info['login'].(empty($info['scopes']) ? '' : ' · scopes: '.implode(', ', $info['scopes'])))
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            $record->update([
                                'validation_status' => 'invalid',
                                'last_validated_at' => now(),
                            ]);

                            Notification::make()
                                ->title('Token invalid')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                EditAction::make()
                    ->mutateFormDataUsing(function (array $data, GithubCredential $record): array {
                        if (! empty($data['token'])) {
                            $data['token_hint'] = GithubCredential::hintFromToken($data['token']);
                            $data['validation_status'] = 'unknown';
                        }

                        return $data;
                    }),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageGithubCredentials::route('/'),
        ];
    }
}
