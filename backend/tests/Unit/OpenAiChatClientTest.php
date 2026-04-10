<?php

namespace Tests\Unit;

use App\Domain\Assistant\OpenAiChatClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiChatClientTest extends TestCase
{
    public function test_generate_json_retries_with_json_object_after_schema_error_and_parses_codeblock_json(): void
    {
        Config::set('services.openai.api_key', 'test-key');
        Config::set('services.openai.base_url', 'https://api.openai.com/v1');
        Config::set('services.openai.assistant_model', 'gpt-smart');
        Config::set('services.openai.assistant_fallback_model', 'gpt-smart');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::sequence()
                ->push(['error' => ['message' => 'unsupported response_format']], 400)
                ->push([
                    'model' => 'gpt-smart',
                    'choices' => [[
                        'message' => [
                            'content' => "```json\n{\"reasoning_summary\":\"Smart response\",\"citations\":[\"fleet:counts\"]}\n```",
                        ],
                    ]],
                    'usage' => ['total_tokens' => 42],
                ], 200),
        ]);

        $client = new OpenAiChatClient();
        $result = $client->generateJson([
            ['role' => 'user', 'content' => 'hello'],
        ]);

        $this->assertSame('Smart response', $result['payload']['reasoning_summary']);
        $this->assertSame('gpt-smart', $result['model']);

        $requests = Http::recorded()->values();
        $this->assertCount(2, $requests);
        $this->assertSame('json_schema', $requests[0][0]['response_format']['type']);
        $this->assertSame('json_object', $requests[1][0]['response_format']['type']);
    }

    public function test_generate_json_falls_back_to_secondary_model_when_primary_model_fails(): void
    {
        Config::set('services.openai.api_key', 'test-key');
        Config::set('services.openai.base_url', 'https://api.openai.com/v1');
        Config::set('services.openai.assistant_model', 'gpt-primary');
        Config::set('services.openai.assistant_fallback_model', 'gpt-fallback');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::sequence()
                ->push(['error' => ['message' => 'model not found']], 404)
                ->push(['error' => ['message' => 'model not found']], 404)
                ->push([
                    'model' => 'gpt-fallback',
                    'choices' => [[
                        'message' => [
                            'content' => '{"reasoning_summary":"Fallback model used"}',
                        ],
                    ]],
                    'usage' => ['total_tokens' => 15],
                ], 200),
        ]);

        $client = new OpenAiChatClient();
        $result = $client->generateJson([
            ['role' => 'user', 'content' => 'help me'],
        ]);

        $this->assertSame('Fallback model used', $result['payload']['reasoning_summary']);
        $this->assertSame('gpt-fallback', $result['model']);

        $requests = Http::recorded()->values();
        $this->assertCount(3, $requests);
        $this->assertSame('gpt-primary', $requests[0][0]['model']);
        $this->assertSame('gpt-primary', $requests[1][0]['model']);
        $this->assertSame('gpt-fallback', $requests[2][0]['model']);
    }
}
