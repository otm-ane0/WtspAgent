# WhatsApp AI Agent - Setup Guide

## 📋 Prerequisites

- PHP 8.2+
- MySQL 8.0+
- Composer
- Node.js & NPM (optional, for frontend)
- Web server (Apache/Nginx)
- SSL Certificate (required for webhooks)

## 🚀 Installation Steps

### 1. Clone and Install Dependencies

```bash
cd /var/www
git clone <repository-url> wa-agent
cd wa-agent
composer install --no-dev --optimize-autoloader
```

### 2. Environment Configuration

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` with your credentials:
- Database settings
- NVIDIA API key
- Meta WhatsApp credentials

### 3. Database Setup

```bash
php artisan migrate --force
php artisan db:seed --class=ProductCatalogSeeder
```

### 4. Configure Webhooks

#### Meta Developer Portal:
1. Go to [developers.facebook.com](https://developers.facebook.com)
2. Create app → Business → WhatsApp
3. Configure webhook URL: `https://your-domain.com/api/webhook/whatsapp`
4. Set verify token (same as META_VERIFY_TOKEN in .env)
5. Subscribe to messages events

### 5. Set Up Queue Worker

Create supervisor config `/etc/supervisor/conf.d/wa-agent-worker.conf`:

```ini
[program:wa-agent-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/wa-agent/artisan queue:work --sleep=3 --tries=3
directory=/var/www/wa-agent
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/wa-agent/storage/logs/worker.log
```

Enable:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start wa-agent-worker:*
```

### 6. Web Server Configuration

#### Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name your-domain.com;
    root /var/www/wa-agent/public;

    ssl_certificate /path/to/fullchain.pem;
    ssl_certificate_key /path/to/privkey.pem;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### 7. Directory Permissions

```bash
chown -R www-data:www-data /var/www/wa-agent
chmod -R 755 /var/www/wa-agent/storage
chmod -R 755 /var/www/wa-agent/bootstrap/cache
```

### 8. Schedule Cleanup Task

Add to crontab:
```bash
* * * * * cd /var/www/wa-agent && php artisan schedule:run >> /dev/null 2>&1
```

## 🔧 Configuration

### Product Catalog

Edit `storage/app/products.json`:

```json
[
    {
        "name": "الزيتون",
        "price": 35,
        "unit": "كيلو",
        "description": "زيتون مغربي أصلي",
        "category": "مواد غذائية"
    }
]
```

### AI Prompts

System prompts are in `app/Services/AIService.php`. Customize `getDarijaSystemPrompt()` for your store's personality.

## 📊 Admin Dashboard

Access admin endpoints at:
- `GET /api/admin/stats` - Dashboard statistics
- `GET /api/admin/orders` - List orders
- `GET /api/admin/orders/{id}` - Order details
- `POST /api/admin/orders/{id}/confirm` - Confirm order
- `POST /api/admin/orders/{id}/cancel` - Cancel order

## 🧪 Testing

### Test Webhook Verification:
```bash
curl "https://your-domain.com/api/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=YOUR_TOKEN&hub.challenge=CHALLENGE_TOKEN"
```

### Test Message Sending:
```bash
curl -X POST https://your-domain.com/api/webhook/whatsapp \
  -H "Content-Type: application/json" \
  -d '{
    "object": "whatsapp_business_account",
    "entry": [{
      "changes": [{
        "value": {
          "messages": [{
            "id": "test123",
            "from": "212600000000",
            "type": "text",
            "text": {"body": "مرحبا"}
          }]
        }
      }]
    }]
  }'
```

## 🐛 Troubleshooting

### Check Logs
```bash
tail -f storage/logs/laravel.log
tail -f storage/logs/whatsapp.log
tail -f storage/logs/ai.log
```

### Common Issues

1. **Webhook verification fails**
   - Verify META_VERIFY_TOKEN matches
   - Check SSL certificate is valid
   - Ensure URL is publicly accessible

2. **Messages not received**
   - Confirm webhook is subscribed in Meta dashboard
   - Check `phone_number_id` is correct
   - Verify access token permissions

3. **AI responses not working**
   - Test NVIDIA API key: `curl -H "Authorization: Bearer $NVIDIA_API_KEY" $NVIDIA_API_URL/v1/models`
   - Check rate limits
   - Verify model names are correct

4. **Database errors**
   - Ensure MySQL version >= 8.0
   - Check collation: `utf8mb4_unicode_ci`
   - Verify connection credentials

## 🔒 Security

- Keep `.env` file secure and never commit it
- Rotate API keys regularly
- Use HTTPS for all webhook endpoints
- Implement rate limiting in production
- Sanitize all user inputs
- Validate file uploads strictly

## 📞 Support

For issues and feature requests, contact:
- Email: support@yourstore.ma
- WhatsApp: +212600000000

---

## 📝 License

This project is proprietary software. All rights reserved.
