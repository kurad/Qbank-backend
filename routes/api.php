<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\TopicController;
use App\Http\Controllers\SchoolController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\GradeLevelController;
use App\Http\Controllers\StudentAnswerController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/



// Public route for school creation
// Public routes for school creation and update
Route::post('/schools', [SchoolController::class, 'store']);
Route::match(['put', 'patch'], '/schools/{id}', [SchoolController::class, 'update']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::get('/students', [AuthController::class, 'getStudents']);
Route::post('/logout', [AuthController::class, 'logout']);

Route::get('/subjects', [SubjectController::class, 'index']);
Route::get('/subjects/details', [SubjectController::class, 'subjectsByGrade']);
Route::get('/subjects/{id}/topics', [TopicController::class, 'topicsBySubject']);
Route::get('/grade-subjects/{gradeId}/{subjectId}/topics', [TopicController::class, 'topicsByGradeAndSubject']);
Route::get('/subjects/{subjectId}/grades/{gradeId}/topics', [TopicController::class, 'topicsBySubjectAndGrade']);
Route::get('/subjects/{subjectId}/grades/{gradeId}/units', [TopicController::class, 'topicsBySubjectGrade']); // For Cascading selection
Route::delete('/topics/{id}', [TopicController::class, 'destroy']);
Route::get('/topics/{topic}/questions', [QuestionController::class, 'byTopic']);
// Route to get grade levels
Route::get('/grade-levels', [GradeLevelController::class, 'index']);
Route::get('/grade-levels/{gradeId}/subjects', [SubjectController::class, 'getSubjectsByGrade']);
Route::put('/questions/{id}', [QuestionController::class, 'update']);
// Route::get('/questions/{topic}', [QuestionController::class, 'byTopic']);

   Route::get('/auth/google', [AuthController::class, 'redirectToGoogle']);
    Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
    

Route::post('/grade-subjects', [TopicController::class, 'createOrGet']);

Route::get('/subjects/{subject}/grades', [SubjectController::class, 'gradesForSubject']);
Route::post('/subjects', [SubjectController::class, 'createSubject']);
Route::put('/subjects/{id}', [SubjectController::class, 'update']);
Route::get('/topics', [TopicController::class, 'index']);
// Routes for teachers to create subjects, topics, and questions
Route::middleware('auth:sanctum')->group(function () {
    // Route::post('/subjects', [SubjectController::class, 'store']);
    Route::post('/topics', [TopicController::class, 'store']);
    Route::put('/topics/{topic}', [TopicController::class, 'update']);
    Route::post('/questions', [QuestionController::class, 'store']);
    Route::get('/my-questions', [QuestionController::class, 'myQuestions']);
    Route::delete('/questions/{id}', [QuestionController::class, 'destroy']);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Assessment endpoints
Route::middleware('auth:sanctum')->group(function () {
    // 1. Create assessment
    Route::post('/assessments', [AssessmentController::class, 'createAssessment']);
    // 2. Add questions to assessment
    Route::post('/assessments/{assessment}/questions', [AssessmentController::class, 'addQuestions']);
    // 3. Get questions for assessment
    Route::get('/assessments/{assessment}/questions', [AssessmentController::class, 'getAssessmentQuestions']);
    // 4. Remove a question from an assessment
    Route::delete('/assessments/{assessment}/questions/{question}', [AssessmentController::class, 'removeQuestion']);
    // 5. Assign assessment to student or grade level
    Route::post('/assessments/{assessment}/assign', [AssessmentController::class, 'assign']);
    // 6. Reorder questions in an assessment
    Route::put('/assessments/{id}/reorder', [AssessmentController::class, 'reorderQuestions']);
    Route::get('/assessments/{id}/pdf', [AssessmentController::class, 'generatePdf']);
    Route::get('/assessments/{id}/pdf/student', [AssessmentController::class, 'generatePdfStudent']);
    
    Route::post('/assessments/assign', [AssessmentController::class, 'assign']);
    Route::post('/assessments/start-practice', [AssessmentController::class, 'startPractice']);
    Route::post('/assessments/practice-for-topic', [AssessmentController::class, 'createPracticeForTopic']);
    Route::get('/student/practice-assessments', [AssessmentController::class, 'practice']);
    // List all assessments created by the authenticated user
    Route::get('/assessments/created', [AssessmentController::class, 'listCreatedAssessments']);
    Route::get('/assessments/{id}/details', [AssessmentController::class, 'show']);
    // Route::post('/assessments/submit-answers', [AssessmentController::class, 'submitAnswers']);
    Route::post('/assessments/submit-answers', [StudentAnswerController::class, 'storeStudentAnswers']);
    Route::get('/student/assessment-results/{id}', [AssessmentController::class, 'showResults']);
    Route::get('/student/assigned-assessments', [AssessmentController::class, 'assignedAssessments']);
    Route::get('/student/statistics', [HomeController::class, 'statistics']);

    Route::post('/refresh-token', [AuthController::class, 'refreshToken']);

    

});
Route::get('/topics/{id}/question-count', [QuestionController::class, 'getQuestionCount']);
Route::get('/questions/{id}', [QuestionController::class, 'show']);


Route::get('/subjects/overview', [HomeController::class, 'subjectsOverview']);
Route::get('/subjects/{subject}/topics', [HomeController::class, 'subjectTopics']);
    // Route::get('/topics/{topic}/questions', [HomeController::class, 'topicQuestions']);
