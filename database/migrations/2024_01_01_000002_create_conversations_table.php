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
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            
            // User Identification
            $table->string('user_phone', 20)->unique()->comment('رقم الهاتف الفريد');
            $table->string('profile_name', 100)->nullable()->comment('اسم المستخدم');
            
            // Conversation State
            $table->enum('current_state', [
                'idle',              // انتظار
                'greeting',          // ترحيب
                'product_inquiry',   // استفسار عن منتج
                'collecting_info',   // جمع معلومات الطلب
                'awaiting_confirm',    // انتظار تأكيد
                'order_confirmed',   // الطلب مؤكد
                'support',           // دعم فني
                'complaint',         // شكوى
            ])->default('idle')->comment('الحالة الحالية للمحادثة');
            
            // Active Order Reference
            $table->foreignId('active_order_id')->nullable()->constrained('orders')->onDelete('set null')->comment('الطلب النشط');
            
            // Context and History
            $table->json('context_data')->nullable()->comment('بيانات السياق الحالي');
            $table->integer('message_count')->default(0)->comment('عدد الرسائل');
            $table->integer('order_count')->default(0)->comment('عدد الطلبات');
            
            // Session Management
            $table->timestamp('session_started_at')->nullable()->comment('بدء الجلسة');
            $table->timestamp('session_expires_at')->nullable()->comment('انتهاء الجلسة');
            $table->boolean('is_active')->default(true)->comment('نشط/غير نشط');
            
            // Metadata
            $table->string('first_message_source', 50)->nullable()->comment('مصدر أول رسالة');
            $table->string('language_preference', 10)->default('ar')->comment('تفضيل اللغة');
            
            // Timestamps
            $table->timestamp('last_message_at')->nullable()->comment('آخر رسالة');
            $table->timestamps();
            
            // Indexes
            $table->index('current_state');
            $table->index('is_active');
            $table->index('last_message_at');
        });
        
        // Add table comment
        DB::statement("ALTER TABLE conversations COMMENT = 'جدول المحادثات - يتتبع حالة المحادثات مع كل مستخدم'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
