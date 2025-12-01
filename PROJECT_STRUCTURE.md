# Project Structure

## Directory Overview

```
flashSaleCheckout/
├── app/
│   ├── Console/
│   │   └── Commands/
│   │       └── ExpireHoldsCommand.php          # Finds and releases expired holds
│   ├── Http/
│   │   └── Controllers/
│   │       └── Api/
│   │           ├── HoldController.php          # Hold creation endpoint
│   │           ├── OrderController.php         # Order creation endpoint
│   │           ├── PaymentWebhookController.php # Webhook processing endpoint
│   │           └── ProductController.php       # Product info endpoint
│   ├── Jobs/
│   │   └── ReleaseHoldJob.php                  # Async hold release job
│   ├── Models/
│   │   ├── Hold.php                            # Hold model with validation logic
│   │   ├── Order.php                           # Order model
│   │   ├── Product.php                         # Product model with stock calculation
│   │   └── WebhookLog.php                      # Webhook log model
│   ├── Providers/
│   │   ├── AppServiceProvider.php              # Default service provider
│   │   └── ServiceBindingProvider.php          # Service interface bindings
│   └── Services/
│       ├── HoldService.php                     # Hold business logic implementation
│       ├── HoldServiceInterface.php            # Hold service contract
│       ├── OrderService.php                    # Order business logic implementation
│       ├── OrderServiceInterface.php           # Order service contract
│       ├── PaymentWebhookService.php           # Webhook business logic implementation
│       ├── PaymentWebhookServiceInterface.php  # Webhook service contract
│       ├── ProductService.php                  # Product business logic implementation
│       └── ProductServiceInterface.php         # Product service contract
├── bootstrap/
│   ├── app.php                                 # Application bootstrap
│   └── providers.php                           # Service provider registration
├── database/
│   ├── factories/
│   │   ├── ProductFactory.php                  # Product factory for testing
│   │   └── UserFactory.php                     # User factory
│   ├── migrations/
│   │   ├── 2024_01_01_000001_create_products_table.php
│   │   ├── 2024_01_01_000002_create_holds_table.php
│   │   ├── 2024_01_01_000003_create_orders_table.php
│   │   └── 2024_01_01_000004_create_webhook_logs_table.php
│   └── seeders/
│       ├── DatabaseSeeder.php                  # Main seeder
│       └── ProductSeeder.php                   # Seeds flash sale products
├── routes/
│   ├── api.php                                 # API routes definition
│   ├── console.php                             # Console commands & scheduler
│   └── web.php                                 # Web routes (not used)
├── tests/
│   ├── Feature/
│   │   ├── ConcurrencyTest.php                 # Concurrency & oversell tests
│   │   ├── HoldTest.php                        # Hold creation & expiry tests
│   │   ├── IntegrationTest.php                 # Full flow integration tests
│   │   ├── OrderTest.php                       # Order creation tests
│   │   ├── ProductTest.php                     # Product endpoint tests
│   │   └── WebhookTest.php                     # Webhook idempotency tests
│   └── TestCase.php                            # Base test case
├── ARCHITECTURE.md                             # Detailed architecture docs
├── DEMO.md                                     # Demo guide with curl examples
├── README.md                                   # Main documentation
├── phpunit.xml                                 # PHPUnit configuration
└── setup.sh                                    # Quick setup script
```

## Key Components

### Services (Business Logic Layer)

| Service | Responsibility | Key Methods |
|---------|---------------|-------------|
| `ProductService` | Product & stock management | `getProductWithStock()`, `getAvailableStock()`, `refreshCachedStock()` |
| `HoldService` | Hold creation & release | `createHold()`, `releaseHold()`, `getExpiredHolds()` |
| `OrderService` | Order management | `createOrderFromHold()`, `markOrderAsPaid()`, `cancelOrder()` |
| `PaymentWebhookService` | Webhook processing | `handlePaymentWebhook()`, `reconcilePendingWebhooks()` |

### Controllers (API Layer)

| Controller | Endpoint | Methods |
|------------|----------|---------|
| `ProductController` | `/api/products/{id}` | `show()` |
| `HoldController` | `/api/holds` | `store()` |
| `OrderController` | `/api/orders` | `store()` |
| `PaymentWebhookController` | `/api/payments/webhook` | `handle()` |

### Models (Data Layer)

| Model | Table | Key Relationships |
|-------|-------|-------------------|
| `Product` | `products` | `hasMany(Hold)` |
| `Hold` | `holds` | `belongsTo(Product)`, `hasOne(Order)` |
| `Order` | `orders` | `belongsTo(Hold)` |
| `WebhookLog` | `webhook_logs` | None |

### Background Processing

| Component | Schedule | Purpose |
|-----------|----------|---------|
| `ExpireHoldsCommand` | Every minute | Finds expired holds and dispatches release jobs |
| `ReleaseHoldJob` | On-demand | Releases a hold and restores stock |

## Data Flow

### Request Flow

```
HTTP Request
    ↓
Middleware (validation, rate limiting, etc.)
    ↓
Controller (orchestration)
    ↓
Service (business logic)
    ↓
Model (data access)
    ↓
Database / Cache
```

### Service Dependencies

```
ProductService (no dependencies)
    ↑
HoldService (depends on ProductService)
    ↑
OrderService (depends on HoldService)
    ↑
PaymentWebhookService (depends on OrderService)
```

## Configuration Files

| File | Purpose |
|------|---------|
| `.env` | Environment-specific configuration |
| `config/cache.php` | Cache driver configuration |
| `config/database.php` | Database connection settings |
| `config/queue.php` | Queue driver configuration |
| `phpunit.xml` | Test environment configuration |

## Cache Keys Reference

