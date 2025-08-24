<?php

namespace Database\Seeders;

use App\Models\GradeLevel;
use Illuminate\Database\Seeder;
use App\Models\School;

class GradesSeeder extends Seeder
{
    public function run()
    {
        $grades = [
            [
                'grade_name' => 'S4',
                
            ],
            [
                'grade_name' => 'S5',
                
            ],
            [
                'grade_name' => 'S6',
                
            ],
            
        ];

        foreach ($grades as $data) {
            GradeLevel::create($data);
        }
    }
}
