<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Illuminate\Support\Facades\App;
use Filament\View\PanelsRenderHook;  // ← AGREGAR ESTA LÍNEA
use Illuminate\Support\Facades\Blade;  // ← AGREGAR ESTA LÍNEA

class ChatsuitePanelProvider extends PanelProvider
{

        public function boot(): void
    {
        App::setLocale('es'); // Configura español globalmente
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('chatsuite')
            ->path('chatsuite')
            ->login()  // ← AGREGA ESTA LÍNEA
            ->colors([
            'primary' => '#005F99', // Color de DT GP
            'secondary' => '#4A658F',
            ])

            //Para cambiar el color de las letras del panel
             ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => Blade::render(<<<'HTML'
                    <style>
                        /* Widget de cuenta */
                        .fi-wi-account h2,
                        .fi-wi-account .text-xl {
                            color: #005F99 !important;
                        }
                        
                        .fi-wi-account p,
                        .fi-wi-account .text-sm {
                            color: #4A658F !important;
                        }
                        
                        /* Avatar */
                        .fi-avatar {
                            background-color: #005F99 !important;
                        }
                        
                        /* Títulos de página */
                        .fi-header-heading,
                        h1, h2 {
                            color: #005F99 !important;
                        }
                    </style>
                HTML)
            )

            ->brandName('ChatSuite')
             ->darkMode(false) 
            ->brandLogo(asset('images/logo-original.png')) // Logo en el panel
            ->brandLogoHeight('3rem')
            ->favicon(asset('images/dt.png'))
            ->discoverResources(in: app_path('Filament/Chatsuite/Resources'), for: 'App\\Filament\\Chatsuite\\Resources')
            ->discoverPages(in: app_path('Filament/Chatsuite/Pages'), for: 'App\\Filament\\Chatsuite\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Chatsuite/Widgets'), for: 'App\\Filament\\Chatsuite\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
             //   Widgets\FilamentInfoWidget::class, //Elimino la informacion de Widgets de Filament
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}