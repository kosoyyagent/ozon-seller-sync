# Ozon Seller → MySQL Sync

A single-file PHP script that pulls your Ozon Seller data into MySQL:

1. **Pickup points (ПВЗ)** — fetches the active delivery-method list and
   upserts each point (id, name, region, city, address).
2. **Orders for a period** — pages through `POST /v3/posting/fbs/list`
   (last 30 days by default), links every order to its pickup point with
   region/city from `analytics_data`, and upserts into `orders`.

Built for the recurring task sellers have: *"get my orders into a database
so I can build reports/joins on them"* — no framework, one file, two
`InnoDB` tables created automatically.

## Requirements

- PHP 7.4+ with `curl` and `pdo_mysql`
- Ozon Seller API keys: `Client-Id` + `Api-Key`
  (seller cabinet → Settings → API keys)

## Setup

```bash
export OZON_CLIENT_ID=12345
export OZON_API_KEY=xxxxxxx
export DB_DSN='mysql:host=localhost;dbname=ozon_sync;charset=utf8mb4'
export DB_USER=ozon
export DB_PASS=secret

php sync.php
```

Or edit the `define(...)` defaults at the top of `sync.php`.

## Output

Two tables, created on first run:

- `pvz` — pickup points (PK `id`)
- `orders` — postings keyed by `posting_number` with `region`, `city`,
  `pvz_name`, `status`, `created_at_ozon`

Both use `ON DUPLICATE KEY UPDATE`, so the script is safe to run on a
schedule (cron) — re-runs update in place.

## Notes

- Uses the **FBS** endpoint (`/v3/posting/fbs/list`). For FBO switch the
  path to `/v2/posting/fbo/list` — the response shape is the same.
- Date range is `strtotime('-30 days')` in `main` — adjust as needed.

## License

MIT — see LICENSE.
