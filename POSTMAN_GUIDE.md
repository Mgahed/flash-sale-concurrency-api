# Postman Collection Guide

## 📦 Import the Collection

### Method 1: Import File
1. Open Postman
2. Click **Import** button (top left)
3. Select `postman_collection.json`
4. Click **Import**

### Method 2: Drag & Drop
1. Open Postman
2. Drag `postman_collection.json` into Postman window
3. Collection will be automatically imported

## ⚙️ Configuration

### Environment Variables

The collection uses the following variables (automatically managed):

| Variable | Default Value | Description |
|----------|---------------|-------------|
| `base_url` | `http://localhost:8000` | API base URL |
| `product_id` | `1` | Product ID (auto-captured) |
| `hold_id` | (empty) | Hold ID (auto-captured) |
| `order_id` | (empty) | Order ID (auto-captured) |
| `idempotency_key` | (empty) | Auto-generated for webhooks |

### Change Base URL

If your API runs on a different host/port:

1. Click on the collection name
2. Go to **Variables** tab
3. Edit `base_url` value
4. Click **Save**

Examples:
- Local: `http://localhost:8000`
- Staging: `https://staging-api.example.com`
- Production: `https://api.example.com`

## 🚀 Getting Started

### Quick Test (Run Complete Flow)

The easiest way to test the API:

1. Navigate to **"Complete Purchase Flow"** folder
2. Run requests in order (1 → 2 → 3 → 4 → 5)
3. Each request automatically captures IDs for the next step

**What it does:**
1. Gets product info
2. Creates a hold (reserves stock)
3. Creates an order
4. Processes successful payment
5. Verifies final stock

**Result:** You'll see stock_sold increase and available_stock decrease!

## 📚 Collection Structure

### 1. Products
- **Get Product Info**: View product details and available stock

### 2. Holds
- **Create Hold**: Reserve stock temporarily
- **Create Hold - Large Quantity**: Example with larger qty

### 3. Orders
- **Create Order from Hold**: Convert hold to order

### 4. Webhooks
- **Payment Webhook - Success**: Mark order as paid
- **Payment Webhook - Failed**: Cancel order, restore stock
- **Payment Webhook - Duplicate**: Test idempotency
- **Payment Webhook - Out of Order**: Test early webhook arrival

### 5. Complete Purchase Flow
- 5-step guided flow from product view to completed purchase

## 🔍 Test Scenarios

### Scenario 1: Successful Purchase
```
1. Get Product → 2. Create Hold → 3. Create Order → 4. Success Webhook → 5. Verify
```

**Expected:**
- Stock sold increases
- Available stock decreases
- Order status: `paid`

### Scenario 2: Failed Payment
```
1-3. (Same as above) → 4. Failed Webhook → 5. Verify
```

**Expected:**
- Stock restored
- Order status: `cancelled`
- Available stock back to original

### Scenario 3: Insufficient Stock
```
1. Get Product → 2. Create Hold (qty > available)
```

**Expected:**
- 400 error: "Insufficient stock available"

### Scenario 4: Expired Hold
```
1-2. (Create hold) → Wait 2+ minutes → 3. Create Order
```

**Expected:**
- 400 error: "Hold is expired and cannot be used"

### Scenario 5: Idempotent Webhook
```
1-4. (Complete purchase) → 4. Success Webhook (same idempotency_key)
```

**Expected:**
- Second webhook: `"status": "already_processed"`
- No duplicate changes

## 🧪 Automated Tests

Each request includes automated tests that verify:

### Product Requests
✅ Status code is 200  
✅ Response has all required fields  
✅ Stock values are numbers

### Hold Requests
✅ Status code is 201  
✅ Hold ID returned  
✅ Expiry timestamp in future

### Order Requests
✅ Status code is 201  
✅ Status is `pending_payment`  
✅ Amount calculated correctly

### Webhook Requests
✅ Status code is 200  
✅ Proper status returned  
✅ Idempotency works correctly

### View Test Results

After running a request:
1. Click **Test Results** tab (bottom right)
2. Green = Passed ✅
3. Red = Failed ❌

## 📊 Variables & Auto-Capture

The collection automatically captures IDs:

```javascript
// Example: After creating a hold
var jsonData = pm.response.json();
pm.collectionVariables.set("hold_id", jsonData.hold_id);
```

**Benefits:**
- No manual copy/paste
- Run requests in sequence
- IDs flow automatically

