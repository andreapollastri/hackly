<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\HacklyStatsOverview;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('/')
            ->login()
            ->profile()
            ->multiFactorAuthentication([
                AppAuthentication::make()
                    ->recoverable()
                    ->brandName('Hackly'),
                EmailAuthentication::make(),
            ])
            ->brandName('Hackly')
            ->topNavigation()
            ->colors([
                'primary' => Color::Emerald,
                'danger' => Color::Rose,
                'warning' => Color::Amber,
                'success' => Color::Teal,
                'info' => Color::Sky,
                'gray' => Color::Slate,
            ])
            ->font('IBM Plex Sans')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                HacklyStatsOverview::class,
            ])
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): HtmlString => new HtmlString(<<<'CSS'
                    <style>
                        .hackly-task-accordion {
                            margin-block: 0.5rem;
                        }

                        .hackly-task-accordion > .fi-section {
                            cursor: pointer;
                            transition: box-shadow 150ms ease, background-color 150ms ease;
                        }

                        .hackly-task-accordion--success > .fi-section {
                            background-color: color-mix(in oklab, var(--success-50) 80%, white);
                            box-shadow:
                                var(--tw-ring-inset,) 0 0 0 1px color-mix(in oklab, var(--success-500) 45%, transparent),
                                var(--tw-shadow, 0 1px 2px 0 rgb(0 0 0 / 0.05));
                        }

                        .hackly-task-accordion--danger > .fi-section {
                            background-color: color-mix(in oklab, var(--danger-50) 80%, white);
                            box-shadow:
                                var(--tw-ring-inset,) 0 0 0 1px color-mix(in oklab, var(--danger-500) 45%, transparent),
                                var(--tw-shadow, 0 1px 2px 0 rgb(0 0 0 / 0.05));
                        }

                        .dark .hackly-task-accordion--success > .fi-section {
                            background-color: color-mix(in oklab, var(--success-950) 55%, var(--gray-900));
                            box-shadow:
                                var(--tw-ring-inset,) 0 0 0 1px color-mix(in oklab, var(--success-400) 35%, transparent),
                                var(--tw-shadow, 0 1px 2px 0 rgb(0 0 0 / 0.05));
                        }

                        .dark .hackly-task-accordion--danger > .fi-section {
                            background-color: color-mix(in oklab, var(--danger-950) 55%, var(--gray-900));
                            box-shadow:
                                var(--tw-ring-inset,) 0 0 0 1px color-mix(in oklab, var(--danger-400) 35%, transparent),
                                var(--tw-shadow, 0 1px 2px 0 rgb(0 0 0 / 0.05));
                        }
                    </style>
                    CSS),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
