<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_interactions', function (Blueprint $table) {
            $table->id();
            
            // References
            $table->foreignId('message_id')->constrained()->onDelete('cascade')->comment('الرسالة المرتبطة');
            $table->foreignId('order_id')->nullable()->constrained()->onDelete('set null')->comment('الطلب المرتبط');
            
            // AI Model Information
            $table->string('model_name', 100)->comment('اسم نموذج الذكاء الاصطناعي');
            $table->string('service_type', 50)->comment('نوع الخدمة: llm, stt, vision');
            
            // Input/Output
            $table->longText('input_prompt')->comment('المدخل');
            $table->longText('output_response')->comment('المخرج');
            $table->json('system_prompt')->nullable()->comment('تعليمات النظام');
            
            // Performance Metrics
            $table->integer('input_tokens')->nullable()->comment('عدد توكنات المدخل');
            $table->integer('output_tokens')->nullable()->comment('عدد توكنات المخرج');
            $table->integer('total_tokens')->nullable()->comment('إجمالي التوكنات');
            $table->float('latency_ms', 10, 2)->nullable()->comment('وقت الاستجابة بالمللي ثانية');
            
            // Cost Tracking
            $table->decimal('cost_usd', 10, 6)->nullable()->comment('التكلفة بالدولار');
            
            // Status and Error Tracking
            $table->enum('status', [
                'success',
                'error',
                'timeout',
                'rate_limited',
            ])->default('success')->comment('حالة الطلب');
            $table->text('error_message')->nullable()->comment('رسالة الخطأ');
            $table->integer('retry_count')->default(0)->comment('عدد المحاولات');
            
            // Metadata
            $table->json('metadata')->nullable()->comment('بيانات إضافية');
            
            // Timestamps
            $table->timestamp('processed_at')->comment('وقت المعالجة');
            $table->timestamps();
            
            // Indexes
            $table->index(['model_name', 'created_at']);
            $table->index('service_type');
            $table->index('status');
        });
        
        // Add table comment
        DB::statement("ALTER TABLE ai_interactions COMMENT = 'جدول تفاعلات الذكاء الاصطناعي - لتتبع استخدام API'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_interactions');
    }
};