| Key Pattern | Example | Purpose | TTL |
|-------------|---------|---------|-----|
| `product:{id}:available_stock` | `product:1:available_stock` | Cached available stock | 5 min |
| `lock:product:{id}` | `lock:product:1` | Distributed lock for product | 10 sec |
| `lock:hold:{id}` | `lock:hold:42` | Distributed lock for hold release | 10 sec |

## Database Schema

### products
```sql
CREATE TABLE products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  stock_total INT UNSIGNED NOT NULL,
  stock_sold INT UNSIGNED DEFAULT 0,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX (id, stock_total, stock_sold)
);
```

### holds
```sql
CREATE TABLE holds (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  qty INT UNSIGNED NOT NULL,
  expires_at TIMESTAMP NOT NULL,
  used BOOLEAN DEFAULT FALSE,
  released BOOLEAN DEFAULT FALSE,
  created_at TIMESTAMP NOT NULL,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  INDEX (product_id, expires_at),
  INDEX (expires_at, used, released)
);
```

### orders
```sql
CREATE TABLE orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hold_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending_payment', 'paid', 'cancelled') DEFAULT 'pending_payment',
  amount DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (hold_id) REFERENCES holds(id) ON DELETE CASCADE,
  INDEX (status)
);
```

### webhook_logs
```sql
CREATE TABLE webhook_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  idempotency_key VARCHAR(255) NOT NULL UNIQUE,
  payload JSON NOT NULL,
  status ENUM('processed', 'pending_order') DEFAULT 'processed',
  processed_at TIMESTAMP NOT NULL,
  INDEX (status, processed_at)
);
```

## API Endpoints Summary

| Method | Endpoint | Purpose | Auth |
|--------|----------|---------|------|
| GET | `/api/products/{id}` | Get product with available stock | None |
| POST | `/api/holds` | Create temporary stock reservation | None |
| POST | `/api/orders` | Create order from hold | None |
| POST | `/api/payments/webhook` | Process payment webhook | None |

## Test Coverage

| Test Suite | File | Focus Area |
|------------|------|------------|
| Product Tests | `ProductTest.php` | Product info, stock calculation |
| Hold Tests | `HoldTest.php` | Hold creation, validation, expiry |
| Order Tests | `OrderTest.php` | Order creation, hold validation |
| Webhook Tests | `WebhookTest.php` | Idempotency, out-of-order handling |
| Concurrency Tests | `ConcurrencyTest.php` | Oversell prevention, race conditions |
| Integration Tests | `IntegrationTest.php` | Full purchase flow |

## Deployment Checklist

- [ ] Configure `.env` with production settings
- [ ] Set `APP_ENV=production` and `APP_DEBUG=false`
- [ ] Configure MySQL database connection
- [ ] Configure Redis cache connection
- [ ] Run `composer install --optimize-autoloader --no-dev`
- [ ] Run `php artisan config:cache`
- [ ] Run `php artisan route:cache`
- [ ] Run `php artisan migrate --force`
- [ ] Run `php artisan db:seed --force`
- [ ] Set up Supervisor for queue workers
- [ ] Add cron job for scheduler: `* * * * * cd /path && php artisan schedule:run`
- [ ] Configure log rotation
- [ ] Set up monitoring (New Relic, DataDog, etc.)
- [ ] Configure backups for database
- [ ] Set up SSL/TLS certificates
- [ ] Configure firewall rules

## Monitoring Checklist

- [ ] Monitor queue depth and failed jobs
- [ ] Monitor database connection pool
- [ ] Monitor Redis memory usage
- [ ] Monitor API response times
- [ ] Set up alerts for high error rates
- [ ] Monitor cache hit ratio
- [ ] Monitor deadlock frequency
- [ ] Set up log aggregation (ELK, Splunk, etc.)

## Common Commands

```bash
# Development
php artisan serve                    # Start dev server
php artisan queue:work               # Process queue jobs
php artisan schedule:work            # Run scheduler (dev)

# Database
php artisan migrate                  # Run migrations
php artisan migrate:fresh --seed    # Fresh database with seeds
php artisan db:seed                  # Run seeders only

# Cache
php artisan cache:clear              # Clear cache
php artisan config:cache             # Cache config (production)
php artisan route:cache              # Cache routes (production)

# Queue
php artisan queue:work --tries=3     # Process queue with retries
php artisan queue:failed             # Show failed jobs
php artisan queue:retry all          # Retry all failed jobs

# Testing
php artisan test                     # Run all tests
php artisan test --filter=ProductTest  # Run specific test
php artisan test --testsuite=Concurrency # Run concurrency tests

# Custom Commands
php artisan holds:expire             # Manually expire holds

# Logs
php artisan pail                     # Tail logs with formatting
tail -f storage/logs/laravel.log     # Standard log tail
```

## Useful Development Tools

- **Laravel Pail**: Real-time log viewer (`php artisan pail`)
- **Laravel Tinker**: REPL for Laravel (`php artisan tinker`)
- **Laravel Telescope**: Debugging assistant (optional)
- **Redis CLI**: Redis debugging (`redis-cli`)
- **MySQL Workbench**: Database GUI
- **Postman/Insomnia**: API testing
- **Apache Bench (ab)**: Load testing

## Environment Variables Reference

```env
# Application
APP_NAME=FlashSaleCheckout
APP_ENV=local|production
APP_DEBUG=true|false
APP_URL=http://localhost

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=flash_sale_checkout
DB_USERNAME=root
DB_PASSWORD=

# Cache
CACHE_DRIVER=redis|database|file
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Queue
QUEUE_CONNECTION=database|redis|sync

# Logging
LOG_CHANNEL=stack
LOG_LEVEL=debug|info|warning|error
```

