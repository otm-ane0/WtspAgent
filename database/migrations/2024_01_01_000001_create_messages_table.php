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
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            
            // Message Identification
            $table->string('message_id', 255)->unique()->comment('معرف الرسالة من واتساب');
            $table->string('user_phone', 20)->index()->comment('رقم هاتف المرسل');
            $table->string('whatsapp_profile_name', 100)->nullable()->comment('اسم المستخدم في واتساب');
            
            // Message Type and Content
            $table->enum('message_type', [
                'text',           // رسالة نصية
                'audio',          // رسالة صوتية
                'image',          // صورة
                'video',          // فيديو
                'document',       // مستند
                'location',       // موقع
                'contact',        // جهة اتصال
                'button',         // زر
                'interactive',    // تفاعلي
                'template',       // قالب
                'unknown',        // نوع غير معروف
            ])->default('text')->comment('نوع الرسالة');
            
            // Original Content
            $table->longText('content')->nullable()->comment('المحتوى الأصلي');
            $table->json('raw_payload')->nullable()->comment('البيانات الخام من واتساب');
            
            // Media Handling
            $table->string('media_url', 500)->nullable()->comment('رابط الوسائط');
            $table->string('media_mime_type', 100)->nullable()->comment('نوع ملف الوسائط');
            $table->string('media_caption', 500)->nullable()->comment('وصف الوسائط');
            $table->bigInteger('media_size')->nullable()->comment('حجم الملف بالبايت');
            
            // Processed Content
            $table->longText('processed_content')->nullable()->comment('المحتوى المعالج (مثل نص من صوت)');
            $table->enum('processing_status', [
                'received',     // تم الاستلام
                'processing',   // قيد المعالجة
                'processed',    // تمت المعالجة
                'failed',       // فشلت المعالجة
            ])->default('received')->comment('حالة المعالجة');
            
            // AI Response
            $table->longText('ai_response')->nullable()->comment('رد الذكاء الاصطناعي');
            $table->json('ai_metadata')->nullable()->comment('بيانات الذكاء الاصطناعي');
            $table->float('ai_confidence', 5, 2)->nullable()->comment('ثقة الذكاء الاصطناعي');
            
            // Order Relation
            $table->foreignId('order_id')->nullable()->constrained()->onDelete('set null')->comment('الطلب المرتبط');
            
            // Timestamps
            $table->timestamp('sent_at')->nullable()->comment('وقت الإرسال الفعلي');
            $table->timestamp('received_at')->nullable()->comment('وقت الاستلام');
            $table->timestamp('processed_at')->nullable()->comment('وقت المعالجة');
            $table->timestamps();
            
            // Indexes
            $table->index(['user_phone', 'created_at']);
            $table->index(['message_type', 'processing_status']);
            $table->index('sent_at');
        });
        
        // Add table comment
        DB::statement("ALTER TABLE messages COMMENT = 'جدول الرسائل - يسجل جميع الرسائل الواردة والصادرة'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
