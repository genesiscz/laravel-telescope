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
     * Get AI configuration status.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function config(Request $request)
    {
        $enabled = config('telescope.ai.enabled', false);
        $provider = config('telescope.ai.provider', 'openai');
        $model = config('telescope.ai.model', 'gpt-4o-mini');
        $apiKey = config("telescope.ai.keys.{$provider}");

        // Build cookie string for curl commands (session cookie is HttpOnly, JS can't read it)
        $cookieParts = [];
        foreach ($request->cookies->all() as $name => $value) {
            if (is_string($value)) {
                $cookieParts[] = $name.'='.urlencode($value);
            }
        }
        $cookieString = implode('; ', $cookieParts);

        return response()->json([
            'configured' => $enabled && ! empty($apiKey),
            'provider' => $provider,
            'model' => $model,
            'cookies' => $cookieString,
        ]);
    }

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
            'provider' => 'nullable|string',
            'model' => 'nullable|string',
        ]);

        // Check if AI is enabled
        if (! config('telescope.ai.enabled', false)) {
            return response()->json([
                'error' => 'AI features are not enabled. Set TELESCOPE_AI_ENABLED=true in your .env file.',
            ], 400);
        }

        // Get provider and model from request or config
        $provider = $validated['provider'] ?? config('telescope.ai.provider', 'openai');
        $model = $validated['model'] ?? config('telescope.ai.model', 'gpt-4o-mini');
        $apiKey = config("telescope.ai.keys.{$provider}");

        if (! $apiKey) {
            return response()->json([
                'error' => "API key not configured for provider: {$provider}. Set the appropriate API key in your .env file.",
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

        // Build the prompt
        $systemPrompt = $this->buildSystemPrompt($validated['entry_type']);
        $userPrompt = $this->buildUserPrompt($validated['question'], $entryData);

        // Log the AI question
        Log::info('Telescope AI Question', [
            'entry_type' => $validated['entry_type'],
            'entry_id' => $validated['entry_id'],
            'question' => $validated['question'],
            'provider' => $provider,
            'model' => $model,
            'context_size' => strlen(json_encode($entryData)),
        ]);

        return $this->streamResponse($provider, $model, $apiKey, $systemPrompt, $userPrompt, $validated);
    }

    /**
     * Build the system prompt based on entry type.
     *
     * @param  string  $entryType
     * @return string
     */
    protected function buildSystemPrompt(string $entryType): string
    {
        $basePrompt = "You are an expert Laravel developer and debugger helping to analyze Laravel Telescope entries. Be concise and practical in your answers. ";

        $typePrompts = [
            'requests' => "You are analyzing an HTTP request entry. Focus on request/response data, status codes, performance, SQL queries in the batch, and potential issues.",
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
     * Stream the AI response with true streaming.
     *
     * @param  string  $provider
     * @param  string  $model
     * @param  string  $apiKey
     * @param  string  $systemPrompt
     * @param  string  $userPrompt
     * @param  array  $validated
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    protected function streamResponse(string $provider, string $model, string $apiKey, string $systemPrompt, string $userPrompt, array $validated): StreamedResponse
    {
        return new StreamedResponse(function () use ($provider, $model, $apiKey, $systemPrompt, $userPrompt, $validated) {
            $fullResponse = '';

            try {
                // Use streaming API calls
                $this->callAiProviderWithStreaming($provider, $model, $apiKey, $systemPrompt, $userPrompt, function ($chunk) use (&$fullResponse) {
                    $fullResponse .= $chunk;
                    echo "data: ".json_encode(['content' => $chunk])."\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                });

                echo "data: [DONE]\n\n";

                // Log the complete AI response
                Log::info('Telescope AI Response', [
                    'entry_type' => $validated['entry_type'],
                    'entry_id' => $validated['entry_id'],
                    'question' => $validated['question'],
                    'response_length' => strlen($fullResponse),
                    'response_preview' => substr($fullResponse, 0, 500),
                ]);
            } catch (\Exception $e) {
                Log::error('Telescope AI Error', [
                    'error' => $e->getMessage(),
                    'provider' => $provider,
                    'entry_id' => $validated['entry_id'] ?? null,
                ]);

                echo "data: ".json_encode(['error' => $e->getMessage()])."\n\n";
                echo "data: [DONE]\n\n";
            }

            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Call the AI provider with streaming support.
     *
     * @param  string  $provider
     * @param  string  $model
     * @param  string  $apiKey
     * @param  string  $systemPrompt
     * @param  string  $userPrompt
     * @param  callable  $onChunk
     * @return void
     */
    protected function callAiProviderWithStreaming(string $provider, string $model, string $apiKey, string $systemPrompt, string $userPrompt, callable $onChunk): void
    {
        $endpoints = [
            'openai' => 'https://api.openai.com/v1/chat/completions',
            'anthropic' => 'https://api.anthropic.com/v1/messages',
            'groq' => 'https://api.groq.com/openai/v1/chat/completions',
            'mistral' => 'https://api.mistral.ai/v1/chat/completions',
            'gemini' => 'https://generativelanguage.googleapis.com/v1beta/models/'.$model.':streamGenerateContent',
        ];

        $endpoint = $endpoints[$provider] ?? $endpoints['openai'];

        if ($provider === 'anthropic') {
            $this->streamAnthropicApi($endpoint, $model, $apiKey, $systemPrompt, $userPrompt, $onChunk);
        } elseif ($provider === 'gemini') {
            $this->streamGeminiApi($endpoint, $apiKey, $userPrompt, $onChunk);
        } else {
            // OpenAI-compatible providers (OpenAI, Groq, Mistral, etc.)
            $this->streamOpenAiCompatibleApi($endpoint, $model, $apiKey, $systemPrompt, $userPrompt, $onChunk);
        }
    }

    /**
     * Stream from OpenAI-compatible API.
     */
    protected function streamOpenAiCompatibleApi(string $endpoint, string $model, string $apiKey, string $systemPrompt, string $userPrompt, callable $onChunk): void
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'max_tokens' => 4000,
                'stream' => true,
            ]),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($onChunk) {
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (strpos($line, 'data: ') === 0) {
                        $json = substr($line, 6);
                        if ($json === '[DONE]') {
                            continue;
                        }
                        $decoded = json_decode($json, true);
                        if (isset($decoded['choices'][0]['delta']['content'])) {
                            $onChunk($decoded['choices'][0]['delta']['content']);
                        }
                    }
                }

                return strlen($data);
            },
        ]);

        curl_exec($ch);

        if (curl_errno($ch)) {
            throw new \Exception('Curl error: '.curl_error($ch));
        }

        curl_close($ch);
    }

    /**
     * Stream from Anthropic API.
     */
    protected function streamAnthropicApi(string $endpoint, string $model, string $apiKey, string $systemPrompt, string $userPrompt, callable $onChunk): void
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'x-api-key: '.$apiKey,
                'anthropic-version: 2023-06-01',
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $model,
                'max_tokens' => 4000,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'stream' => true,
            ]),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($onChunk) {
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (strpos($line, 'data: ') === 0) {
                        $json = substr($line, 6);
                        $decoded = json_decode($json, true);
                        if (isset($decoded['type']) && $decoded['type'] === 'content_block_delta') {
                            if (isset($decoded['delta']['text'])) {
                                $onChunk($decoded['delta']['text']);
                            }
                        }
                    }
                }

                return strlen($data);
            },
        ]);

        curl_exec($ch);

        if (curl_errno($ch)) {
            throw new \Exception('Curl error: '.curl_error($ch));
        }

        curl_close($ch);
    }

    /**
     * Stream from Gemini API.
     */
    protected function streamGeminiApi(string $endpoint, string $apiKey, string $userPrompt, callable $onChunk): void
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint.'?key='.$apiKey.'&alt=sse',
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'contents' => [
                    ['parts' => [['text' => $userPrompt]]],
                ],
            ]),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($onChunk) {
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (strpos($line, 'data: ') === 0) {
                        $json = substr($line, 6);
                        $decoded = json_decode($json, true);
                        if (isset($decoded['candidates'][0]['content']['parts'][0]['text'])) {
                            $onChunk($decoded['candidates'][0]['content']['parts'][0]['text']);
                        }
                    }
                }

                return strlen($data);
            },
        ]);

        curl_exec($ch);

        if (curl_errno($ch)) {
            throw new \Exception('Curl error: '.curl_error($ch));
        }

        curl_close($ch);
    }
}
