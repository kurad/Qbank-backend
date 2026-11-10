<?php

namespace App\Console\Commands;

use App\Models\StudentTopicLearningState;
use App\Models\TutorSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairPrematureTutorCompletion extends Command
{
    protected $signature = 'tutor:repair-premature-completion
        {session_id : Exact affected Tutor session ID}
        {--apply : Apply changes (without it, command is read-only)}';

    protected $description = 'Safely reopen a prematurely completed Tutor session containing material gaps; preserve completed objectives and chat.';

    public function handle(): int
    {
        $id = (int) $this->argument('session_id');
        $session = TutorSession::with('objectives')->find($id);
        if (!$session) {
            $this->error('Tutor session not found.');
            return self::FAILURE;
        }

        $counts = $session->objectives->groupBy('status')->map->count()->all();
        $this->table(['Session', 'Student', 'Status', 'Objective status counts'], [[
            $session->id, $session->student_id, $session->status, json_encode($counts),
        ]]);

        $gaps = $session->objectives->where('status', 'material_gap');
        $done = $session->objectives->whereIn('status', ['completed', 'mastered']);
        if ($session->status !== 'completed' || $gaps->isEmpty() || $done->isEmpty()) {
            $this->error('Not an eligible partial-completion incident: requires completed session with at least one mastered objective and one material gap. Nothing changed.');
            return self::FAILURE;
        }
        if (!$session->learning_period_id || !$session->topic_id) {
            $this->error('Missing learning period/topic. Manual review required.');
            return self::FAILURE;
        }

        $newer = TutorSession::query()
            ->where('student_id', $session->student_id)
            ->where('learning_period_id', $session->learning_period_id)
            ->where('topic_id', $session->topic_id)
            ->where('id', '>', $session->id)
            ->exists();
        if ($newer) {
            $this->error('A newer session exists for the same topic. Do not overwrite newer progress; manual review required.');
            return self::FAILURE;
        }
        $state = StudentTopicLearningState::query()
            ->where('student_id', $session->student_id)
            ->where('learning_period_id', $session->learning_period_id)
            ->where('topic_id', $session->topic_id)->first();
        if ($state && $state->mastery_status === 'mastered' && $state->updated_at && $session->ended_at && $state->updated_at->gt($session->ended_at)) {
            $this->error('A newer mastery update exists; manual review required.');
            return self::FAILURE;
        }

        $this->info('Will preserve ' . $done->count() . ' completed objectives, all attempts, messages, and scores.');
        $this->info('Will reopen ' . $gaps->count() . ' material-gap objectives for source recheck and restore topic to in_progress/developing.');
        if (!$this->option('apply')) {
            $this->warn('DRY RUN ONLY. Rerun with --apply to commit after checking this session ID.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($session, $state) {
            // Lock and re-check critical state within the transaction.
            $locked = TutorSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->status !== 'completed' || !$locked->objectives()->where('status', 'material_gap')->exists()) {
                throw new \RuntimeException('Session changed while processing. Nothing committed.');
            }
            $locked->objectives()->where('status', 'material_gap')->update([
                'status' => 'not_started',
                'completed_at' => null,
                'support_status' => 'unknown',
                'support_reason' => null,
            ]);
            $locked->update(['status' => 'active', 'ended_at' => null, 'last_activity_at' => now()]);
            $learningState = StudentTopicLearningState::query()
                ->where('student_id', $locked->student_id)
                ->where('learning_period_id', $locked->learning_period_id)
                ->where('topic_id', $locked->topic_id)
                ->lockForUpdate()->first();
            if ($learningState) {
                $learningState->progression_status = 'in_progress';
                $learningState->mastery_status = 'developing';
                $learningState->move_on_chosen = false;
                $learningState->move_on_reason = null;
                $learningState->move_on_at = null;
                $learningState->mastered_at = null;
                $learningState->save();
            }
        });

        $this->info('Session reopened. Resume the existing session; previously completed objectives and chat have not been deleted.');
        return self::SUCCESS;
    }
}
