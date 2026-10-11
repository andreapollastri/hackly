<?php

namespace App\Console\Commands;

use App\Domain\Digest\WeeklyDigest;
use App\Filament\Resources\Findings\FindingResource;
use App\Mail\WeeklyDigestMail;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

class HacklyDigestCommand extends Command
{
    protected $signature = 'hackly:digest {--dry-run : Print the digest without sending it}';

    protected $description = 'Email the weekly findings digest to every user';

    public function handle(WeeklyDigest $digest): int
    {
        $report = $digest->build();

        if ($this->option('dry-run')) {
            $this->line($report['text']);

            return self::SUCCESS;
        }

        $users = User::query()->get();

        if ($users->isEmpty()) {
            $this->warn('No users to notify.');

            return self::SUCCESS;
        }

        $open = $report['open'];
        $summary = sprintf(
            '%d high · %d medium · %d low open · %d new this week',
            $open['high'],
            $open['medium'],
            $open['low'],
            $report['new_count'],
        );

        foreach ($users as $user) {
            try {
                Mail::to($user)->send(new WeeklyDigestMail($report));
            } catch (Throwable $e) {
                Log::warning('hackly.digest.mail_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                $this->error('Mail failed for '.$user->email.': '.$e->getMessage());
            }

            if (! Schema::hasTable('notifications')) {
                continue;
            }

            Notification::make()
                ->title('Weekly digest')
                ->body($summary)
                ->icon('heroicon-o-calendar-days')
                ->actions([
                    Action::make('open')
                        ->label('Open findings')
                        ->button()
                        ->url(FindingResource::getUrl('index', panel: 'admin'))
                        ->markAsRead(),
                ])
                ->sendToDatabase($user);
        }

        $this->info('Digest sent to '.$users->count().' user(s).');

        return self::SUCCESS;
    }
}
