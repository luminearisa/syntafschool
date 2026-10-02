<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_ai_providers', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('provider_type')->nullable();
            $table->string('base_url')->nullable();
            $table->string('credential_ref')->nullable();
            $table->string('data_region')->nullable();
            $table->boolean('is_private')->default(false);
            $table->boolean('approved_for_sensitive')->default(false);
            $table->string('status')->nullable();
            $table->integer('priority')->default(0);
            $table->json('config')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_ai_models', function (Blueprint $table): void
        {
            $table->id();
            $table->string('provider_code')->index()->nullable();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('model_type')->nullable();
            $table->integer('context_window')->default(0);
            $table->decimal('input_cost_per_1k', 10, 4)->nullable();
            $table->decimal('output_cost_per_1k', 10, 4)->nullable();
            $table->string('latency_class')->nullable();
            $table->string('data_classification_limit')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_ai_agents', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('agent_group')->nullable();
            $table->text('description')->nullable();
            $table->text('system_prompt')->nullable();
            $table->json('allowed_tools')->nullable();
            $table->json('required_permissions')->nullable();
            $table->string('model_code')->nullable();
            $table->integer('max_tokens')->default(0);
            $table->boolean('requires_human_review')->default(true);
            $table->string('status')->nullable();
            $table->integer('version')->default(1);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_ai_tools', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('tool_group')->nullable();
            $table->text('description')->nullable();
            $table->json('input_schema')->nullable();
            $table->json('output_schema')->nullable();
            $table->string('handler')->nullable();
            $table->string('required_permission')->nullable();
            $table->string('sensitivity')->nullable();
            $table->boolean('is_write')->default(false);
            $table->boolean('requires_confirmation')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_ai_tool_permissions', function (Blueprint $table): void
        {
            $table->id();
            $table->string('tool_code')->index()->nullable();
            $table->string('principal_type')->nullable();
            $table->string('principal_reference')->nullable();
            $table->string('scope')->nullable();
            $table->boolean('allowed')->default(true);
            $table->json('field_masking')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_ai_conversations', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('channel')->nullable();
            $table->json('context')->nullable();
            $table->foreignId('employee_id')->nullable()->index();
            $table->string('status')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('last_message_at')->nullable();
            $table->text('summary')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_ai_runs', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->index();
            $table->string('code')->unique();
            $table->string('agent_code')->index()->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreignId('employee_id')->nullable()->index();
            $table->string('intent')->index()->nullable();
            $table->decimal('intent_confidence', 8, 2)->nullable();
            $table->text('prompt')->nullable();
            $table->json('prompt_redacted')->nullable();
            $table->string('model_code')->nullable();
            $table->string('provider_code')->nullable();
            $table->string('status')->nullable();
            $table->text('response')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('tools_used')->nullable();
            $table->json('data_sources')->nullable();
            $table->json('calculation')->nullable();
            $table->json('assumptions')->nullable();
            $table->decimal('confidence', 8, 2)->nullable();
            $table->integer('tokens_in')->default(0);
            $table->integer('tokens_out')->default(0);
            $table->integer('latency_ms')->default(0);
            $table->decimal('cost', 18, 2)->nullable();
            $table->boolean('requires_approval')->default(false);
            $table->string('approval_status')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('idempotency_key')->index()->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_ai_tool_calls', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('run_id')->nullable()->index();
            $table->string('tool_code')->index()->nullable();
            $table->json('arguments')->nullable();
            $table->json('redacted_arguments')->nullable();
            $table->json('result')->nullable();
            $table->string('status')->nullable();
            $table->text('denial_reason')->nullable();
            $table->string('permission_checked')->nullable();
            $table->integer('latency_ms')->default(0);
            $table->dateTime('called_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_ai_recommendations', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('run_id')->nullable()->index();
            $table->string('recommendation_type')->nullable();
            $table->string('title')->nullable();
            $table->text('problem')->nullable();
            $table->json('evidence')->nullable();
            $table->json('options')->nullable();
            $table->text('expected_impact')->nullable();
            $table->decimal('estimated_cost', 18, 2)->nullable();
            $table->json('risks')->nullable();
            $table->json('dependencies')->nullable();
            $table->text('recommended_next_step')->nullable();
            $table->decimal('confidence', 8, 2)->nullable();
            $table->string('owner_role')->nullable();
            $table->string('status')->nullable();
            $table->foreignId('reviewed_by')->nullable()->index();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('human_decision')->nullable();
            $table->foreignId('strategic_action_id')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_ai_feedback', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('run_id')->nullable()->index();
            $table->foreignId('recommendation_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('feedback')->nullable();
            $table->text('comment')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_ai_usage_logs', function (Blueprint $table): void
        {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('feature')->nullable();
            $table->string('agent_code')->nullable();
            $table->foreignId('run_id')->nullable()->index();
            $table->string('provider_code')->nullable();
            $table->string('model_code')->nullable();
            $table->integer('tokens_in')->default(0);
            $table->integer('tokens_out')->default(0);
            $table->integer('latency_ms')->default(0);
            $table->decimal('cost', 18, 2)->nullable();
            $table->string('currency')->nullable();
            $table->dateTime('occurred_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_ai_cost_logs', function (Blueprint $table): void
        {
            $table->id();
            $table->string('period')->index()->nullable();
            $table->string('provider_code')->nullable();
            $table->string('model_code')->nullable();
            $table->string('feature')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->integer('requests')->default(0);
            $table->integer('tokens')->default(0);
            $table->decimal('cost', 18, 2)->nullable();
            $table->string('currency')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_ai_audit_logs', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('run_id')->nullable()->index();
            $table->string('action')->nullable();
            $table->string('actor_type')->nullable();
            $table->string('actor_reference')->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->boolean('approval_required')->default(false);
            $table->foreignId('approved_by')->nullable()->index();
            $table->dateTime('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('occurred_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_ai_policies', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('purpose')->nullable();
            $table->json('rules')->nullable();
            $table->json('applies_to')->nullable();
            $table->string('data_classification_limit')->nullable();
            $table->boolean('requires_human_review')->default(true);
            $table->json('prohibited_actions')->nullable();
            $table->string('status')->nullable();
            $table->date('effective_from')->nullable();
            $table->string('owner_role')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_ai_knowledge_documents', function (Blueprint $table): void
        {
            $table->id();
            $table->string('code')->unique();
            $table->string('title')->nullable();
            $table->string('category')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('sensitivity')->nullable();
            $table->string('content_hash')->nullable();
            $table->dateTime('indexed_at')->nullable();
            $table->string('status')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_ai_knowledge_chunks', function (Blueprint $table): void
        {
            $table->id();
            $table->foreignId('document_id')->nullable()->index();
            $table->integer('chunk_index')->default(0);
            $table->text('content')->nullable();
            $table->integer('tokens')->default(0);
            $table->string('embedding_ref')->nullable();
            $table->json('metadata')->nullable();
            $table->string('sensitivity')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_bias_monitoring', function (Blueprint $table): void
        {
            $table->id();
            $table->string('model_code')->index()->nullable();
            $table->string('period')->nullable();
            $table->string('context')->nullable();
            $table->string('group_dimension')->nullable();
            $table->decimal('selection_rate', 10, 4)->nullable();
            $table->decimal('reference_rate', 10, 4)->nullable();
            $table->decimal('disparity_ratio', 10, 4)->nullable();
            $table->string('flag')->nullable();
            $table->json('evidence')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('generated_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_ai_providers');
        Schema::dropIfExists('hr_ai_models');
        Schema::dropIfExists('hr_ai_agents');
        Schema::dropIfExists('hr_ai_tools');
        Schema::dropIfExists('hr_ai_tool_permissions');
        Schema::dropIfExists('hr_ai_conversations');
        Schema::dropIfExists('hr_ai_runs');
        Schema::dropIfExists('hr_ai_tool_calls');
        Schema::dropIfExists('hr_ai_recommendations');
        Schema::dropIfExists('hr_ai_feedback');
        Schema::dropIfExists('hr_ai_usage_logs');
        Schema::dropIfExists('hr_ai_cost_logs');
        Schema::dropIfExists('hr_ai_audit_logs');
        Schema::dropIfExists('hr_ai_policies');
        Schema::dropIfExists('hr_ai_knowledge_documents');
        Schema::dropIfExists('hr_ai_knowledge_chunks');
        Schema::dropIfExists('hr_bias_monitoring');
    }
};
