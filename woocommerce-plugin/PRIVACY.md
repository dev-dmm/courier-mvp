# Privacy & GDPR Compliance

## How Customer Data is Handled

### 1. Data Transmission
- All data is sent over **HTTPS** (encrypted connection)
- Requests are authenticated using **HMAC signatures** (only authorized shops can send data)
- API credentials are stored securely in WordPress options

### 2. Email Hashing (Pseudonymization)
When an order is sent to the API:

1. **Email is received** by the backend API
2. **Immediately hashed** using: `SHA256(strtolower(trim(email)))`
3. **Hash is used** as `customer_hash` for all cross-shop operations
4. **Email is stored** optionally as `primary_email` (for internal use only)

### 3. Cross-Shop Customer Matching
- Customers are matched across different shops using **`customer_hash`** only
- The hash is deterministic: same email = same hash across all shops
- This enables cross-shop analytics while maintaining privacy

### 4. Customer Statistics
The system calculates and stores:

- `total_orders` - Total orders across all shops
- `successful_deliveries` - Successfully delivered orders
- `failed_deliveries` - Failed/cancelled orders
- `late_deliveries` - Deliveries > 5 days after shipping
- `returns` - Returned orders
- `cod_orders` - Cash on delivery orders
- `cod_refusals` - COD orders that were refused
- `delivery_success_rate` - Percentage (0-100)
- `delivery_risk_score` - Risk score (0-100, higher = more risky)

**All statistics are based on `customer_hash`, not email addresses.**

### 5. GDPR Compliance

✅ **What is allowed:**
- Cross-shop analytics using pseudonymized data (customer_hash)
- Delivery/fraud risk scoring for legitimate business purposes
- Showing statistics to merchants (shop owners), not end consumers
- Using data for delivery optimization and fraud prevention

❌ **What is NOT done:**
- Personal/moral ratings ("bad customer as a person")
- Blacklisting beyond business needs
- Using data for marketing/targeting
- Sharing data with unrelated third parties
- Showing scores to end consumers

### 6. Privacy Policy Recommendation

Your WooCommerce shop's privacy policy should include:

> "Order data is transmitted to a partner analytics platform for fraud prevention and delivery optimization purposes. The platform may combine data from multiple stores to calculate delivery reliability scores. Customer identification is done using pseudonymized hashes, not email addresses."

### 7. Data Flow Summary

```
WooCommerce Order
    ↓ (HTTPS + HMAC)
Backend API
    ↓ (immediate hashing)
SHA256(email) → customer_hash
    ↓
Cross-shop matching & statistics
    ↓
Stats shown to merchant (not consumer)
```

## Technical Details

### Customer Hash Generation
```php
$normalized = strtolower(trim($email));
$customer_hash = hash('sha256', $normalized);
```

### Database Schema
- `customers` table: Stores `customer_hash` and optional `primary_email`
- `customer_stats` table: All statistics linked via `customer_hash`
- `orders` table: Links to customer via `customer_hash`

### Security
- HMAC authentication prevents unauthorized access
- HTTPS encryption in transit
- Email hashing provides pseudonymization
- No plaintext emails used for cross-shop operations

