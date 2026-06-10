<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\TopicController;
use App\Http\Controllers\SchoolController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\GradeLevelController;
use App\Http\Controllers\StudentAnswerController;
use App\Http\Controllers\PaperGeneratorController;
use App\Http\Controllers\PracticePaperController;
use App\Http\Controllers\AssessmentBuilderController;
use App\Http\Controllers\AssessmentSectionController;
use App\Http\Controllers\StudentAssessmentController;

/*
|--------------------------------------------------------------------------
| Public Email Verification Routes
|--------------------------------------------------------------------------
*/

Route::get('/email/verify/{id}/{hash}', function (Request $request, $id, $hash) {
    $user = User::findOrFail($id);

    if (! hash_equals(sha1($user->getEmailForVerification()), (string) $hash)) {
        return response()->json([
            'message' => 'Invalid verification link.',
        ], 403);
    }

    if ($user->hasVerifiedEmail()) {
        return response()->json([
            'message' => 'Email already verified.',
        ]);
    }

    $user->markEmailAsVerified();
    event(new Verified($user));

    return response()->json([
        'message' => 'Email verified successfully.',
    ]);
})
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');


Route::post('/email/resend-verification', function (Request $request) {
    $request->validate([
        'email' => ['required', 'email'],
    ]);

    $user = User::where('email', $request->email)->first();

    // Prevent email enumeration
    if (! $user) {
        return response()->json([
            'message' => 'If that email exists, a link has been sent.',
        ]);
    }

    if ($user->hasVerifiedEmail()) {
        return response()->json([
            'message' => 'Email is already verified.',
        ]);
    }

    $user->sendEmailVerificationNotification();

    return response()->json([
        'message' => 'Verification link sent.',
    ]);
})
    ->middleware(['throttle:6,1']);


/*
|--------------------------------------------------------------------------
| Public Authentication Routes
|--------------------------------------------------------------------------
*/

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/refresh-token', [AuthController::class, 'refreshToken']);