## 🎯 Tips & Tricks

### 1. Run All Requests in Folder

1. Right-click on a folder
2. Select **Run folder**
3. Click **Run Flash-Sale...**
4. Watch all requests execute in sequence!

### 2. View Console Logs

Many requests log helpful info:

1. Open **Postman Console** (bottom left)
2. Run a request
3. See detailed logs with captured values

Example output:
```
Step 1: Product ID saved - 1
Available Stock: 100
Step 2: Hold ID saved - 42
Expires at: 2025-11-29T15:32:00Z
```

### 3. Save Responses

1. Run a request
2. Click **Save Response**
3. Add to examples for reference

### 4. Generate Code

Need to use the API in your app?

1. Run a request
2. Click **Code** (</> icon, right side)
3. Select language (cURL, JavaScript, Python, etc.)
4. Copy generated code

### 5. Mock Server (Optional)

Create a mock server for testing:

1. Right-click collection
2. **Mock Collection**
3. Use mock URL for frontend development

## 🔄 Common Workflows

### Testing Concurrency

Run multiple holds simultaneously:

1. Open **Holds → Create Hold**
2. Click **Send** multiple times quickly
3. Check product stock
4. Verify no overselling

### Testing Hold Expiry

1. Create a hold
2. Wait 2+ minutes
3. Try to create order from hold
4. Should get "expired" error

Or manually trigger expiry:
```bash
php artisan holds:expire
php artisan queue:work --once
```

### Testing Webhook Idempotency

1. Run **Payment Webhook - Success**
2. Copy the idempotency_key from body
3. Run again with same key
4. Second response: `already_processed`

### Testing Out-of-Order Webhook

1. Run **Payment Webhook - Out of Order**
2. Uses non-existent order_id (99999)
3. Response: `pending_order`
4. Webhook stored for later reconciliation

## 📖 Example Requests

### Get Product (cURL)
```bash
curl http://localhost:8000/api/products/1
```

### Create Hold (cURL)
```bash
curl -X POST http://localhost:8000/api/holds \
  -H "Content-Type: application/json" \
  -d '{"product_id": 1, "qty": 5}'
```

### Create Order (cURL)
```bash
curl -X POST http://localhost:8000/api/orders \
  -H "Content-Type: application/json" \
  -d '{"hold_id": 1}'
```

### Payment Webhook (cURL)
```bash
curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Content-Type: application/json" \
  -d '{
    "order_id": 1,
    "payment_status": "success",
    "idempotency_key": "unique-key-123"
  }'
```

## 🐛 Troubleshooting

### Issue: "Could not send request"

**Solution:** Ensure API server is running:
```bash
php artisan serve
```

### Issue: "Connection refused"

**Cause:** Server not running or wrong port

**Solution:** 
1. Check `base_url` variable
2. Verify server is running: `php artisan serve`
3. Default port is 8000

### Issue: "404 Not Found"

**Cause:** Route not registered

**Solution:**
```bash
php artisan route:list
# Should show /api/products, /api/holds, etc.
```

### Issue: Variables not capturing

**Cause:** Collection variables not enabled

**Solution:**
1. Click collection name
2. Go to **Variables** tab
3. Ensure variables exist
4. Try running requests again

### Issue: Test failures

**Cause:** Response format different than expected

**Solution:**
1. Check **Body** tab for actual response
2. Check **Test Results** for specific failure
3. Verify database is seeded: `php artisan db:seed`

## 📚 Additional Resources

- **README.md**: Setup and architecture overview
- **DEMO.md**: Detailed demo scenarios with curl
- **ARCHITECTURE.md**: Technical implementation details
- **API Documentation**: See README.md → API Endpoints section

## 💡 Pro Tips

1. **Use Collection Runner**: Test entire flows automatically
2. **Monitor Logs**: Keep Laravel logs open: `tail -f storage/logs/laravel.log`
3. **Check Database**: Run queries to see state changes
4. **Use Variables**: Never hardcode IDs
5. **Save Examples**: Document your API usage patterns

## 🎓 Learning Path

1. ✅ Import collection
2. ✅ Run "Complete Purchase Flow"
3. ✅ Understand auto-capture
4. ✅ Test individual endpoints
5. ✅ Try error scenarios
6. ✅ Test idempotency
7. ✅ Test concurrency
8. ✅ Generate code snippets

---

**Happy Testing! 🚀**

For issues or questions, refer to the main README.md or DEMO.md files.

