<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Assessment;
use App\Models\AssessmentQuestion;
use App\Models\User;
use App\Models\Subject;
use App\Models\Topic;
use App\Models\Question;

class AssessmentSeeder extends Seeder
{
    public function run()
    {
        $teacher = User::where('role', 'teacher')->inRandomOrder()->first();
        $subject = Subject::inRandomOrder()->first();
        if (!$teacher || !$subject) {
            return; // No teacher or subject found, skip seeding
        }
        $topic = Topic::where('subject_id', $subject->id)->inRandomOrder()->first();
        if (!$topic) {
            return; // No topic for this subject, skip seeding
        }
        $questions = Question::where('topic_id', $topic->id)->get();
        if ($questions->isEmpty()) {
            return; // No questions for this topic, skip seeding
        }

        // Create a quiz assessment (as before)
        $quizAssessment = Assessment::create([
            'type' => 'quiz',
            'title' => 'Sample Quiz Assessment',
            'creator_id' => $teacher->id,
            'subject_id' => $subject->id,
            'topic_id' => $topic->id,
            'question_count' => min(5, $questions->count()),
            'start_time' => now(),
            'end_time' => now()->addDays(1),
            'is_timed' => true,
            'time_limit' => 30,
        ]);
        foreach ($questions->take(5) as $order => $question) {
            AssessmentQuestion::create([
                'assessment_id' => $quizAssessment->id,
                'question_id' => $question->id,
                'order' => $order + 1,
            ]);
        }

        // Create a practice assessment for a student with all questions from the topic
        $student = User::where('role', 'student')->inRandomOrder()->first();
        if ($student) {
            $practiceAssessment = Assessment::create([
                'type' => 'practice',
                'title' => 'Practice All Questions for Topic',
                'creator_id' => $teacher->id,
                'subject_id' => $subject->id,
                'topic_id' => $topic->id,
                'question_count' => $questions->count(),
                'start_time' => now(),
                'end_time' => now()->addDays(1),
                'is_timed' => false,
                'time_limit' => null,
            ]);
            foreach ($questions as $order => $question) {
                AssessmentQuestion::create([
                    'assessment_id' => $practiceAssessment->id,
                    'question_id' => $question->id,
                    'order' => $order + 1,
                ]);
            }
        }
    }
}
