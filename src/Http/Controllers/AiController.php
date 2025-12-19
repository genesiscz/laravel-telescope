<?php

namespace Laravel\Telescope\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Laravel\Telescope\Contracts\EntriesRepository;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiController extends Controller
{
    /**
     * Ask AI a question about an entry.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Laravel\Telescope\Contracts\EntriesRepository  $storage
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\JsonResponse
     */
    public function ask(Request $request, EntriesRepository $storage)
    {
        $validated = $request->validate([
            'entry_type' => 'required|string',
            'entry_id' => 'required|string',
            'question' => 'required|string|max:2000',
            'context' => 'nullable|array',
        ]);

        // Check if AI is enabled
        if (! config('telescope.ai.enabled', false)) {
            return response()->json([
                'error' => 'AI features are not enabled. Set TELESCOPE_AI_ENABLED=true in your .env file.',
            ], 400);
        }

        // Get the entry data if not provided in context
        $entryData = $validated['context'] ?? null;
        if (! $entryData) {
            try {
                $entry = $storage->find($validated['entry_id']);
                $entryData = $entry->toArray();
            } catch (\Exception $e) {
                return response()->json([
                    'error' => 'Entry not found: '.$validated['entry_id'],
                ], 404);
            }
        }

        $provider = config('telescope.ai.provider', 'openai');
        $model = config('telescope.ai.model', 'gpt-4o-mini');
        $apiKey = config("telescope.ai.keys.{$provider}");

        if (! $apiKey) {
            return response()->json([
                'error' => "API key not configured for provider: {$provider}. Set the appropriate API key in your .env file.",
            ], 400);
        }

        // Build the prompt
        $systemPrompt = $this->buildSystemPrompt($validated['entry_type']);
        $userPrompt = $this->buildUserPrompt($validated['question'], $entryData);

        return $this->streamResponse($provider, $model, $apiKey, $systemPrompt, $userPrompt);
    }

    /**
     * Build the system prompt based on entry type.
     *
     * @param  string  $entryType
     * @return string
     */
    protected function buildSystemPrompt(string $entryType): string
    {
        $basePrompt = "You are an expert Laravel developer and debugger helping to analyze Laravel Telescope entries. ";

        $typePrompts = [
            'requests' => "You are analyzing an HTTP request entry. Focus on request/response data, status codes, performance, and potential issues.",
            'exceptions' => "You are analyzing an exception entry. Focus on the error message, stack trace, potential causes, and solutions.",
            'logs' => "You are analyzing a log entry. Help understand the log context and any issues it might indicate.",
            'jobs' => "You are analyzing a queued job entry. Focus on job status, data, failures, and potential issues.",
            'commands' => "You are analyzing an Artisan command entry. Focus on command arguments, exit codes, and execution issues.",
            'models' => "You are analyzing an Eloquent model action entry. Focus on the model changes and potential implications.",
            'queries' => "You are analyzing a database query entry. Focus on query performance, potential N+1 issues, and optimization opportunities.",
        ];

        return $basePrompt.($typePrompts[$entryType] ?? "You are analyzing a Telescope entry. Provide helpful insights.");
    }

