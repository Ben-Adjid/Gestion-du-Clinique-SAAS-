<?php

namespace App\Exceptions;

use RuntimeException;

/** A patient account that has no record linked in this clinic: the portal answers with a clear French notice. */
class NoPatientRecordException extends RuntimeException
{
    public const MESSAGE = "Aucun dossier patient lié à ce compte. Contactez l'accueil.";

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
