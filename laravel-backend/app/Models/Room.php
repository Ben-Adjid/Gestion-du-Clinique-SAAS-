<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A room of the hospital, belonging to a service, with a number of beds (capacity). */
class Room extends Model
{
    use BelongsToClinic;

    public const SERVICES = ['MEDECINE', 'CHIRURGIE', 'MATERNITE', 'URGENCES'];

    protected $fillable = ['clinic_id', 'name', 'service', 'capacity'];

    public function beds(): HasMany { return $this->hasMany(Bed::class); }
}
