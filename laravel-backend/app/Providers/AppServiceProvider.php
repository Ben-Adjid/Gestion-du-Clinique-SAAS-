<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
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
        // N+1 guard: a relation loaded lazily inside a loop fails the test suite, and is logged
        // in local development. Production is never interrupted.
        Model::preventLazyLoading(!$this->app->isProduction());
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) {
            $message = sprintf('Lazy loading of %s::%s (N+1 query).', $model::class, $relation);
            if ($this->app->runningUnitTests()) {
                throw new \RuntimeException($message);
            }
            Log::warning($message);
        });

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
