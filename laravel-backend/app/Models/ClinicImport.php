<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One takeover of a clinic's data from an archive: checked first, then written when the administrator confirms. */
class ClinicImport extends Model
{
    public const PENDING = 'PENDING';
    public const CHECKED = 'CHECKED';
    public const IMPORTING = 'IMPORTING';
    public const DONE = 'DONE';
    public const FAILED = 'FAILED';

    protected $fillable = ['clinic_id', 'user_id', 'status', 'file_path', 'original_name', 'report', 'error', 'finished_at'];

    protected function casts(): array
    {
        return ['report' => 'array', 'finished_at' => 'datetime'];
    }
}
