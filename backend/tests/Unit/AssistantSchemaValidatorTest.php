<?php

namespace Tests\Unit;

use App\Domain\Assistant\AssistantSchemaValidator;
use Tests\TestCase;

class AssistantSchemaValidatorTest extends TestCase
{
    public function test_validator_accepts_wrapped_answer_payload(): void
    {
        $validator = new AssistantSchemaValidator();

        $validated = $validator->validate([
            'answer' => [
                'reasoning_summary' => 'No active high-confidence threat.',
                'known_facts' => [
                    ['statement' => 'Risk score is 0.', 'citations' => ['risk:latest']],
                ],
                'inferences' => [],
                'confidence_score' => 0.58,
                'risk_level' => 'low',
                'recommended_actions' => [],
                'requires_human_review' => true,
                'citations' => ['risk:latest'],
            ],
        ]);

        $this->assertSame('No active high-confidence threat.', $validated['reasoning_summary']);
        $this->assertSame('No active high-confidence threat.', $validated['why_this_action']);
        $this->assertFalse($validated['rollback_possible']);
        $this->assertFalse($validated['approval_required']);
        $this->assertSame([], $validated['context_gaps']);
    }

    public function test_validator_coerces_object_and_json_string_collections(): void
    {
        $validator = new AssistantSchemaValidator();

        $validated = $validator->validate([
            'reasoning_summary' => 'Telemetry is low risk.',
            'known_facts' => json_encode([
                'first' => [
                    'statement' => 'Risk score is 0.',
                    'citations' => '["risk:latest"]',
                ],
            ], JSON_THROW_ON_ERROR),
            'inferences' => [
                'primary' => [
                    'statement' => 'No active finding requires response.',
                    'confidence' => '0.66',
                    'citations' => ['finding:none'],
                ],
            ],
            'confidence_score' => '0.58',
            'risk_level' => 'low',
            'recommended_actions' => '{"first":{"action_type":"re_run_inventory","target_scope":{"type":"device","id":"abc"},"arguments":{"reason":"refresh"},"approval_required":false}}',
            'requires_human_review' => true,
            'citations' => '{"first":"device:abc"}',
        ]);

        $this->assertCount(1, $validated['known_facts']);
        $this->assertSame(['risk:latest'], $validated['known_facts'][0]['citations']);
        $this->assertCount(1, $validated['inferences']);
        $this->assertSame(0.66, $validated['inferences'][0]['confidence']);
        $this->assertCount(1, $validated['recommended_actions']);
        $this->assertSame('re_run_inventory', $validated['recommended_actions'][0]['action_type']);
        $this->assertSame(['device:abc'], $validated['citations']);
    }

    public function test_validator_drops_blank_collection_items(): void
    {
        $validator = new AssistantSchemaValidator();

        $validated = $validator->validate([
            'reasoning_summary' => 'Telemetry is low risk.',
            'known_facts' => [
                ['statement' => '', 'citations' => []],
                ['statement' => 'Risk score is 0.', 'citations' => ['risk:latest']],
            ],
            'inferences' => [
                ['statement' => '', 'confidence' => 0.5, 'citations' => []],
            ],
            'confidence_score' => 0.58,
            'risk_level' => 'low',
            'recommended_actions' => [
                ['action_type' => '', 'target_scope' => [], 'arguments' => []],
            ],
            'requires_human_review' => true,
            'citations' => ['risk:latest'],
        ]);

        $this->assertCount(1, $validated['known_facts']);
        $this->assertCount(0, $validated['inferences']);
        $this->assertCount(0, $validated['recommended_actions']);
    }
}
