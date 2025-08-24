<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\School;

class SchoolSeeder extends Seeder
{
    public function run()
    {
        $schools = [
            [
                'school_name' => 'Green Valley High School',
                'district' => 'North District',
                'email' => 'contact@greenvalley.edu',
            ],
            [
                'school_name' => 'Sunrise Academy',
                'district' => 'East District',
                'email' => 'info@sunriseacademy.edu',
            ],
            [
                'school_name' => 'Riverdale College',
                'district' => 'West District',
                'email' => 'admin@riverdale.edu',
            ],
            [
                'school_name' => 'Mountainview School',
                'district' => 'South District',
                'email' => 'office@mountainview.edu',
            ],
            [
                'school_name' => 'Lakeside Institute',
                'district' => 'Central District',
                'email' => 'hello@lakeside.edu',
            ],
        ];

        foreach ($schools as $data) {
            School::create($data);
        }
    }
}