    /**
     * Build the user prompt with context.
     *
     * @param  string  $question
     * @param  array  $entryData
     * @return string
     */
    protected function buildUserPrompt(string $question, array $entryData): string
    {
        $contextJson = json_encode($entryData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return "Here is the Telescope entry data:\n\n```json\n{$contextJson}\n```\n\nUser Question: {$question}";
    }

    /**
     * Stream the AI response.
     *
     * @param  string  $provider
     * @param  string  $model
     * @param  string  $apiKey
     * @param  string  $systemPrompt
     * @param  string  $userPrompt
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    protected function streamResponse(string $provider, string $model, string $apiKey, string $systemPrompt, string $userPrompt): StreamedResponse
    {
        return new StreamedResponse(function () use ($provider, $model, $apiKey, $systemPrompt, $userPrompt) {
            try {
                $response = $this->callAiProvider($provider, $model, $apiKey, $systemPrompt, $userPrompt);

                echo "data: ".json_encode(['content' => $response])."\n\n";
                echo "data: [DONE]\n\n";
            } catch (\Exception $e) {
                Log::error('Telescope AI Error', [
                    'error' => $e->getMessage(),
                    'provider' => $provider,
                ]);

                echo "data: ".json_encode(['error' => $e->getMessage()])."\n\n";
                echo "data: [DONE]\n\n";
            }

            ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Call the AI provider.
     *
     * @param  string  $provider
     * @param  string  $model
     * @param  string  $apiKey
     * @param  string  $systemPrompt
     * @param  string  $userPrompt
     * @return string
     */
    protected function callAiProvider(string $provider, string $model, string $apiKey, string $systemPrompt, string $userPrompt): string
    {
        // Try to use instructor-php if available
        if (class_exists(\Cognesy\Instructor\Instructor::class)) {
            return $this->callWithInstructor($provider, $model, $apiKey, $systemPrompt, $userPrompt);
        }

        // Fallback to direct API calls
        return $this->callDirectApi($provider, $model, $apiKey, $systemPrompt, $userPrompt);
    }

    /**
     * Call AI using instructor-php.
     *
     * @param  string  $provider
     * @param  string  $model
     * @param  string  $apiKey
     * @param  string  $systemPrompt
     * @param  string  $userPrompt
     * @return string
     */
    protected function callWithInstructor(string $provider, string $model, string $apiKey, string $systemPrompt, string $userPrompt): string
    {
        $instructor = new \Cognesy\Instructor\Instructor();

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        $response = $instructor
            ->withConnection($provider)
            ->withModel($model)
            ->withMessages($messages)
            ->get();

        return $response ?? 'No response from AI provider.';
    }

    /**
     * Call AI provider directly via HTTP.
     *
     * @param  string  $provider
     * @param  string  $model
     * @param  string  $apiKey
     * @param  string  $systemPrompt
     * @param  string  $userPrompt
     * @return string
     */
    protected function callDirectApi(string $provider, string $model, string $apiKey, string $systemPrompt, string $userPrompt): string
    {
        $endpoints = [
            'openai' => 'https://api.openai.com/v1/chat/completions',
            'anthropic' => 'https://api.anthropic.com/v1/messages',
            'groq' => 'https://api.groq.com/openai/v1/chat/completions',
            'mistral' => 'https://api.mistral.ai/v1/chat/completions',
            'gemini' => 'https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent',
        ];

        $endpoint = $endpoints[$provider] ?? $endpoints['openai'];

        if ($provider === 'anthropic') {
            return $this->callAnthropicApi($endpoint, $model, $apiKey, $systemPrompt, $userPrompt);
        }

        if ($provider === 'gemini') {
            return $this->callGeminiApi($endpoint, $apiKey, $userPrompt);
        }

        // OpenAI-compatible providers (OpenAI, Groq, Mistral, etc.)
        return $this->callOpenAiCompatibleApi($endpoint, $model, $apiKey, $systemPrompt, $userPrompt);
    }

    /**
     * Call OpenAI-compatible API.
     */
    protected function callOpenAiCompatibleApi(string $endpoint, string $model, string $apiKey, string $systemPrompt, string $userPrompt): string
    {
        $client = new \GuzzleHttp\Client();

        $response = $client->post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'max_tokens' => 2000,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        return $data['choices'][0]['message']['content'] ?? 'No response content.';
    }

    /**
     * Call Anthropic API.
     */
    protected function callAnthropicApi(string $endpoint, string $model, string $apiKey, string $systemPrompt, string $userPrompt): string
    {
        $client = new \GuzzleHttp\Client();

        $response = $client->post($endpoint, [
            'headers' => [
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $model,
                'max_tokens' => 2000,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        return $data['content'][0]['text'] ?? 'No response content.';
    }

    /**
     * Call Gemini API.
     */
    protected function callGeminiApi(string $endpoint, string $apiKey, string $userPrompt): string
    {
        $client = new \GuzzleHttp\Client();

        $response = $client->post($endpoint.'?key='.$apiKey, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'contents' => [
                    ['parts' => [['text' => $userPrompt]]],
                ],
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? 'No response content.';
    }
}
