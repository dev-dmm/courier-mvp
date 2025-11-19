# GDPR Compliance Setup Guide

## Overview

This system implements **GDPR-compliant pseudonymization** of customer data. All Personally Identifiable Information (PII) is hashed before transmission to the API, ensuring:

- ✅ No raw PII is stored in the central database
- ✅ Cross-shop customer matching without storing personal data
- ✅ Full compliance with GDPR data pooling and profiling requirements
- ✅ Legitimate Interest basis for fraud prevention and delivery optimization

## Architecture

### Data Flow

```
WooCommerce Shop → Hash PII (email, phone, name, address) → Send Hashed Data → Laravel API → Store Hashed Data Only
```

### Hashing Mechanism

All PII is hashed using **SHA256** with a **global salt**:

- **Email**: `SHA256(lowercase(trim(email)) + salt)`
- **Phone**: `SHA256(normalize(phone) + salt)`
- **Name**: `SHA256(lowercase(normalize(name)) + salt)`
- **Address**: `SHA256(lowercase(normalize(address)) + salt)`

The salt **must be identical** across all installations to enable cross-shop customer matching.

## Setup Instructions

### 1. Laravel Backend Setup

#### Step 1: Generate a Secure Salt

Generate a secure random string (minimum 32 characters):

```bash
# Using OpenSSL
openssl rand -hex 32

# Or using PHP
php -r "echo bin2hex(random_bytes(32));"
```

#### Step 2: Configure Laravel

Add to your `.env` file:

```env
CUSTOMER_HASH_SALT=your-generated-secure-random-string-here-min-32-chars
```

**⚠️ IMPORTANT:** This salt must be:
- **Secret** (never commit to version control)
- **Stable** (never change after going live)
- **Same** across all installations

#### Step 3: Run Migrations

```bash
php artisan migrate
```

This will:
- Remove raw PII columns from `customers` and `orders` tables
- Add hashed PII columns (`customer_name_hash`, `customer_phone_hash`, etc.)

### 2. WooCommerce Plugin Setup

#### Step 1: Install Plugin

Upload and activate the `woocommerce-plugin` folder to your WordPress installation.

#### Step 2: Configure Hash Salt

1. Go to **WooCommerce → Courier Intelligence → Settings**
2. Find the **"Customer Hash Salt"** field
3. Enter the **exact same salt** as in your Laravel `.env` file
4. Save settings

**Alternative:** You can also define it in `wp-config.php`:

```php
define('COURIER_INTELLIGENCE_HASH_SALT', 'your-generated-secure-random-string-here');
```

#### Step 3: Configure API Settings

1. Enter your **API Endpoint** (e.g., `https://api.oreksi.gr`)
2. Enter your **API Key** and **API Secret**
3. Save settings

## Data Transmission

### What Gets Sent (Hashed)

- ✅ `customer_hash` (from email)
- ✅ `customer_phone_hash` (optional)
- ✅ `customer_name_hash` (optional)
- ✅ `shipping_address_line1_hash` (optional)
- ✅ `shipping_address_line2_hash` (optional)

### What Gets Sent (Non-PII)

- ✅ `external_order_id`
- ✅ `shipping_city`
- ✅ `shipping_postcode`
- ✅ `shipping_country`
- ✅ `total_amount`
- ✅ `currency`
- ✅ `status`
- ✅ `payment_method`
- ✅ `shipping_method`
- ✅ `items_count`
- ✅ `ordered_at`
- ✅ `completed_at`

### What Does NOT Get Sent

- ❌ Raw email addresses
- ❌ Raw phone numbers
- ❌ Raw names
- ❌ Raw addresses

## GDPR Legal Basis

### Legitimate Interest

This system operates under **Legitimate Interest** (GDPR Article 6(1)(f)) for:

1. **Fraud Prevention**: Reducing failed deliveries and payment fraud
2. **Business Protection**: Protecting merchants from high-risk customers
3. **Delivery Optimization**: Improving delivery success rates

### Requirements Met

✅ **Necessity**: Scoring is necessary for fraud prevention  
✅ **Balancing Test**: Impact on customers is minimal (no automated blocking)  
✅ **Transparency**: Customers are informed via privacy policy  
✅ **Data Minimization**: Only hashed identifiers stored  
✅ **Security**: HTTPS + HMAC authentication  
✅ **Retention**: Data retention policies apply  

## Privacy Policy Section

Add this to your shop's privacy policy:

```markdown
### Fraud Prevention and Delivery Optimization

We share order data with our delivery risk evaluation partner (oreksi.gr) 
to prevent fraud and optimize delivery success rates. 

**What data is shared:**
- Pseudonymized customer identifiers (hashed email, phone, name, address)
- Order details (amount, status, shipping method)
- Delivery outcomes (success, failure, returns)

**How it's used:**
- Cross-shop customer behavior analysis
- Delivery risk scoring
- Fraud prevention

**Legal basis:** Legitimate Interest (fraud prevention and business protection)

**Data retention:** 12 months

**Your rights:** You can request deletion of your data at any time.
```

## Data Processing Agreement (DPA)

You should have a DPA between:
- **Data Controller**: Each WooCommerce shop
- **Data Processor**: oreksi.gr (Laravel API)

The DPA should specify:
- Purpose: Fraud prevention and delivery optimization
- Data types: Hashed customer identifiers only
- Retention: 12 months
- Security: Encryption, access controls
- Customer rights: Access, deletion, portability

## Troubleshooting

### Error: "CUSTOMER_HASH_SALT must be configured"

**Solution:** Set the hash salt in:
- Laravel: `.env` file → `CUSTOMER_HASH_SALT=...`
- WooCommerce: Plugin settings → "Customer Hash Salt"

### Error: "Customer hash mismatch"

**Solution:** Ensure the salt is **identical** in both Laravel and WooCommerce.

### Customers not matching across shops

**Solution:** 
1. Verify salt is the same everywhere
2. Check email normalization (lowercase, trim)
3. Verify phone normalization (spaces, dashes removed)

## Security Best Practices

1. **Never commit salt to version control**
2. **Use different salts for development/staging/production**
3. **Rotate salt only during maintenance windows** (requires data migration)
4. **Monitor API access logs**
5. **Use HTTPS only**
6. **Implement rate limiting**

## Migration from Raw PII

If you're migrating from a system that stored raw PII:

1. **Backup database** before running migrations
2. **Export existing data** if needed for reference
3. **Run migrations** to remove raw PII columns
4. **Verify** no raw PII remains in database
5. **Update privacy policy** to reflect new data handling

## Support

For questions or issues:
- Check logs: WooCommerce → Courier Intelligence → Activity Logs
- Laravel logs: `storage/logs/laravel.log`
- Verify salt configuration matches everywhere

---

**Last Updated:** 2025-11-19  
**Version:** 1.0.0

