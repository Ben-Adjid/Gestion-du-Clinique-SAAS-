<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim((string) config('app.frontend_url'), '/')
            .'/reset-password?token='.urlencode($token).'&email='.urlencode($user->getEmailForPasswordReset()));

        ResetPassword::toMailUsing(fn ($user, string $token) => (new MailMessage)
            ->subject('Réinitialisation de votre mot de passe Clinique+')
            ->line('Vous avez demandé la réinitialisation du mot de passe de votre compte.')
            ->action('Choisir un nouveau mot de passe', call_user_func(ResetPassword::$createUrlCallback, $user, $token))
            ->line('Ce lien expire dans 24 heures.')
            ->line('Si vous n’êtes pas à l’origine de cette demande, ignorez ce message.'));
    }
}
