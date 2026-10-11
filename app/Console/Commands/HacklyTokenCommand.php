<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\ApiTokenAbilities;
use Illuminate\Console\Command;

class HacklyTokenCommand extends Command
{
    protected $signature = 'hackly:token
        {email : User who will own the token}
        {--name=cli : Token name}
        {--ability=full : full, read, or write}';

    protected $description = 'Issue a personal API token for a user (shown once)';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        $choice = match ((string) $this->option('ability')) {
            'full', '*' => '*',
            default => (string) $this->option('ability'),
        };

        try {
            $abilities = ApiTokenAbilities::expand((string) $choice);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $issued = $user->createToken((string) $this->option('name'), $abilities);

        $this->newLine();
        $this->line($issued->plainTextToken);
        $this->newLine();
        $this->comment('Copy it now. Hackly stores only a hash. Manage tokens from Profile.');

        return self::SUCCESS;
    }
}
