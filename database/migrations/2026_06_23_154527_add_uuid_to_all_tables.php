<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        "users", "departments", "programs", "cohorts", "courses",
        "course_syllabus", "students", "course_classes",
        "plos", "pis", "clos", "clo_pi_mappings", "mapping_scale_weights",
        "assessments", "assessment_clo_mappings", "rubrics", "rubric_criteria",
        "student_grades", "student_rubric_scores", "student_clo_results",
        "student_pi_results", "student_plo_results",
        "standard_frameworks", "system_settings", "audit_logs",
        "file_batches", "report_snapshots", "framework_plo_mappings",
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (Schema::hasColumn($tableName, "uuid")) {
                continue;
            }
            if (DB::connection()->getDriverName() === 'sqlite') {
                Schema::table($tableName, function ($table) {
                    $table->uuid('uuid')->nullable()->unique();
                });
            } else {
                DB::statement("ALTER TABLE \"{$tableName}\" ADD COLUMN \"uuid\" UUID DEFAULT gen_random_uuid()");
                DB::statement("UPDATE \"{$tableName}\" SET \"uuid\" = gen_random_uuid() WHERE \"uuid\" IS NULL");
                DB::statement("ALTER TABLE \"{$tableName}\" ALTER COLUMN \"uuid\" SET NOT NULL");
                DB::statement("CREATE UNIQUE INDEX \"{$tableName}_uuid_unique\" ON \"{$tableName}\" (\"uuid\")");
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (Schema::hasColumn($tableName, "uuid")) {
                Schema::table($tableName, function ($table) {
                    $table->dropColumn("uuid");
                });
            }
        }
    }
};
