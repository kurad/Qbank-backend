<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\School;

class SchoolSeeder extends Seeder
{
    public function run(): void
    {
        School::updateOrCreate(
            [
                'email' => 'info@ggast.org',
            ],
            [
                'school_name' => 'Gashora Girls Academy of Science and Technology',
                'district' => 'Bugesera',
                'email' => 'info@ggast.org',
            ]
        );

        $this->command->info(
            'Gashora Girls Academy of Science and Technology created successfully.'
        );
    }
}