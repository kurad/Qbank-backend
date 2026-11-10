<?php

namespace App\Console\Commands;

use App\Models\TutorSession;
use App\Models\TutorSessionObjective;
use App\Services\TutorSupportingSourceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecheckTutorSupportingSource extends Command
{
    protected $signature = 'tutor:recheck-support {session_id : Existing tutor session ID} {objective_id : Learning objective ID} {--apply : Activate the objective only if source verification succeeds}';
    protected $description = 'Inspect a gap against cached teacher-approved sources; optionally reopen only a verified supported objective.';

    public function handle(TutorSupportingSourceService $sources): int
    {
        $session = TutorSession::find((int) $this->argument('session_id'));
        if (!$session || $session->status !== 'active' || $session->session_mode !== 'learning') {
            $this->error('The requested Tutor session must exist and be in active learning mode.');
            return self::FAILURE;
        }

        $objective = $session->objectives()
            ->where('learning_objective_id', (int) $this->argument('objective_id'))
            ->first();
        if (!$objective || !in_array($objective->status, ['material_gap', 'not_started'], true)
            || (int) $objective->attempts !== 0) {
            $this->error('Objective is absent, already started/finished, or has attempts. No changes made.');
            return self::FAILURE;
        }

        $this->line("Session {$session->id} / learning objective {$objective->learning_objective_id}");
        $this->line('Primary source: '.($objective->support_status ?: 'unknown'));
        $this->line('Primary reason: '.($objective->support_reason ?: '(none)'));

        try {
            $result = $sources->resolve($session, $objective, [
                'status' => $objective->support_status ?? 'partial',
                'reason' => $objective->support_reason ?? '',
                'missing_focus' => [],
            ]);
        } catch (\Throwable $exception) {
            $this->error('Source verification temporarily failed: '.$exception->getMessage());
            $this->line('No tutor progression records changed.');
            return self::FAILURE;
        }

        $this->table(['Result', 'Details'], [
            ['Coverage', $result['status'] ?? 'unknown'],
            ['Reason', $result['reason'] ?? ''],
            ['Approved source IDs', implode(', ', $result['source_ids'] ?? []) ?: '(none)'],
        ]);

        if (($result['status'] ?? '') !== 'supported' || empty($result['source_ids'])) {
            $this->warn('No source verified as adequate. Objective remains unchanged. Inspect the approved page content / source-evaluation logs.');
            return self::SUCCESS;
        }

        if (!$this->option('apply')) {
            $this->info('DRY RUN: supporting source verified. Rerun with --apply to activate this objective.');
            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($session, $objective, $result) {
                $lockedSession = TutorSession::query()->lockForUpdate()->findOrFail($session->id);
                $row = TutorSessionObjective::query()->lockForUpdate()->findOrFail($objective->id);
                if ($lockedSession->status !== 'active' || $lockedSession->session_mode !== 'learning'
                    || !in_array($row->status, ['material_gap', 'not_started'], true)
                    || (int) $row->attempts !== 0) {
                    throw new \RuntimeException('Session or objective changed during verification.');
                }
                $otherActive = $lockedSession->objectives()
                    ->where('id', '!=', $row->id)
                    ->where('status', 'in_progress')->exists();
                if ($otherActive) {
                    throw new \RuntimeException('Another objective is in progress. Refusing conflicting activation.');
                }
                $row->fill([
                    'status' => 'in_progress',
                    'completed_at' => null,
                    'started_at' => $row->started_at ?? now(),
                    'support_status' => 'supporting_source',
                    'support_reason' => $result['reason'] ?? null,
                    'supporting_source_ids' => $result['source_ids'],
                    'supporting_source_started_at' => $row->supporting_source_started_at ?? now(),
                ])->save();
                $lockedSession->messages()->create([
                    'role' => 'assistant',
                    'message_type' => 'supporting_source_notice',
                    'content' => 'I will use an additional source provided by your teacher for this learning objective.',
                    'metadata' => [
                        'learning_objective_id' => $row->learning_objective_id,
                        'support_status' => 'supporting_source',
                        'supporting_source_ids' => $result['source_ids'],
                    ],
                ]);
            });
        } catch (\Throwable $exception) {
            $this->error('No changes applied: '.$exception->getMessage());
            return self::FAILURE;
        }

        $this->info('Objective activated using verified supporting source. Previous completed objectives, scores, and chat preserved.');
        return self::SUCCESS;
    }
}
