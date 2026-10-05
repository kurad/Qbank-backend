<?php

namespace Database\Seeders;

use App\Models\School;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersTableSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Find Gashora Girls School
        |--------------------------------------------------------------------------
        |
        | Avoid hard-coding school_id because IDs may be different between
        | local, staging and production databases.
        |
        */

        $school = School::query()
            ->where('school_name', 'like', '%Gashora Girls%')
            ->first();

        if (!$school) {
            $this->command->error(
                'Gashora Girls school was not found. Seed schools before users.'
            );

            return;
        }

        $password = Hash::make('Test@12345');

        /*
        |--------------------------------------------------------------------------
        | Admin
        |--------------------------------------------------------------------------
        */

        User::updateOrCreate(
            [
                'email' => 'admin@test.revisionhub.rw',
            ],
            [
                'name' => 'RevisionHub Admin',
                'password' => $password,
                'role' => 'admin',
                'school_id' => $school->id,
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Teachers
        |--------------------------------------------------------------------------
        */

        $teachers = [
            [
                'name' => 'Alain Mukama',
                'email' => 'a.teacher@test.revisionhub.rw',
            ],
            [
                'name' => 'Bob Rukundo',
                'email' => 'b.teacher@test.revisionhub.rw',
            ],
            [
                'name' => 'Claude Habimana',
                'email' => 'c.teacher@test.revisionhub.rw',
            ],
            [
                'name' => 'Dieudonne Rukundo',
                'email' => 'd.teacher@test.revisionhub.rw',
            ],
            [
                'name' => 'Emmy Karera',
                'email' => 'e.teacher@test.revisionhub.rw',
            ],
        ];

        foreach ($teachers as $teacher) {
            User::updateOrCreate(
                [
                    'email' => $teacher['email'],
                ],
                [
                    'name' => $teacher['name'],
                    'password' => $password,
                    'role' => 'teacher',
                    'school_id' => $school->id,
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]
            );
        }

        $students = [
            [
                'name' => 'Aline Uwimana',
                'email' => 'aline.student@test.revisionhub.rw',
            ],
            [
                'name' => 'Bella Ishimwe',
                'email' => 'bella.student@test.revisionhub.rw',
            ],
            [
                'name' => 'Chantal Uwamahoro',
                'email' => 'chantal.student@test.revisionhub.rw',
            ],
            [
                'name' => 'Divine Mugisha',
                'email' => 'divine.student@test.revisionhub.rw',
            ],
        ];

        foreach ($students as $student) {
            User::updateOrCreate(
                [
                    'email' => $student['email'],
                ],
                [
                    'name' => $student['name'],
                    'password' => $password,
                    'role' => 'student',
                    'school_id' => $school->id,
                    'status' => 'active',
                    'email_verified_at' => now(),
                ]
            );
        }

        $this->command->info(
            'Test users created successfully for ' . $school->name
        );

        $this->command->table(
            ['Role', 'Count'],
            [
                ['Admin', 1],
                ['Teachers', count($teachers)],
                ['Students', count($students)],
                ['Total', 1 + count($teachers) + count($students)],
            ]
        );

        $this->command->warn(
            'Test password for all seeded users: Test@12345'
        );
    }
}