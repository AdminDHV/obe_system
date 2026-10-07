<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MappingScaleWeightSeeder extends Seeder
{
    public function run(): void
    {
        // Mức đóng góp I/R/M/A theo công thức đo chuẩn đầu ra của khách (sửa được sau).
        $weights = [
            ['level' => 'I', 'weight_percent' => 1.00],
            ['level' => 'R', 'weight_percent' => 2.00],
            ['level' => 'M', 'weight_percent' => 3.00],
            ['level' => 'A', 'weight_percent' => 3.00],
        ];

        foreach ($weights as $w) {
            DB::table('mapping_scale_weights')->updateOrInsert(
                ['level' => $w['level']],
                ['weight_percent' => $w['weight_percent'], 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }
}
