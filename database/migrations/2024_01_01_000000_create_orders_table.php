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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            
            // Customer Information
            $table->string('customer_name', 255)->nullable()->comment('الاسم الكامل للعميل');
            $table->string('phone', 20)->index()->comment('رقم الهاتف');
            $table->string('city', 100)->nullable()->comment('المدينة');
            $table->text('address')->nullable()->comment('العنوان الكامل');
            
            // Order Details
            $table->string('product', 255)->nullable()->comment('اسم المنتج المطلوب');
            $table->integer('quantity')->default(1)->comment('الكمية');
            $table->decimal('price_per_unit', 10, 2)->nullable()->comment('السعر لكل وحدة');
            $table->decimal('total_price', 10, 2)->nullable()->comment('السعر الإجمالي');
            
            // Order Status and Flow
            $table->enum('status', [
                'new',              // طلب جديد
                'collecting_info',  // جاري جمع المعلومات
                'awaiting_confirm', // في انتظار التأكيد
                'confirmed',        // مؤكد
                'processing',       // قيد المعالجة
                'shipped',          // تم الشحن
                'delivered',        // تم التوصيل
                'cancelled',        // ملغي
            ])->default('new')->comment('حالة الطلب');
            
            // Conversation Tracking
            $table->json('collected_data')->nullable()->comment('البيانات المجمعة خطوة بخطوة');
            $table->json('conversation_context')->nullable()->comment('سياق المحادثة');
            $table->timestamp('last_interaction_at')->nullable()->comment('آخر تفاعل');
            
            // Metadata
            $table->text('notes')->nullable()->comment('ملاحظات إضافية');
            $table->string('source', 50)->default('whatsapp')->comment('مصدر الطلب');
            $table->string('assigned_to', 100)->nullable()->comment('مسؤول الطلب');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for performance
            $table->index(['status', 'created_at']);
            $table->index('last_interaction_at');
        });
        
        // Add table comment
        DB::statement("ALTER TABLE orders COMMENT = 'جدول الطلبات - يحتوي على جميع معلومات الطلبات'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
