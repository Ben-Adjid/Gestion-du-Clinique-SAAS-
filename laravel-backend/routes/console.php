<?php

use App\Services\SubscriptionService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('saas:billing {--date= : Date à simuler (AAAA-MM-JJ), par défaut aujourd’hui}', function (SubscriptionService $subscriptions) {
    $date = $this->option('date') ? Carbon::parse($this->option('date')) : null;
    $counters = $subscriptions->runBilling($date);
    $this->info("Renouvellements : {$counters['renewed']} · Fins d’abonnement : {$counters['ended']} · Factures en retard : {$counters['overdue']} · Suspensions : {$counters['suspended']}");
})->purpose('Renouvelle les abonnements, signale les retards et suspend les cliniques impayées');

Schedule::command('saas:billing')->dailyAt('01:00');

Artisan::command('billing:overdue', function (\App\Services\InvoiceReminders $reminders) {
    $counters = $reminders->run();
    $this->info("Factures passées en retard : {$counters['overdue']} · Relances planifiées : {$counters['reminders']}");
})->purpose('Passe en retard les factures patients impayées et prépare les relances');

Schedule::command('billing:overdue')->dailyAt('06:00');