Route::get('/auth/google', [AuthController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);


/*
|--------------------------------------------------------------------------
| Public Basic Data Routes
|--------------------------------------------------------------------------
*/

Route::get('/subjects', [SubjectController::class, 'index']);
Route::get('/subjects/search', [SubjectController::class, 'searchSubjects']);
Route::get('/subjects/details', [SubjectController::class, 'subjectsByGrade']);
Route::get('/subjects/overview', [HomeController::class, 'subjectsOverview']);
Route::get('/subjects/{subject}/topics', [HomeController::class, 'subjectTopics']);
Route::get('/subjects/{subject}/grades', [SubjectController::class, 'gradesForSubject']);
Route::get('/subjects/{id}/topics', [TopicController::class, 'topicsBySubject']);
Route::get('/subjects/{subjectId}/topics-with-questions', [QuestionController::class, 'topicsWithQuestionsBySubject']);
Route::get('/subjects/{subjectId}/grades/{gradeId}/topics', [TopicController::class, 'topicsBySubjectAndGrade']);
Route::get('/subjects/{subjectId}/grades/{gradeId}/units', [TopicController::class, 'topicsBySubjectGrade']);

Route::get('/grade-levels', [GradeLevelController::class, 'index']);
Route::get('/grade-levels/{gradeId}/subjects', [SubjectController::class, 'getSubjectsByGrade']);

Route::get('/grade-subjects/{gradeId}/{subjectId}/topics', [TopicController::class, 'topicsByGradeAndSubject']);

Route::get('/topics', [TopicController::class, 'index']);
Route::get('/topics-by-subject', [TopicController::class, 'topicsBySubject']);
Route::get('/topics/{id}/question-count', [QuestionController::class, 'getQuestionCount']);
Route::get('/topics/{topic}/questions', [QuestionController::class, 'byTopic']);
Route::get('/topics/{topic}/questions/no-pagination', [QuestionController::class, 'byTopicNoPagination']);

Route::get('/questions/all', [QuestionController::class, 'allQuestions']);
Route::get('/questions/search', [QuestionController::class, 'search']);
Route::get('/questions/{id}', [QuestionController::class, 'show']);

Route::get('/reports/questions-per-subject', [HomeController::class, 'questionsPerSubject']);


/*
|--------------------------------------------------------------------------
| Protected Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'token.not_expired'])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Authenticated User
    |--------------------------------------------------------------------------
    */

    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::post('/logout', [AuthController::class, 'logout']);


    /*
    |--------------------------------------------------------------------------
    | School & User Management
    |--------------------------------------------------------------------------
    */

    Route::get('/schools', [SchoolController::class, 'index']);
    Route::post('/schools', [SchoolController::class, 'store']);
    Route::match(['put', 'patch'], '/schools/{id}', [SchoolController::class, 'update']);

    Route::get('/users', [AuthController::class, 'index']);
    Route::put('/users/{id}', [AuthController::class, 'updateUser']);
    Route::delete('/users/{id}', [AuthController::class, 'deleteUser']);

    Route::get('/students', [AuthController::class, 'getStudents']);


    /*
    |--------------------------------------------------------------------------
    | Subject, Grade Subject & Topic Management
    |--------------------------------------------------------------------------
    */

    Route::post('/subjects', [SubjectController::class, 'createSubject']);
    Route::put('/subjects/{id}', [SubjectController::class, 'update']);

    Route::post('/grade-subjects', [TopicController::class, 'createOrGet']);

    Route::post('/topics', [TopicController::class, 'store']);
    Route::put('/topics/{topic}', [TopicController::class, 'update']);
    Route::delete('/topics/{id}', [TopicController::class, 'destroy']);


    /*
    |--------------------------------------------------------------------------
    | Question Bank
    |--------------------------------------------------------------------------
    */

    Route::post('/questions', [QuestionController::class, 'store']);
    Route::put('/questions/{id}', [QuestionController::class, 'update']);
    Route::delete('/questions/{id}', [QuestionController::class, 'destroy']);

    Route::get('/my-questions', [QuestionController::class, 'myQuestions']);

    Route::post('/questions/ai-generate', [QuestionController::class, 'generateAIQuestions']);
    Route::post('/questions/ai-generate/store', [QuestionController::class, 'storeAIQuestions']);

    Route::get('/questions/by-topics', [AssessmentBuilderController::class, 'questionsByTopics']);


    /*
    |--------------------------------------------------------------------------
    | Practice Papers
    |--------------------------------------------------------------------------
    | Papers are linked only to subjects.
    | Uploaded PDFs are watermarked and stored privately.
    | Students can view/browse only. No download route is exposed.
    |--------------------------------------------------------------------------
    */

    Route::get('/practice-papers', [PracticePaperController::class, 'index']);
    Route::get('/practice-papers/{practicePaper}', [PracticePaperController::class, 'show']);
    Route::get('/practice-papers/{practicePaper}/view', [PracticePaperController::class, 'view']);

    Route::post('/practice-papers', [PracticePaperController::class, 'store']);
    Route::post('/practice-papers/{practicePaper}', [PracticePaperController::class, 'update']);
    Route::delete('/practice-papers/{practicePaper}', [PracticePaperController::class, 'destroy']);


    /*
    |--------------------------------------------------------------------------
    | Assessment Builder
    |--------------------------------------------------------------------------
    */

    Route::post('/create-assessments', [AssessmentBuilderController::class, 'store']);


    /*
    |--------------------------------------------------------------------------
    | Teacher Assessment Management
    |--------------------------------------------------------------------------
    */

    Route::post('/assessments', [AssessmentController::class, 'createAssessment']);
    Route::post('/assessments/assign', [AssessmentController::class, 'assign']);
    Route::post('/assessments/start-practice', [AssessmentController::class, 'startPractice']);
    Route::post('/assessments/practice-for-topic', [AssessmentController::class, 'createPracticeForTopic']);

    Route::get('/assessments/created', [AssessmentController::class, 'listCreatedAssessments']);
    Route::get('/assessments/{id}/details', [AssessmentController::class, 'show']);
    Route::get('/assessments/{id}/questions-for-practice', [AssessmentController::class, 'questionsForPractice']);
    Route::get('/assessments/{assessment}/questions', [AssessmentController::class, 'getAssessmentQuestions']);
    Route::get('/assessments/{id}/pdf/student', [PaperGeneratorController::class, 'generatePdf']);

    Route::put('/assessments/{id}/title', [AssessmentController::class, 'updateTitle']);
    Route::put('/assessments/{id}/reorder', [AssessmentController::class, 'reorderQuestions']);
    Route::put('/assessments/{id}/instructions', [AssessmentController::class, 'updateInstructions']);

    Route::post('/assessments/{id}/questions', [AssessmentController::class, 'addQuestions']);
    Route::post('/assessments/{assessment}/assign', [AssessmentController::class, 'assign']);
    Route::post('/assessments/{id}/assign-group', [AssessmentController::class, 'assignGroup']);

    Route::delete('/assessments/{id}', [AssessmentController::class, 'destroy']);
    Route::delete('/assessments/{assessment}/questions/{question}', [AssessmentController::class, 'removeQuestion']);


    /*
    |--------------------------------------------------------------------------
    | Assessment Sections
    |--------------------------------------------------------------------------
    */

    Route::get('/assessments/{id}/sections', [AssessmentSectionController::class, 'index']);
    Route::post('/assessments/{id}/sections', [AssessmentSectionController::class, 'store']);

    Route::put('/assessment-sections/{id}', [AssessmentSectionController::class, 'update']);
    Route::delete('/assessment-sections/{id}', [AssessmentSectionController::class, 'destroy']);

    Route::post('/assessment-sections/{id}/questions', [AssessmentSectionController::class, 'addSectionQuestions']);
    Route::post('/assessment-sections/{sectionId}/questions/insert', [AssessmentSectionController::class, 'insertQuestionsIntoSection']);
    Route::post('/assessment-sections/{section}/questions/reorder', [AssessmentSectionController::class, 'reorderSectionQuestions']);


    /*
    |--------------------------------------------------------------------------
    | Student Assessment Practice & Results
    |--------------------------------------------------------------------------
    */

    Route::get('/student/practice-assessments', [AssessmentController::class, 'practice']);
    Route::get('/student/assigned-assessments', [AssessmentController::class, 'assignedAssessments']);
    Route::get('/student/assessment-results/{id}', [AssessmentController::class, 'showResults']);
    Route::get('/student/statistics', [HomeController::class, 'statistics']);

    Route::post('/assessments/submit-answers', [StudentAnswerController::class, 'storeStudentAnswers']);
    Route::post('/assessments/update-short-answer-confidence', [StudentAnswerController::class, 'updateShortAnswerConfidence']);


    /*
    |--------------------------------------------------------------------------
    | Groups / Classes
    |--------------------------------------------------------------------------
    */

    Route::get('/groups', [GroupController::class, 'index']);
    Route::post('/groups', [GroupController::class, 'store']);

    Route::get('/my-groups', [GroupController::class, 'myGroups']);
    Route::post('/groups/join', [GroupController::class, 'joinClassByCode']);

    Route::get('/groups/{id}', [GroupController::class, 'show']);
    Route::put('/groups/{id}', [GroupController::class, 'update']);
    Route::delete('/groups/{id}', [GroupController::class, 'destroy']);

    Route::post('/groups/{id}/students', [GroupController::class, 'addStudents']);
    Route::delete('/groups/{id}/students/{studentId}', [GroupController::class, 'removeStudent']);

    Route::get('/groups/{group}/assignments', [GroupController::class, 'assignments']);
    Route::get('/groups/{group}/assignments/{assessment}/submissions', [GroupController::class, 'assignmentSubmissions']);


    /*
    |--------------------------------------------------------------------------
    | StudentAssessment Runtime
    |--------------------------------------------------------------------------
    */

    Route::get('/assessments', [StudentAssessmentController::class, 'myAssessments']);
    Route::get('/assessments/{studentAssessment}', [StudentAssessmentController::class, 'show']);

    Route::post('/assessments/{assessment}/start', [StudentAssessmentController::class, 'startAssessment']);
    Route::post('/answers', [StudentAssessmentController::class, 'saveAnswer']);
    Route::post('/assessments/{studentAssessment}/submit', [StudentAssessmentController::class, 'submit']);
    Route::post('/assessments/{studentAssessment}/finalize', [StudentAssessmentController::class, 'finalize']);

    Route::put('/answers/{answer}', [StudentAssessmentController::class, 'reviewAnswer']);
});