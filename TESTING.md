# WhatsApp AI Agent - Testing Guide

## 🧪 Testing Methods

### 1. Webhook Verification Test

```bash
# Test webhook verification endpoint
curl "http://localhost:8000/api/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=your_webhook_verify_token&hub.challenge=test_challenge"

# Expected response: test_challenge
```

### 2. Simulate Incoming Message

```bash
# Test with text message
curl -X POST http://localhost:8000/api/webhook/whatsapp \
  -H "Content-Type: application/json" \
  -d '{
    "object": "whatsapp_business_account",
    "entry": [{
      "id": "test_account_id",
      "changes": [{
        "value": {
          "messaging_product": "whatsapp",
          "metadata": {
            "display_phone_number": "15551234567",
            "phone_number_id": "12345678"
          },
          "contacts": [{
            "profile": {"name": "Test User"},
            "wa_id": "212600000001"
          }],
          "messages": [{
            "from": "212600000001",
            "id": "wamid.test123",
            "timestamp": "'$(date +%s)'",
            "text": {"body": "مرحبا"},
            "type": "text"
          }]
        },
        "field": "messages"
      }]
    }]
  }'
```

### 3. API Endpoints Test

```bash
# Health check
curl http://localhost:8000/api/health

# Test webhook endpoint
curl http://localhost:8000/api/webhook/whatsapp/test

# Get orders (requires auth)
curl -H "Authorization: Bearer YOUR_TOKEN" \
  http://localhost:8000/api/admin/orders

# Get stats
curl -H "Authorization: Bearer YOUR_TOKEN" \
  http://localhost:8000/api/admin/stats
```

### 4. Artisan Commands Test

```bash
# Cleanup expired sessions
php artisan sessions:cleanup

# Test with verbose output
php artisan sessions:cleanup --hours=1 -v

# View routes
php artisan route:list

# Test config
php artisan config:cache
php artisan config:clear
```

### 5. PHPUnit Tests

```bash
# Run all tests
php artisan test

# Run specific test
php artisan test --filter=OrderTest

# Run with coverage
php artisan test --coverage
```

### 6. Database Tests

```bash
# Seed test data
php artisan db:seed --class=ProductCatalogSeeder

# Check database connection
php artisan db:monitor

# Run migrations fresh
php artisan migrate:fresh --seed
```

### 7. Queue Tests

```bash
# Start queue worker
php artisan queue:work --queue=default --sleep=3 --tries=3

# Check failed jobs
php artisan queue:failed

# Retry failed job
php artisan queue:retry all
```

### 8. Log Inspection

```bash
# Watch WhatsApp logs
tail -f storage/logs/whatsapp.log

# Watch AI service logs
tail -f storage/logs/ai.log

# Watch order logs
tail -f storage/logs/orders.log

# Watch Laravel logs
tail -f storage/logs/laravel.log
```

### 9. Manual WhatsApp Testing

Send these messages to test flow:

| Step | Input | Expected Response |
|------|-------|-------------------|
| 1 | مرحبا | Greeting with options |
| 2 | طلب جديد | Ask for product |
| 3 | الزيتون | Show product info, ask for quantity |
| 4 | 2 | Ask for name |
| 5 | احمد | Ask for city |
| 6 | الدار البيضاء | Ask for address |
| 7 | شارع الحسن الثاني | Show order summary |
| 8 | ايه | Confirm order |

### 10. Error Scenario Tests

```bash
# Test with invalid JSON
curl -X POST http://localhost:8000/api/webhook/whatsapp \
  -H "Content-Type: application/json" \
  -d '{invalid json}'

# Test with empty payload
curl -X POST http://localhost:8000/api/webhook/whatsapp \
  -H "Content-Type: application/json" \
  -d '{}'

# Test unauthorized access
curl http://localhost:8000/api/admin/orders
# Should return 401
```

### 11. NVIDIA API Test

```php
// In tinker
$ai = app(\App\Services\AIService::class);
$result = $ai->generateResponse('مرحبا', []);
dd($result);

// Test STT (requires audio URL)
$result = $ai->transcribeAudio('https://example.com/audio.ogg', []);
dd($result);

// Test Vision (requires image URL)
$result = $ai->analyzeImage('https://example.com/image.jpg', null, []);
dd($result);

// Health check
$ai->healthCheck();
```

### 12. Service Testing with Tinker

```bash
php artisan tinker
```

```php
// Test WhatsApp service
$wa = app(\App\Services\WhatsAppService::class);
$wa->sendMessage('212600000001', 'Test message');

// Test order service
$orderService = app(\App\Services\OrderManagementService::class);
$orderService->getStatistics();

// Test conversation
$conv = \App\Models\Conversation::findOrCreateByPhone('212600000001', 'Test');
$conv->transitionTo('greeting');

// Create test order
$order = \App\Models\Order::create([
    'customer_name' => 'Test User',
    'phone' => '212600000001',
    'city' => 'Casablanca',
    'address' => 'Test Address',
    'product' => 'الزيتون',
    'quantity' => 2,
    'status' => 'confirmed'
]);
```

### 13. Performance Tests

```bash
# Using Apache Bench (ab)
ab -n 100 -c 10 http://localhost:8000/api/health

# Using curl for timing
curl -o /dev/null -s -w "Total time: %{time_total}s\n" \
  http://localhost:8000/api/health
```

### 14. Webhook Response Time Test

```bash
# Time the webhook response
time curl -X POST http://localhost:8000/api/webhook/whatsapp \
  -H "Content-Type: application/json" \
  -d @test-message.json
```

### 15. Load Test (using k6)

```javascript
// load-test.js
import http from 'k6/http';
import { check } from 'k6';

export let options = {
    stages: [
        { duration: '30s', target: 10 },
        { duration: '1m', target: 50 },
        { duration: '30s', target: 0 },
    ],
};

export default function () {
    let payload = JSON.stringify({
        object: "whatsapp_business_account",
        entry: [{
            changes: [{
                value: {
                    messages: [{
                        from: "212600000001",
                        id: `wamid.${Date.now()}`,
                        timestamp: Math.floor(Date.now() / 1000).toString(),
                        text: { body: "مرحبا" },
                        type: "text"
                    }]
                }
            }]
        }]
    });

    let res = http.post('http://localhost:8000/api/webhook/whatsapp', payload, {
        headers: { 'Content-Type': 'application/json' },
    });

    check(res, {
        'status is 200': (r) => r.status === 200,
        'response success': (r) => r.json('success') === true,
    });
}
```

Run: `k6 run load-test.js`

## 📋 Testing Checklist

- [ ] Webhook verification works
- [ ] Text messages are processed
- [ ] Audio messages trigger STT
- [ ] Images trigger vision analysis
- [ ] Order flow works end-to-end
- [ ] Confirmation required before order confirmation
- [ ] Database records are created
- [ ] AI responses are in Darija
- [ ] Admin endpoints work with auth
- [ ] Logs are written correctly
- [ ] Error handling works
- [ ] Session cleanup works
- [ ] Queue processing works

## 🐛 Debugging Tips

1. **Check logs first**: `tail -f storage/logs/*.log`
2. **Verify config**: `php artisan config:show services`
3. **Test services individually** using tinker
4. **Check database**: `php artisan db:monitor`
5. **Clear caches**: `php artisan cache:clear`
