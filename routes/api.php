<?php

use App\Http\Controllers\AssessmentBuilderController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AssessmentSectionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClassInvitationController;
use App\Http\Controllers\CourseMaterialController;
use App\Http\Controllers\CurriculumAnalysisController;
use App\Http\Controllers\GradeLevelController;
use App\Http\Controllers\GradeSubjectController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LearningObjectiveController;
use App\Http\Controllers\LearningPeriodController;
use App\Http\Controllers\ModernAssessmentBuilderController;
use App\Http\Controllers\PaperGeneratorController;
use App\Http\Controllers\PracticePaperController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\QuestionnaireImportController;
use App\Http\Controllers\SchoolController;
use App\Http\Controllers\StudentAnswerController;
use App\Http\Controllers\StudentAssessmentController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\TeacherGradebookController;
use App\Http\Controllers\TeacherProgressController;
use App\Http\Controllers\TopicController;
use App\Http\Controllers\TutorController;
use App\Http\Controllers\UnitController;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


/*
|--------------------------------------------------------------------------
| Public Email Verification Routes
|--------------------------------------------------------------------------
*/

Route::get('/email/verify/{id}/{hash}', function (Request $request, $id, $hash) {
    $user = User::findOrFail($id);

    if (!hash_equals(sha1($user->getEmailForVerification()), (string) $hash)) {
        return response()->json(['message' => 'Invalid verification link.'], 403);
    }

    if ($user->hasVerifiedEmail()) {
        return response()->json(['message' => 'Email already verified.']);
    }

    $user->markEmailAsVerified();

    event(new Verified($user));

    return response()->json(['message' => 'Email verified successfully.']);
})->middleware(['signed', 'throttle:6,1'])->name('verification.verify');

Route::post('/email/resend-verification', function (Request $request) {
    $request->validate(['email' => ['required', 'email']]);

    $user = User::where('email', $request->email)->first();

    if (!$user) {
        return response()->json(['message' => 'If that email exists, a link has been sent.']);
    }

    if ($user->hasVerifiedEmail()) {
        return response()->json(['message' => 'Email is already verified.']);
    }

    $user->sendEmailVerificationNotification();

    return response()->json(['message' => 'Verification link sent.']);
})->middleware(['throttle:6,1']);


/*
|--------------------------------------------------------------------------
| Public Authentication Routes
|--------------------------------------------------------------------------
*/

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/refresh-token', [AuthController::class, 'refreshToken']);

