<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RecoverTutorSession135 extends Command
{
    protected $signature = 'tutor:recover-session-135 {--apply : Commit the recovery; default is dry run}';
    protected $description = 'One-time guarded recovery for student 21, topic 3, sessions 134 and 135';

    public function handle(): int
    {
        $check = function (bool $lock = false): array {
            $sessions = DB::table('tutor_sessions')->whereIn('id', [134, 135])->orderBy('id');
            if ($lock) $sessions->lockForUpdate();
            $rows = $sessions->get()->keyBy('id');
            $old = $rows->get(134);
            $current = $rows->get(135);
            if (!$old || !$current) throw new RuntimeException('Expected sessions not found.');
            foreach ([$old, $current] as $s) {
                if ((int) $s->student_id !== 21 || (int) $s->topic_id !== 3 || (int) $s->learning_period_id !== 3) {
                    throw new RuntimeException('Session ownership/scope mismatch.');
                }
            }
            if ($old->status !== 'completed' || $current->status !== 'active' || $current->session_mode !== 'review') {
                throw new RuntimeException('Session states changed; review manually.');
            }
            if (DB::table('tutor_sessions')->where('student_id',21)->where('topic_id',3)->where('learning_period_id',3)->where('id','>',135)->exists()) {
                throw new RuntimeException('Newer session exists. No recovery performed.');
            }
            $objectiveRows = DB::table('tutor_session_objectives')->whereIn('tutor_session_id',[134,135])->orderBy('id');
            if ($lock) $objectiveRows->lockForUpdate();
            $objectives = $objectiveRows->get()->keyBy('id');
            foreach ([486,487,488,489,490,491] as $id) if (!$objectives->has($id)) throw new RuntimeException('An expected objective is missing.');
            $expected = [486=>[134,8,'completed'],487=>[134,9,'material_gap'],488=>[134,11,'material_gap'],489=>[135,8,'in_progress'],490=>[135,9,'not_started'],491=>[135,11,'not_started']];
            foreach ($expected as $id => [$sessionId,$objectiveId,$status]) {
                $o=$objectives[$id];
                if ((int)$o->tutor_session_id !== $sessionId || (int)$o->learning_objective_id !== $objectiveId || $o->status !== $status) {
                    throw new RuntimeException("Objective {$id} changed; review manually.");
                }
            }
            foreach ([489,490,491] as $id) {
                if ((int)$objectives[$id]->attempts !== 0 || (int)$objectives[$id]->correct_attempts !== 0) {
                    throw new RuntimeException('New session has attempts; manual reconciliation needed.');
                }
            }
            if (DB::table('tutor_messages')->where('tutor_session_id',135)->count() !== 1) {
                throw new RuntimeException('New session chat has changed; manual review needed.');
            }
            $stateQuery=DB::table('student_topic_learning_states')->where('id',93);
            if ($lock) $stateQuery->lockForUpdate();
            $state=$stateQuery->first();
            if (!$state || (int)$state->student_id !== 21 || (int)$state->topic_id !== 3 || (int)$state->learning_period_id !== 3 || $state->progression_status !== 'covered' || $state->mastery_status !== 'mastered') {
                throw new RuntimeException('Learning state changed or mismatched; manual review needed.');
            }
            return [$old,$current,$objectives,$state];
        };

        try { [$old,$current,$objectives,$state] = $check(); }
        catch (\Throwable $e) { $this->error($e->getMessage()); return self::FAILURE; }

        $this->table(['Record','Current','Proposed'], [
            ['Session 134','completed','unchanged'],
            ['Session 135','active/review','active/learning'],
            ['Objective 489','in_progress (0 attempts)','completed, linked to evidence in Session 134'],
            ['Objectives 490/491','not_started','unchanged'],
            ['Topic state 93','covered/mastered','in_progress/developing'],
            ['Chat messages','19 + 1','unchanged'],
        ]);
        if (!$this->option('apply')) { $this->warn('DRY RUN. Nothing changed. Back up your database; rerun with --apply.'); return self::SUCCESS; }
        try {
            DB::transaction(function () use ($check) {
                [$old,$current,$objectives,$state] = $check(true);
                // Carry forward the completion status, but NOT the attempt counters:
                // the original evidence/attempts remain in Session 134 to avoid double counting.
                DB::table('tutor_session_objectives')->where('id',489)->update([
                    'status'=>'completed',
                    'completed_at'=>$objectives[486]->completed_at,
                    'notes'=>json_encode(['carried_forward_from_session'=>134, 'original_objective_record_id'=>486, 'message'=>'Completion evidence and attempts retained in original session']),
                    'support_status'=>$objectives[486]->support_status,
                    'support_reason'=>$objectives[486]->support_reason,
                    'updated_at'=>now(),
                ]);
                DB::table('tutor_sessions')->where('id',135)->update(['session_mode'=>'learning','last_activity_at'=>now(),'updated_at'=>now()]);
                DB::table('student_topic_learning_states')->where('id',93)->update([
                    'progression_status'=>'in_progress', 'mastery_status'=>'developing',
                    'mastered_at'=>null, 'move_on_chosen'=>0, 'move_on_reason'=>null, 'move_on_at'=>null,
                    'updated_at'=>now(),
                ]);
            },3);
        } catch (\Throwable $e) { $this->error('No changes committed: '.$e->getMessage()); return self::FAILURE; }
        $this->info('Recovered. Session 135 stays active; Objective 8 recognized as complete; topic unmastered. Original session and chat preserved.');
        return self::SUCCESS;
    }
}
