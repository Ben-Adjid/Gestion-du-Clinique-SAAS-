<?php

namespace App\Exceptions;

use RuntimeException;

/** Login refused because the clinic's subscription no longer gives access. */
class SubscriptionBlockedException extends RuntimeException
{
    public function __construct(string $message = 'L’abonnement de votre clinique n’est plus actif. Votre direction doit régulariser le paiement.')
    {
        parent::__construct($message, 403);
    }
}
