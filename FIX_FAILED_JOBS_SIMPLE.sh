#!/bin/bash

echo "🔧 failed_jobs jadvalini yaratish (oddiy usul)..."
echo ""

# 1-usul: migrate orqali
echo "1️⃣ Migration orqali yaratish..."
php artisan migrate --force 2>&1 | grep -E "(failed_jobs|Nothing to migrate|DONE)" || echo "Migration bajarildi"

echo ""
echo "2️⃣ Tekshirish..."
php artisan tinker <<'EOF'
try {
    $count = DB::table('failed_jobs')->count();
    echo "✅ failed_jobs jadvali mavjud (yozuvlar: $count)\n";
} catch (Exception $e) {
    echo "❌ failed_jobs jadvali yo'q: " . $e->getMessage() . "\n";
    echo "SQL orqali yaratishga harakat qilamiz...\n";
    
    // SQL orqali yaratish
    DB::statement("CREATE TABLE IF NOT EXISTS failed_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        uuid VARCHAR(255) UNIQUE NOT NULL,
        connection TEXT NOT NULL,
        queue TEXT NOT NULL,
        payload LONGTEXT NOT NULL,
        exception LONGTEXT NOT NULL,
        failed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    echo "✅ SQL orqali yaratildi\n";
}
EOF

echo ""
echo "✅ Tugadi!"
