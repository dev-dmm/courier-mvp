# Courier Intelligence MVP

A cross-shop delivery intelligence platform that aggregates orders from multiple WooCommerce shops, computes delivery success probability and risk scores per customer using hashed email identification, and provides merchants with a dashboard to view customer statistics.

## Tech Stack

- **Backend**: Laravel 12 + PostgreSQL
- **Frontend**: Inertia.js + React + Tailwind CSS
- **Authentication**: Laravel Breeze (React stack)
- **Plugin**: WooCommerce plugin for order/voucher submission

## Features

- Multi-shop order ingestion via WooCommerce plugin
- Customer identification via SHA256 email hash (GDPR-safe)
- Delivery risk scoring algorithm
- Customer statistics aggregation
- Merchant admin dashboard
- HMAC-signed API authentication

## Installation

### Prerequisites

- PHP 8.2+
- PostgreSQL
- Node.js 20.19+ or 22.12+
- Composer
- npm/yarn

### Setup

1. Clone the repository:
```bash
git clone <repository-url>
cd courier-mvp
```

2. Install PHP dependencies:
```bash
composer install
```

3. Install Node dependencies:
```bash
npm install
```

4. Copy environment file:
```bash
cp .env.example .env
```

5. Generate application key:
```bash
php artisan key:generate
```

6. Configure database in `.env`:
```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=courier_mvp
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

7. Run migrations:
```bash
php artisan migrate
```

8. Build frontend assets:
```bash
npm run build
```

9. Start development server:
```bash
php artisan serve
npm run dev
```

## WooCommerce Plugin Setup

1. Copy the `woocommerce-plugin` directory to your WordPress plugins directory:
```bash
cp -r woocommerce-plugin /path/to/wordpress/wp-content/plugins/courier-intelligence
```

2. Activate the plugin in WordPress admin

3. Configure plugin settings:
   - Go to WooCommerce → Courier Intelligence
   - Enter your API endpoint (e.g., `https://your-domain.com`)
   - Enter your API key and secret (generated in Laravel admin)

## API Endpoints

### Orders

- `POST /api/orders` - Submit order data (HMAC protected)
- `GET /api/orders/{id}` - Get order details (HMAC protected)

### Vouchers

- `POST /api/vouchers` - Submit voucher/tracking data (HMAC protected)
- `GET /api/vouchers/{id}` - Get voucher details (HMAC protected)

### Authentication

All API endpoints require HMAC authentication:
- `X-API-Key`: Your shop's API key
- `X-Timestamp`: Current Unix timestamp
- `X-Signature`: HMAC-SHA256 signature

Signature format: `HMAC-SHA256(timestamp + method + path + body)`

## Admin Dashboard

Access the admin dashboard at `/admin/dashboard` after logging in.

Features:
- View customer statistics
- Search customers by email or hash
- View customer order history
- View delivery risk scores
- Filter orders by shop, customer, or status

## Database Schema

- `shops` - Connected WooCommerce stores
- `customers` - Global customer records (keyed by email hash)
- `customer_stats` - Aggregated delivery statistics
- `orders` - All orders from all shops
- `vouchers` - Shipping/tracking information
- `courier_events` - Optional tracking event timeline

## Risk Scoring

The delivery risk score is calculated using:
```
risk_score = (failed_deliveries * 30) + (cod_refusals * 40) + (returns * 20) + (late_deliveries * 10)
```

Normalized to 0-100:
- Green: 0-30 (low risk)
- Yellow: 31-60 (medium risk)
- Red: 61-100 (high risk)

## Development

Run tests:
```bash
php artisan test
```

Code style:
```bash
./vendor/bin/pint
```

## License

MIT
