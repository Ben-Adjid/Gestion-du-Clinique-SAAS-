<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes. Every foreign key gets an index (MySQL creates one implicitly for
 * its constraints, SQLite does not), plus composite indexes matching the busiest queries:
 * agenda, waiting room, worklists, billing and cash register. An index is only created when
 * no existing index already starts with the same columns, so the migration is idempotent on
 * both engines.
 */
return new class extends Migration
{
    private const SUFFIX = '_perf_idx';

    /** @var array<string, list<list<string>>> */
    private const COMPOSITES = [
        'appointments' => [['clinic_id', 'appointment_datetime'], ['doctor_id', 'appointment_datetime'], ['patient_id', 'status']],
        'consultations' => [['clinic_id', 'consultation_datetime'], ['doctor_id', 'status'], ['patient_id', 'status']],
        'prescriptions' => [['clinic_id', 'status', 'prescribed_at']],
        'lab_requests' => [['clinic_id', 'status', 'requested_at']],
        'imaging_requests' => [['clinic_id', 'status', 'requested_at']],
        'invoices' => [['clinic_id', 'created_at']],
        'invoice_items' => [['reference_id']],
        'payments' => [['invoice_id', 'status'], ['received_by', 'payment_datetime']],
        'pharmacy_lots' => [['inventory_id', 'expiration_date']],
        'pharmacy_sales' => [['patient_id', 'sold_at']],
        'users' => [['clinic_id', 'status']],
        'employees' => [['clinic_id', 'status']],
        'leaves' => [['clinic_id', 'status'], ['employee_id', 'start_date']],
        'payroll' => [['clinic_id', 'year', 'month']],
        'user_notifications' => [['user_id', 'read_at']],
        'vital_signs' => [['patient_id', 'recorded_at']],
        'saas_payments' => [['saas_invoice_id', 'status']],
    ];

    public function up(): void
    {
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if (str_starts_with($name, 'sqlite_')) {
                continue;
            }
            $wanted = array_map(fn (array $foreignKey) => [$foreignKey['columns'][0]], Schema::getForeignKeys($name));
            foreach (self::COMPOSITES[$name] ?? [] as $columns) {
                $wanted[] = $columns;
            }
            foreach ($wanted as $columns) {
                if (!$this->columnsExist($name, $columns) || $this->covered($name, $columns)) {
                    continue;
                }
                Schema::table($name, fn (Blueprint $blueprint) => $blueprint->index($columns, $this->indexName($name, $columns)));
            }
        }
    }

    public function down(): void
    {
        foreach (Schema::getTables() as $table) {
            foreach (Schema::getIndexes($table['name']) as $index) {
                if (str_ends_with($index['name'], self::SUFFIX)) {
                    Schema::table($table['name'], fn (Blueprint $blueprint) => $blueprint->dropIndex($index['name']));
                }
            }
        }
    }

    /** True when an existing index already starts with these columns, in this order. */
    private function covered(string $table, array $columns): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (array_slice($index['columns'], 0, count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }

    private function columnsExist(string $table, array $columns): bool
    {
        return array_diff($columns, Schema::getColumnListing($table)) === [];
    }

    /** MySQL limits index names to 64 characters. */
    private function indexName(string $table, array $columns): string
    {
        $name = $table.'_'.implode('_', $columns).self::SUFFIX;

        return strlen($name) <= 64 ? $name : substr($table, 0, 30).'_'.substr(md5(implode(',', $columns)), 0, 12).self::SUFFIX;
    }
};
