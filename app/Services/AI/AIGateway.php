<?php

namespace App\Services\AI;

use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\GroqProvider;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AIGateway
{
    public function __construct(
        protected GeminiProvider $gemini,
        protected GroqProvider $groq
    ) {
    }

    /**
     * General chat request.
     *
     * Uses the configured default provider with fallback support.
     */
    public function chat(
        array $messages,
        array $options = []
    ): array {
        return $this->execute(
            'chat',
            $messages,
            $options
        );
    }

    /**
     * General text request.
     *
     * Uses the configured default provider with fallback support.
     */
    public function text(
        array $messages,
        array $options = []
    ): string {
        return $this->execute(
            'text',
            $messages,
            $options
        );
    }

    /**
     * General JSON request.
     *
     * Uses the configured default provider with fallback support.
     *
     * The schema is optional because Groq currently uses
     * JSON object mode rather than the Gemini response schema.
     */
    public function json(
        array $messages,
        array $schema = [],
        array $options = []
    ): array {
        $providerKey = config(
            'services.ai.provider',
            'gemini'
        );

        $provider = $this->resolveProvider(
            $providerKey
        );

        try {
            return $this->callJsonProvider(
                $provider,
                $messages,
                $schema,
                $options
            );
        } catch (Throwable $e) {

            Log::warning(
                "AI JSON provider [{$providerKey}] failed: " .
                $e->getMessage() .
                '. Attempting fallback.'
            );

            return $this->executeJsonFallback(
                $messages,
                $schema,
                $options,
                $providerKey
            );
        }
    }

    /**
     * Curriculum-specific structured JSON request.
     *
     * Curriculum organization uses Gemini as the primary provider
     * and Groq as the fallback provider.
     */
    public function curriculumJson(
        array $messages,
        array $schema,
        array $options = []
    ): array {
        try {

            Log::info(
                'AI curriculum analysis: using Gemini provider.'
            );

            return $this->gemini->json(
                $messages,
                $schema,
                $options
            );

        } catch (Throwable $e) {

            Log::warning(
                'Gemini curriculum analysis failed: ' .
                $e->getMessage() .
                '. Attempting Groq fallback.'
            );

            try {

                Log::info(
                    'AI curriculum analysis: using Groq fallback.'
                );

                return $this->groq->json(
                    $messages,
                    $options
                );

            } catch (Throwable $fallbackException) {

                Log::error(
                    'Both Gemini and Groq curriculum analysis failed.',
                    [
                        'gemini_error' =>
                            $e->getMessage(),

                        'groq_error' =>
                            $fallbackException->getMessage(),
                    ]
                );

                throw new RuntimeException(
                    'All AI providers failed during curriculum analysis.'
                );
            }
        }
    }

    /**
     * Execute a normal text/chat operation.
     */
    protected function execute(
        string $method,
        array $messages,
        array $options = []
    ): mixed {
        $providerKey = config(
            'services.ai.provider',
            'gemini'
        );

        $provider = $this->resolveProvider(
            $providerKey
        );

        try {

            return $provider->{$method}(
                $messages,
                $options
            );

        } catch (Throwable $e) {

            Log::warning(
                "AI Provider [{$providerKey}] failed: " .
                $e->getMessage() .
                '. Attempting fallback.'
            );

            return $this->executeFallback(
                $method,
                $messages,
                $options,
                $providerKey
            );
        }
    }

    /**
     * Resolve a provider by configuration key.
     */
    protected function resolveProvider(
        string $providerKey
    ): object {
        return match ($providerKey) {

            'gemini' => $this->gemini,

            'groq' => $this->groq,

            default => throw new RuntimeException(
                "Unsupported AI provider: {$providerKey}"
            ),
        };
    }

    /**
     * Execute JSON request against a provider.
     */
    protected function callJsonProvider(
        object $provider,
        array $messages,
        array $schema,
        array $options
    ): array {
        if ($provider instanceof GeminiProvider) {

            return $provider->json(
                $messages,
                $schema,
                $options
            );
        }

        if ($provider instanceof GroqProvider) {

            return $provider->json(
                $messages,
                $options
            );
        }

        throw new RuntimeException(
            'Unsupported AI provider for JSON request.'
        );
    }

    /**
     * Execute JSON fallback.
     */
    protected function executeJsonFallback(
        array $messages,
        array $schema,
        array $options,
        string $failedProvider
    ): array {
        if ($failedProvider === 'gemini') {

            try {

                return $this->groq->json(
                    $messages,
                    $options
                );

            } catch (Throwable $e) {

                Log::warning(
                    'Groq JSON fallback also failed: ' .
                    $e->getMessage()
                );
            }
        }

        if ($failedProvider === 'groq') {

            try {

                return $this->gemini->json(
                    $messages,
                    $schema,
                    $options
                );

            } catch (Throwable $e) {

                Log::warning(
                    'Gemini JSON fallback also failed: ' .
                    $e->getMessage()
                );
            }
        }

        throw new RuntimeException(
            'All configured AI providers and fallbacks failed.'
        );
    }

    /**
     * Handles sequential fallback for normal chat/text requests.
     */
    protected function executeFallback(
        string $method,
        array $messages,
        array $options,
        string $failedProvider
    ): mixed {
        $fallbacks = match ($failedProvider) {

            'gemini' => [
                'groq' => $this->groq,
            ],

            'groq' => [
                'gemini' => $this->gemini,
            ],

            default => [],
        };

        foreach ($fallbacks as $providerName => $fallbackProvider) {

            try {

                Log::info(
                    "AI fallback: using {$providerName} provider."
                );

                return $fallbackProvider->{$method}(
                    $messages,
                    $options
                );

            } catch (Throwable $e) {

                Log::warning(
                    "Fallback provider [{$providerName}] also failed: " .
                    $e->getMessage()
                );
            }
        }

        throw new RuntimeException(
            'All configured AI providers and fallbacks failed.'
        );
    }
}