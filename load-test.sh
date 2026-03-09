#!/bin/sh
COUNT=${1:-100}
BATCH=$(date +%s)

echo "=== Multi-Source Load Test ==="
echo "Batch: $BATCH | $COUNT records x 3 sources = $((COUNT * 3)) total"
echo ""

php /var/www/artisan source:produce banking   $COUNT $BATCH &
php /var/www/artisan source:produce ecommerce $COUNT $BATCH &
php /var/www/artisan source:produce payroll   $COUNT $BATCH &

php /var/www/artisan verify:processing $((COUNT * 3)) $BATCH
