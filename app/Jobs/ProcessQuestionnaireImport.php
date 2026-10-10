<?php

namespace App\Jobs;

use App\Models\QuestionnaireImport;
use App\Services\QuestionnaireImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessQuestionnaireImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;
    public int $tries = 2;

    public function __construct(
        public int $questionnaireImportId
    ) {
    }

    public function handle(
        QuestionnaireImportService $service
    ): void {
        $import = QuestionnaireImport::find(
            $this->questionnaireImportId
        );

        if (!$import) {
            return;
        }

        $service->process($import);
    }
}
