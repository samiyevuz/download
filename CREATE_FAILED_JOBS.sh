#!/bin/bash

echo "🔧 failed_jobs jadvalini yaratish..."
echo ""

# Oddiy SQL orqali yaratish
php artisan tinker <<'EOF'
try {
    if (!Schema::hasTable('failed_jobs')) {
        Schema::create('failed_jobs', function($table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
        echo "✅ failed_jobs jadvali yaratildi\n";
    } else {
        echo "✅ failed_jobs jadvali allaqachon mavjud\n";
    }
} catch (Exception $e) {
    echo "❌ Xato: " . $e->getMessage() . "\n";
}
EOF

echo ""
echo "✅ Tugadi!"
