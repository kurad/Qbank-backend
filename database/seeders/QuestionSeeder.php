<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Question;
use App\Models\Topic;
use App\Models\User;

class QuestionSeeder extends Seeder
{
    public function run()
    {
        $topics = Topic::all();
        $users = User::all();
        $difficultyLevels = ['easy', 'medium', 'hard'];
        $sampleOptions = [
            ['A', 'B', 'C', 'D'],
            ['True', 'False'],
            ['Option 1', 'Option 2', 'Option 3', 'Option 4'],
        ];

        for ($i = 1; $i <= 50; $i++) {
            $topic = $topics->random();
            $user = $users->random();
            $options = $sampleOptions[array_rand($sampleOptions)];
            $multipleAnswers = rand(0, 1) === 1;
            $correctAnswer = $multipleAnswers ? array_slice($options, 0, rand(1, 2)) : [$options[array_rand($options)]];

            Question::create([
                'topic_id' => $topic->id,
                'question' => "Sample question $i for topic {$topic->name}",
                'options' => json_encode($options),
                'correct_answer' => json_encode($correctAnswer),
                'marks' => rand(1, 5),
                'difficulty_level' => $difficultyLevels[array_rand($difficultyLevels)],
                'is_math' => rand(0, 1),
                'multiple_answers' => $multipleAnswers,
                'is_required' => rand(0, 1),
                'explanation' => "Explanation for question $i.",
                'question_image' => null,
                'created_by' => $user->id,
            ]);
        }
    }
}
