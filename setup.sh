#!/bin/bash

echo "🚀 Flash-Sale Checkout API - Setup Script"
echo "=========================================="

# Check if .env exists
if [ ! -f .env ]; then
    echo "📝 Creating .env file..."
    cp .env.example .env
    echo "✅ .env file created"
else
    echo "✅ .env file already exists"
fi

# Install dependencies
echo ""
echo "📦 Installing Composer dependencies..."
composer install --no-interaction

# Generate application key
echo ""
echo "🔑 Generating application key..."
php artisan key:generate --no-interaction

# Check if database is configured
echo ""
echo "📊 Database Setup"
echo "-----------------"
echo "Please ensure your .env file has correct database credentials:"
echo "  DB_CONNECTION=mysql"
echo "  DB_HOST=127.0.0.1"
echo "  DB_DATABASE=flash_sale_checkout"
echo "  DB_USERNAME=your_username"
echo "  DB_PASSWORD=your_password"
echo ""
read -p "Press Enter once database is configured and created..."

# Run migrations
echo ""
echo "🗄️  Running migrations..."
php artisan migrate --force

# Seed database
echo ""
echo "🌱 Seeding database..."
php artisan db:seed --force

# Clear and cache config
echo ""
echo "⚙️  Optimizing configuration..."
php artisan config:clear
php artisan cache:clear

# Display success message
echo ""
echo "✅ Setup Complete!"
echo "=================="
echo ""
echo "📋 Next Steps:"
echo "1. Start the server:       php artisan serve"
echo "2. Start queue worker:     php artisan queue:work --tries=3"
echo "3. Start scheduler:        php artisan schedule:work"
echo "4. Run tests:              php artisan test"
echo ""
echo "📖 API Endpoints:"
echo "  GET  /api/products/{id}       - Get product info"
echo "  POST /api/holds                - Create hold"
echo "  POST /api/orders               - Create order"
echo "  POST /api/payments/webhook     - Payment webhook"
echo ""
echo "🧪 Run Concurrency Tests:"
echo "  php artisan test --testsuite=Concurrency"
echo ""
echo "Happy coding! 🎉"

