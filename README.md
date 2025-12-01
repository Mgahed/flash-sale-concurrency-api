# Flash-Sale Checkout API

A high-performance, concurrency-safe Flash-Sale Checkout API built with Laravel 12, following the Service Pattern architecture.

## 📋 Table of Contents

- [Overview](#overview)
- [Architecture](#architecture)
- [Requirements](#requirements)
- [Installation](#installation)
- [Running the Application](#running-the-application)
- [Testing](#testing)
- [API Endpoints](#api-endpoints)
- [Concurrency & Idempotency Implementation](#concurrency--idempotency-implementation)
- [Logging & Metrics](#logging--metrics)

## Overview

This API handles flash-sale checkout scenarios with the following features:

- **Concurrency-safe**: Prevents overselling under heavy parallel requests
- **Hold System**: 2-minute temporary stock reservations
- **Idempotent Webhooks**: Handles duplicate and out-of-order payment webhooks
- **Background Processing**: Automatic expiry and release of holds
- **Performance Optimized**: Redis caching with distributed locks

### Core Invariants

1. **No Overselling**: Total sold + active holds + pending_payment holds ≤ stock_total
2. **Hold Uniqueness**: Each hold can only be used once
3. **Webhook Idempotency**: Same webhook (by idempotency_key) processed only once
4. **Stock Consistency**: DB is source of truth; cache is kept in sync
5. **Pending Payment Protection**: Orders awaiting payment webhooks still reserve stock

## Architecture

### Service Pattern

All business logic resides in service classes following single-responsibility principle:

```
app/Services/
├── ProductServiceInterface.php       # Product & stock operations
├── ProductService.php
├── HoldServiceInterface.php          # Hold creation & release
├── HoldService.php
├── OrderServiceInterface.php         # Order management
├── OrderService.php
├── PaymentWebhookServiceInterface.php # Webhook processing
└── PaymentWebhookService.php
```

### Data Flow

```
1. GET /api/products/{id}
   └─> ProductService::getProductWithStock()
       └─> Cache check → DB fallback → Update cache

2. POST /api/holds
   └─> HoldService::createHold()
       ├─> Acquire distributed lock
       ├─> Verify stock (cache + DB)
       ├─> Create hold record
       └─> Decrement cache atomically

3. POST /api/orders
   └─> OrderService::createOrderFromHold()
       ├─> Validate hold (not expired/used/released)
       ├─> Mark hold as used
       ├─> Create order (pending_payment)
       └─> Reconcile pending webhooks

4. POST /api/payments/webhook
   └─> PaymentWebhookService::handlePaymentWebhook()
       ├─> Check idempotency_key (unique constraint)
       ├─> If order exists: process payment
       ├─> If order missing: store as pending
       └─> On success: mark paid & increment stock_sold
           On failure: cancel order & release stock
```

### Database Schema

**products**
- id, name, price, stock_total, stock_sold, timestamps

**holds**
- id, product_id, qty, expires_at, used, released, created_at

**orders**
- id, hold_id, status (pending_payment|paid|cancelled), amount, timestamps

**webhook_logs**
- id, idempotency_key (unique), payload, status, processed_at

## Requirements

- PHP 8.2+
- Laravel 12
- MySQL 8.0+ (InnoDB)
- Redis (for caching and distributed locks)
- Composer

## Installation

### 1. Clone and Install Dependencies

```bash
git clone <repository-url> flashSaleCheckout
cd flashSaleCheckout
composer install
```

### 2. Environment Configuration

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` and configure:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=flashsalecheckout
DB_USERNAME=root
DB_PASSWORD=your_password

CACHE_DRIVER=database
QUEUE_CONNECTION=database

#REDIS_HOST=127.0.0.1
#REDIS_PORT=6379
```

### 3. Database Setup

```bash
# Create database
mysql -u root -p -e "CREATE DATABASE flashsalecheckout;"

# Run migrations
php artisan migrate

# Seed products
php artisan db:seed
```

## Running the Application

### Development Mode

```bash
# Terminal 1: Start server
php artisan serve

# Terminal 2: Start queue worker
php artisan queue:work --tries=3

```

## Testing

### Run All Tests

```bash
php artisan test
```

### Run Specific Test Suites

```bash
# Product tests
php artisan test --filter=ProductTest

# Hold tests
php artisan test --filter=HoldTest

# Order tests
php artisan test --filter=OrderTest

# Webhook tests (idempotency & out-of-order)
php artisan test --filter=WebhookTest

# Concurrency tests (oversell prevention)
php artisan test --filter=ConcurrencyTest

# Integration tests (full flow)
php artisan test --filter=IntegrationTest
```

### Key Tests

**Concurrency Tests** (`tests/Feature/ConcurrencyTest.php`)
- Parallel hold attempts at stock boundary
- Prevents overselling under high contention
- Database consistency verification

**Webhook Tests** (`tests/Feature/WebhookTest.php`)
- Idempotency (same key processed once)
- Out-of-order delivery (webhook before order)
- Success/failure payment handling

**Integration Tests** (`tests/Feature/IntegrationTest.php`)
- Complete flash-sale flow
- Multiple concurrent purchases
- Failed payment stock release

## Postman Collection

A comprehensive Postman collection is included for easy API testing:

# Import the collection
File: **[postman_collection.json](postman_collection.json)**

```bash

# Features:
- All 4 API endpoints
- Automated variable capture
- Pre-configured test scripts
- Complete purchase flow examples
- Idempotency testing
- Out-of-order webhook testing
```

See **[POSTMAN_GUIDE.md](POSTMAN_GUIDE.md)** for detailed usage instructions.

## API Endpoints

### 1. Get Product

**GET** `/api/products/{id}`

Response:
```json
{
  "id": 1,
  "name": "Flash Sale Widget",
  "price": "99.99",
  "stock_total": 100,
  "stock_sold": 25,
  "available_stock": 60
}
```

### 2. Create Hold

**POST** `/api/holds`

Request:
```json
{
  "product_id": 1,
  "qty": 5
}
```

Response (201):
```json
{
  "hold_id": 42,
  "expires_at": "2025-11-29T14:32:00Z"
}
```

Error (400):
```json
{
  "error": "Insufficient stock available"
}
```

### 3. Create Order

**POST** `/api/orders`

Request:
```json
{
  "hold_id": 42
}
```

Response (201):
```json
{
  "id": 123,
  "hold_id": 42,
  "status": "pending_payment",
  "amount": "499.95",
  "created_at": "2025-11-29T14:30:00Z"
}
```

Error (400):
```json
{
  "error": "Hold is expired and cannot be used"
}
```

### 4. Payment Webhook

**POST** `/api/payments/webhook`

Request:
```json
{
  "order_id": 123,
  "payment_status": "success",
  "idempotency_key": "unique-key-abc123"
}
```

Response (200):
```json
{
  "status": "success",
  "message": "Payment successful, order marked as paid",
  "order_id": 123
}
```

Duplicate webhook:
```json
{
  "status": "already_processed",
  "message": "Webhook already processed"
}
```

## Concurrency & Idempotency Implementation

### Concurrency Control

**1. Distributed Locks (Redis)**
```php
Cache::lock("lock:product:{$id}", 10)->block(3, function () {
    // Critical section
});
```

**2. Database Row Locks**
```php
DB::transaction(function () {
    $product = Product::where('id', $id)->lockForUpdate()->first();
    // Atomic operations
});
```

**3. Atomic Cache Operations**
```php
Cache::decrement($key, $qty);  // Atomic decrement
Cache::increment($key, $qty);  // Atomic increment
```

**4. Deadlock Retry with Exponential Backoff**
```php
for ($attempt = 0; $attempt < 3; $attempt++) {
    try {
        // DB operation
        break;
    } catch (QueryException $e) {
        if (isDeadlock($e) && $attempt < 2) {
            usleep(pow(2, $attempt) * 100000);
            continue;
        }
        throw $e;
    }
}
```

### Idempotency

**Webhook Idempotency via Unique Constraint**
```sql
-- webhook_logs table
UNIQUE KEY `idempotency_key` (`idempotency_key`)
```

```php
// Attempt to insert; if fails = already processed
WebhookLog::create([
    'idempotency_key' => $key,
    // ...
]);
```

**Out-of-Order Handling**
- Webhook arrives before order → store as `pending_order`
- Order creation → reconciles pending webhooks
- Eventual consistency guaranteed

### Hold Expiry

**Background Processing**
```php
// Scheduled every minute
Schedule::command('holds:expire')->everyMinute();

// Command finds expired holds
$expiredHolds = Hold::where('expires_at', '<=', now())
    ->where('used', false)
    ->where('released', false)
    ->get();

// Dispatch jobs with unique IDs
foreach ($expiredHolds as $hold) {
    ReleaseHoldJob::dispatch($hold->id);
}
```

**Job Uniqueness**
```php
public function uniqueId(): string {
    return "release_hold_{$this->holdId}";
}
```

## Logging & Metrics

### Structured Logging

Logs are written to `storage/logs/laravel.log` in JSON format:

```json
{
  "message": "Hold created successfully",
  "context": {
    "hold_id": 42,
    "product_id": 1,
    "qty": 5,
    "expires_at": "2025-11-29T14:32:00Z",
    "new_cached_stock": 95
  },
  "level": "info",
  "timestamp": "2025-11-29T14:30:00Z"
}
```

### Key Log Events

- `Hold created successfully` - Hold creation with stock changes
- `Cache miss for product stock` - Cache misses requiring DB fetch
- `Deadlock detected, retrying` - Concurrency contention
- `Webhook already processed (idempotency)` - Duplicate webhooks
- `Webhook received before order creation` - Out-of-order webhooks
- `Hold released successfully` - Stock restoration

### Viewing Logs

```bash
# Tail logs
tail -f storage/logs/laravel.log

# Filter for specific events
tail -f storage/logs/laravel.log | grep "Hold created"

# Use Laravel Pail for formatted output
php artisan pail
```

### Metrics to Monitor

- **holds_created**: Counter of successful hold creations
- **holds_expired**: Counter of expired holds released
- **oversell_prevented**: Counter of insufficient stock rejections
- **webhook_duplicates**: Counter of idempotent webhook hits
- **deadlock_retries**: Counter of deadlock retry attempts

## Demo Commands

### Quick Test Flow

```bash
# 1. Get product info
curl http://localhost:8000/api/products/1

# 2. Create a hold
curl -X POST http://localhost:8000/api/holds \
  -H "Content-Type: application/json" \
  -d '{"product_id": 1, "qty": 5}'

# Response: {"hold_id": 1, "expires_at": "..."}

# 3. Create order from hold
curl -X POST http://localhost:8000/api/orders \
  -H "Content-Type: application/json" \
  -d '{"hold_id": 1}'

# Response: {"id": 1, "status": "pending_payment", ...}

# 4. Simulate successful payment
curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Content-Type: application/json" \
  -d '{
    "order_id": 1,
    "payment_status": "success",
    "idempotency_key": "test-key-123"
  }'

# 5. Verify stock sold
curl http://localhost:8000/api/products/1
# available_stock should be reduced
```

### Testing Idempotency

```bash
# Send webhook twice with same key
curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Content-Type: application/json" \
  -d '{"order_id": 1, "payment_status": "success", "idempotency_key": "duplicate-test"}'

# Second call returns: {"status": "already_processed"}
curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Content-Type: application/json" \
  -d '{"order_id": 1, "payment_status": "success", "idempotency_key": "duplicate-test"}'
```

## Troubleshooting

### Cache Issues

```bash
# Clear cache
php artisan cache:clear
```

### Queue Not Processing

```bash
# Check failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all

# Clear queue
php artisan queue:clear
```

### Database Deadlocks

- Check logs for `Deadlock detected` messages
- Increase `MAX_RETRIES` in `HoldService`
- Review MySQL `innodb_lock_wait_timeout` setting

