<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\School;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersTableSeeder extends Seeder
{
    public function run()
    {
       

        // Create an admin user
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'school_id' => 1, // Admin is not associated with a specific school
        ]);

        // Create teachers
        User::create([
            'name' => 'Teacher Smith',
            'email' => 'smith@example.com',
            'password' => Hash::make('password'),
            'role' => 'teacher',
            'school_id' => 1,
        ]);

        User::create([
            'name' => 'Teacher Johnson',
            'email' => 'johnson@example.com',
            'password' => Hash::make('password'),
            'role' => 'teacher',
            'school_id' => 2,
        ]);

        // Create students
        $students = [
            ['name' => 'Student Doe', 'email' => 'doe@example.com', 'school_id' => 5],
            ['name' => 'Student Roe', 'email' => 'roe@example.com', 'school_id' => 5],
            ['name' => 'Student Poe', 'email' => 'poe@example.com', 'school_id' => 2],
            ['name' => 'Student Moe', 'email' => 'moe@example.com', 'school_id' => 2],
        ];

        foreach ($students as $student) {
            User::create([
                'name' => $student['name'],
                'email' => $student['email'],
                'password' => Hash::make('password'),
                'role' => 'student',
                'school_id' => $student['school_id'],
            ]);
        }
    }
}