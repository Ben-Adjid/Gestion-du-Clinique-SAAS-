<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A medical specialty of the platform catalogue; it tells the hospital service and the record template. */
class Specialty extends Model
{
    public const RECORD_KINDS = ['GENERIC', 'DENTAL', 'OPHTALMO', 'ORL', 'MATERNITE'];

    protected $fillable = ['code', 'label', 'service', 'record_kind'];

    public function doctors(): HasMany { return $this->hasMany(DoctorProfile::class); }
}
