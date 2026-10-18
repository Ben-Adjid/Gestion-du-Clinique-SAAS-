<?php
namespace App\Models;
use App\Models\Concerns\BelongsToClinic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model {
    use BelongsToClinic;
    use SoftDeletes;

    public const CONTRACT_TYPES = ['CDI' => 'CDI', 'CDD' => 'CDD', 'STAGE' => 'Stage', 'PRESTATAIRE' => 'Prestataire'];
    public const STATUSES = ['ACTIVE' => 'En poste', 'ON_LEAVE' => 'En congé', 'SUSPENDED' => 'Suspendu', 'TERMINATED' => 'Sorti des effectifs'];

    protected $fillable = [
        'clinic_id', 'user_id', 'employee_number', 'first_name', 'last_name', 'position', 'department', 'contract_type',
        'contract_end_date', 'phone', 'national_id', 'address', 'cnps_number', 'payment_method', 'payment_account',
        'emergency_contact', 'annual_leave_days', 'base_salary', 'hire_date', 'end_date', 'status', 'metadata',
    ];

    protected $casts = [
        'base_salary' => 'decimal:2',
        'hire_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'contract_end_date' => 'date:Y-m-d',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function attendance(): HasMany { return $this->hasMany(Attendance::class); }
    public function leaves(): HasMany { return $this->hasMany(Leave::class); }
    public function payrolls(): HasMany { return $this->hasMany(Payroll::class); }

    public function fullName(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}
