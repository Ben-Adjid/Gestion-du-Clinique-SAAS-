<?php

return [
    'appointment_slot_minutes' => (int) env('APPOINTMENT_SLOT_MINUTES', 30),

    // SaaS billing: days to pay an invoice, then days of tolerance before the clinic is suspended.
    'saas_payment_terms_days' => (int) env('SAAS_PAYMENT_TERMS_DAYS', 7),
    'saas_suspend_after_days' => (int) env('SAAS_SUSPEND_AFTER_DAYS', 7),
    // true: block the clinic as soon as a subscription invoice is overdue (no grace period).
    'block_past_due' => (bool) env('CLINIC_BLOCK_PAST_DUE', false),

    // Where clinics send their subscription payments (shown on the clinic admin subscription page).
    'saas_payment_instructions' => [
        'ORANGE_MONEY' => env('SAAS_ORANGE_MONEY_NUMBER', '+235 66 00 00 00'),
        'MOOV_MONEY' => env('SAAS_MOOV_MONEY_NUMBER', '+235 99 00 00 00'),
        'AIRTEL_MONEY' => env('SAAS_AIRTEL_MONEY_NUMBER', '+235 60 00 00 00'),
        'BANK_TRANSFER' => env('SAAS_BANK_ACCOUNT', 'IBAN à configurer'),
    ],

    'totp_required_roles' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TOTP_REQUIRED_ROLES', 'SUPER_ADMIN,ADMIN_CLINIC,DOCTOR,CASHIER')),
    ))),
];
