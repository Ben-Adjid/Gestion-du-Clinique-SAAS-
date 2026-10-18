<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Clinic extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'city', 'country', 'code', 'subscription_plan',
        'admin_email', 'admin_phone', 'settings', 'status',
    ];

    protected $casts = ['settings' => 'json'];

    public function users() { return $this->hasMany(User::class); }
    public function patients() { return $this->hasMany(Patient::class); }
    public function consultations() { return $this->hasMany(Consultation::class); }
    public function invoices() { return $this->hasMany(Invoice::class); }
    public function subscription() { return $this->hasOne(ClinicSubscription::class); }
    public function saasInvoices() { return $this->hasMany(SaasInvoice::class); }
    public function subscriptionEvents() { return $this->hasMany(SubscriptionEvent::class); }
}