// Public class invitation preview
Route::get('/class-invitations/{classCode}', [ClassInvitationController::class, 'show']);
Route::post('/class-invitations/{classCode}/resolve-account')->middleware('throttle:10,1');
Route::get('/auth/google', [AuthController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);

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
    | General Question Access
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
    Route::get('/grade-subjects/{gradeId}/{subjectId}/topics', [TopicController::class, 'topicsByGradeAndSubject']);
    Route::get('/topics', [TopicController::class, 'index']);
    Route::get('/topics-by-subject', [TopicController::class, 'topicsBySubject']);
    Route::get('/questions/all', [QuestionController::class, 'allQuestions']);
    Route::get('/questions/search', [QuestionController::class, 'search']);
    Route::get('/questions/{id}', [QuestionController::class, 'show']);
    Route::post('/questions/{id}/approve-for-assessment', [QuestionController::class, 'approveForAssessment']);
    Route::post('/questions/{id}/reject-for-assessment', [QuestionController::class, 'rejectForAssessment']);
    Route::get('/questions/assessment-pool/objective/{learningObjectiveId}', [QuestionController::class, 'assessmentQuestionsByObjective']);
    Route::get('/reports/questions-per-subject', [HomeController::class, 'questionsPerSubject']);

    Route::get('/topics/{id}/question-count', [QuestionController::class, 'getQuestionCount']);
    Route::get('/topics/{topic}/questions', [QuestionController::class, 'byTopic']);
    Route::get('/topics/{topic}/questions/no-pagination', [QuestionController::class, 'byTopicNoPagination']);


    /*
    |--------------------------------------------------------------------------
    | School & User Management
    |--------------------------------------------------------------------------
    */

    Route::get('/grade-levels/{gradeId}/subjects', [SubjectController::class, 'subjectsByGrade']);
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

    Route::get('/grade-subjects', [GradeSubjectController::class, 'index']);
    Route::post('/grade-subjects', [GradeSubjectController::class, 'store']);
    Route::get('/grade-subjects/{gradeSubject}', [GradeSubjectController::class, 'show']);
    Route::put('/grade-subjects/{gradeSubject}', [GradeSubjectController::class, 'update']);
    Route::delete('/grade-subjects/{gradeSubject}', [GradeSubjectController::class, 'destroy']);

    Route::post('/subjects', [SubjectController::class, 'createSubject']);
    Route::put('/subjects/{id}', [SubjectController::class, 'update']);

    Route::put('/topics/{topic}', [TopicController::class, 'update']);
    Route::delete('/topics/{id}', [TopicController::class, 'destroy']);


    /*
    |--------------------------------------------------------------------------
    | Approved Course Materials
    |--------------------------------------------------------------------------
    */

    Route::get('/course-materials', [CourseMaterialController::class, 'index']);
    Route::post('/course-materials', [CourseMaterialController::class, 'store']);
    Route::post('/course-materials/{courseMaterial}/approve', [CourseMaterialController::class, 'approve']);
    Route::delete('/course-materials/{courseMaterial}', [CourseMaterialController::class, 'destroy']);


    /*
    |--------------------------------------------------------------------------
    | Learning Periods
    |--------------------------------------------------------------------------
    |
    | Teachers define the content students should focus on during
    | a specific learning period.
    |
    */

    Route::get('/learning-periods', [LearningPeriodController::class, 'index']);
    Route::post('/learning-periods', [LearningPeriodController::class, 'store']);
    Route::get('/learning-periods/{learningPeriod}', [LearningPeriodController::class, 'show']);
    Route::put('/learning-periods/{learningPeriod}', [LearningPeriodController::class, 'update']);
    Route::post('/learning-periods/{learningPeriod}/publish', [LearningPeriodController::class, 'publish']);
    Route::post('/learning-periods/{learningPeriod}/unpublish', [LearningPeriodController::class, 'unpublish']);
    Route::delete('/learning-periods/{learningPeriod}', [LearningPeriodController::class, 'destroy']);


    /*
    |--------------------------------------------------------------------------
    | Learning Period Topics
    |--------------------------------------------------------------------------
    */

    Route::get('/learning-periods/{learningPeriod}/topics', [LearningPeriodController::class, 'topics']);
    Route::post('/learning-periods/{learningPeriod}/topics', [LearningPeriodController::class, 'addTopic']);
    Route::put('/learning-periods/{learningPeriod}/topics/{learningPeriodTopic}', [LearningPeriodController::class, 'updateTopic']);
    Route::delete('/learning-periods/{learningPeriod}/topics/{learningPeriodTopic}', [LearningPeriodController::class, 'removeTopic']);


    /*
    |--------------------------------------------------------------------------
    | AI Tutor Engine
    |--------------------------------------------------------------------------
    */

    /*
    | Student learning periods
    */

    Route::get('/tutor/groups/{group}/learning-periods', [TutorController::class, 'learningPeriods']);
    Route::get('/tutor/groups/{group}/learning-periods/current', [TutorController::class, 'currentLearningPeriods']);


    /*
    | Student tutor navigation
    */

    Route::get('/tutor/groups', [TutorController::class, 'groups']);
    Route::get('/tutor/groups/{group}/subjects', [TutorController::class, 'subjects']);
    Route::get('/tutor/groups/{group}/subjects/{gradeSubject}/units', [TutorController::class, 'units']);
    Route::get('/tutor/groups/{group}/subjects/{gradeSubject}/units/{unit}/topics', [TutorController::class, 'topics']);
    Route::get('/tutor/groups/{group}/subjects/{gradeSubject}/units/{unit}/topics/{topic}/objectives', [TutorController::class, 'objectives']);


    /*
    | Tutor Sessions
    |
    | Student selects a learning period/topic.
    | The backend automatically attaches all active objectives.
    */

    Route::post('/tutor/sessions', [TutorController::class, 'createSession']);
    Route::get('/tutor/sessions', [TutorController::class, 'sessions']);
    Route::get('/tutor/sessions/{tutorSession}', [TutorController::class, 'showSession']);
    Route::post('/tutor/sessions/{tutorSession}/messages', [TutorController::class, 'message']);
    Route::post('/tutor/sessions/{tutorSession}/end', [TutorController::class, 'endSession']);
    Route::post('/tutor/sessions/{tutorSession}/continue', [TutorController::class, 'continueLearning']);
    Route::post('/tutor/sessions/{tutorSession}/checkpoints/{checkpointMessage}/answer', [TutorController::class, 'answerCheckpoint']);
    Route::post('/tutor/sessions/{tutorSession}/checkpoints/{checkpointMessage}/skip', [TutorController::class, 'skipCheckpoint']);
    Route::post(
        '/tutor/sessions/{tutorSession}/next-objective',
        [TutorController::class, 'nextObjective']
    );
    /*
    |--------------------------------------------------------------------------
    | Legacy Tutor Endpoint
    |--------------------------------------------------------------------------
    */

    Route::post('/tutor/ask', [TutorController::class, 'ask']);


    /*
    |--------------------------------------------------------------------------
    | Question Bank
    |--------------------------------------------------------------------------
    */

    Route::post('/questions', [QuestionController::class, 'store']);
    Route::put('/questions/{id}', [QuestionController::class, 'update']);
    Route::delete('/questions/{id}', [QuestionController::class, 'destroy']);
    Route::get('/my-questions', [QuestionController::class, 'myQuestions']);
    Route::post('/questions/ai-assist', [QuestionController::class, 'aiAssist']);
    Route::post('/questions/ai-generate', [QuestionController::class, 'generateAIQuestions']);
    Route::post('/questions/ai-generate/store', [QuestionController::class, 'storeAIQuestions']);
    Route::get('/questions/by-topics', [AssessmentBuilderController::class, 'questionsByTopics']);


    /*
    |--------------------------------------------------------------------------
    | Practice Papers
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
    | Modern Assessment Builder
    |--------------------------------------------------------------------------
    */

    Route::post('/assessment-builder', [ModernAssessmentBuilderController::class, 'store']);
    Route::get('/assessment-builder/question-pool', [ModernAssessmentBuilderController::class, 'questionPool']);
    Route::get('/assessment-builder/{assessment}', [ModernAssessmentBuilderController::class, 'show']);
    Route::put('/assessment-builder/{assessment}', [ModernAssessmentBuilderController::class, 'update']);
    Route::put('/assessment-builder/{assessment}/scope', [ModernAssessmentBuilderController::class, 'updateScope']);
    Route::put('/assessment-builder/{assessment}/blueprint', [ModernAssessmentBuilderController::class, 'updateBlueprint']);
    Route::post('/assessment-builder/{assessment}/publish', [ModernAssessmentBuilderController::class, 'publish']);
    Route::post('/assessment-builder/{assessment}/questions', [ModernAssessmentBuilderController::class, 'addQuestions']);
    Route::delete('/assessment-builder/{assessment}/questions/{question}', [ModernAssessmentBuilderController::class, 'removeQuestion']);
    Route::put('/assessment-builder/{assessment}/questions/reorder', [ModernAssessmentBuilderController::class, 'reorderQuestions']);


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
    Route::post('/groups/join', [ClassInvitationController::class, 'join']);
    Route::post('/class-invitations/{classCode}/join', [ClassInvitationController::class, 'join']);
    Route::get('/groups/{id}', [GroupController::class, 'show']);
    Route::put('/groups/{id}', [GroupController::class, 'update']);
    Route::delete('/groups/{id}', [GroupController::class, 'destroy']);
    Route::get('/groups/{id}/eligible-students', [GroupController::class, 'eligibleStudents']);
    Route::post('/groups/{id}/students', [GroupController::class, 'addStudents']);
    Route::delete('/groups/{id}/students/{studentId}', [GroupController::class, 'removeStudent']);
    Route::get('/groups/{group}/assignments', [GroupController::class, 'assignments']);
    Route::get('/groups/{group}/assignments/{assessment}/submissions', [GroupController::class, 'assignmentSubmissions']);


    Route::get('/student/groups/{id}', [GroupController::class, 'studentShow']);
    Route::get('/student/groups/{id}/assignments', [GroupController::class, 'studentAssignments']);
    /*
    |--------------------------------------------------------------------------
    | Student Assessment Runtime
    |--------------------------------------------------------------------------
    */

    Route::get('/assessments', [StudentAssessmentController::class, 'myAssessments']);
    Route::get('/assessments/{studentAssessment}', [StudentAssessmentController::class, 'show']);
    Route::post('/assessments/{assessment}/start', [StudentAssessmentController::class, 'startAssessment']);
    Route::post('/answers', [StudentAssessmentController::class, 'saveAnswer']);
    Route::post('/assessments/{studentAssessment}/submit', [StudentAssessmentController::class, 'submit']);
    Route::post('/assessments/{studentAssessment}/finalize', [StudentAssessmentController::class, 'finalize']);
    Route::put('/answers/{answer}', [StudentAssessmentController::class, 'reviewAnswer']);


    /*
    |--------------------------------------------------------------------------
    | Units
    |--------------------------------------------------------------------------
    */

    Route::get('/grade-subjects/{gradeSubject}/units', [UnitController::class, 'index']);
    Route::post('/grade-subjects/{gradeSubject}/units', [UnitController::class, 'store']);
    Route::get('/units/{unit}', [UnitController::class, 'show']);
    Route::put('/units/{unit}', [UnitController::class, 'update']);
    Route::delete('/units/{unit}', [UnitController::class, 'destroy']);


    /*
    |--------------------------------------------------------------------------
    | Topics under Units
    |--------------------------------------------------------------------------
    */

    Route::get('/units/{unit}/topics', [TopicController::class, 'topicsByUnit']);
    Route::post('/units/{unit}/topics', [TopicController::class, 'storeForUnit']);


    /*
    |--------------------------------------------------------------------------
    | Learning Objectives
    |--------------------------------------------------------------------------
    */

    Route::get('/topics/{topic}/learning-objectives', [LearningObjectiveController::class, 'index']);
    Route::post('/topics/{topic}/learning-objectives', [LearningObjectiveController::class, 'store']);
    Route::get('/learning-objectives/{learningObjective}', [LearningObjectiveController::class, 'show']);
    Route::put('/learning-objectives/{learningObjective}', [LearningObjectiveController::class, 'update']);
    Route::delete('/learning-objectives/{learningObjective}', [LearningObjectiveController::class, 'destroy']);
    Route::get('/questions/by-learning-objective/{learningObjectiveId}', [QuestionController::class, 'byLearningObjective']);


    /*
    |--------------------------------------------------------------------------
    | Curriculum Analysis & Course Material Processing
    |--------------------------------------------------------------------------
    */

    Route::post('/course-materials/{courseMaterial}/analyze-curriculum', [CurriculumAnalysisController::class, 'analyze']);
    Route::get('/curriculum-analyses/{curriculumAnalysis}', [CurriculumAnalysisController::class, 'show']);
    Route::post('/curriculum-analyses/{curriculumAnalysis}/refine', [CurriculumAnalysisController::class, 'refine']);
    Route::put('/curriculum-analyses/{curriculumAnalysis}', [CurriculumAnalysisController::class, 'update']);
    Route::post('/curriculum-analyses/{curriculumAnalysis}/approve', [CurriculumAnalysisController::class, 'approve']);
    Route::post('/course-materials/{courseMaterial}/extract', [CourseMaterialController::class, 'extract']);
    Route::post('/course-materials/{courseMaterial}/analyze-content', [CourseMaterialController::class, 'analyzeContent']);
    Route::get('/course-materials/{courseMaterial}/processing-status', [CourseMaterialController::class, 'processingStatus']);

    Route::prefix('teacher/progress')->group(function () {

        Route::get('/', [TeacherProgressController::class, 'dashboard']);
        Route::get('/subjects', [TeacherProgressController::class, 'subjects']);
        Route::get('/learning-periods', [TeacherProgressController::class, 'learningPeriods']);
        Route::get('/students', [TeacherProgressController::class, 'students']);
        Route::get('/activity', [TeacherProgressController::class, 'activity']);
        Route::get('/learning-periods/{learningPeriod}', [TeacherProgressController::class, 'learningPeriod']);
        Route::get('/students/{student}', [TeacherProgressController::class, 'student']);
    });

    Route::prefix('teacher/gradebook')->group(function () {
        Route::get('/', [TeacherGradebookController::class, 'index']);
        Route::get('/export', [TeacherGradebookController::class, 'export']);
        Route::get('/review-queue', [TeacherGradebookController::class, 'reviewQueue']);
        Route::get('/students/{student}', [TeacherGradebookController::class, 'student']);
        Route::put('/answers/{answer}', [TeacherGradebookController::class, 'reviewAnswer']);
    });

    Route::prefix('teacher/questionnaire-imports')->group(function () {
        Route::get('/', [QuestionnaireImportController::class, 'index']);
        Route::post('/', [QuestionnaireImportController::class, 'store']);
        Route::get('/{questionnaireImport}', [QuestionnaireImportController::class, 'show']);
        Route::post('/{questionnaireImport}/reprocess', [QuestionnaireImportController::class, 'reprocess']);
        Route::put('/{questionnaireImport}/items/{item}', [QuestionnaireImportController::class, 'updateItem']);
        Route::post('/{questionnaireImport}/approve', [QuestionnaireImportController::class, 'approve']);
        Route::delete('/{questionnaireImport}', [QuestionnaireImportController::class, 'destroy']);
    });
});
