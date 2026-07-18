# Deploying The Daily Pulse to Hetzner

A complete, containerized deploy: PHP-FPM app, Caddy (automatic HTTPS), PostgreSQL,
Redis, a queue worker, and the scheduler — all via Docker Compose.

## 1. DNS

Point your domain's **A record** at your Hetzner server's IPv4 address (and an
**AAAA** record at its IPv6 if you have one). Wait for it to resolve before step 6
so Caddy can obtain a Let's Encrypt certificate.

## 2. Server (Ubuntu 24.04)

SSH in as root (or a sudo user) and install Docker:

```bash
curl -fsSL https://get.docker.com | sh
```

Open the firewall for web + SSH (Hetzner Cloud Firewall or ufw):

```bash
ufw allow 22 && ufw allow 80 && ufw allow 443 && ufw enable
```

## 3. Get the code

Clone the repo; the Laravel app lives in the `pulse/` subfolder (all Docker
commands run from there):

```bash
git clone https://github.com/trajcheangjelovski-blip/TheTrueDefender.git /opt/ttd
cd /opt/ttd/pulse
```

## 4. Configure

```bash
cp .env.production.example .env
nano .env      # set APP_DOMAIN, TLS_EMAIL, APP_URL, DB_PASSWORD,
               # OPENAI_API_KEY, VAPID_* , mail — everything marked to fill in
```

Generate an app key and paste it into `.env` as `APP_KEY=`:

```bash
docker compose run --rm app php artisan key:generate --show
```

(Generate VAPID keys the same way if you didn't reuse the dev ones — command is in
`.env.production.example`.)

## 5. Build & launch

```bash
docker compose build
docker compose up -d
```

On first boot the app container installs dependencies, waits for Postgres, runs
migrations, links storage, and caches config. Watch it come up:

```bash
docker compose logs -f app
```

## 6. Seed & create your admin

```bash
docker compose exec app php artisan db:seed --force        # categories + demo content (optional)
docker compose exec app php artisan make:filament-user     # your admin login
```

Visit **https://yourdomain.com** (site) and **https://yourdomain.com/admin** (dashboard).
Caddy fetches the TLS certificate automatically on first request.

## 7. What's running

| Service     | Role                                                            |
|-------------|----------------------------------------------------------------|
| `web`       | Caddy — HTTPS, static files, proxies PHP                        |
| `app`       | PHP-FPM — the site + admin                                      |
| `db`        | PostgreSQL (data in the `pgdata` volume)                        |
| `redis`     | cache, queues, sessions                                         |
| `queue`     | processes web-push, social posts, and AI-ingest jobs           |
| `scheduler` | runs `ingest:run` every 5 min + `seo:pull-rankings` daily       |

Because `queue` and `scheduler` run as always-on services, **web push, social
auto-posting, and the 5-minute AI news ingest all work in production** with no cron
setup needed.

## 8. Updating

```bash
cd /opt/dailypulse
git pull
docker compose build
docker compose up -d          # app re-runs pending migrations on boot
```

## 9. Backups

```bash
# Database
docker compose exec db pg_dump -U dailypulse dailypulse > backup_$(date +%F).sql
# Uploaded media lives in ./storage/app/public (part of the repo dir — back it up too)
```

## 10. SEO & Google ranking (optional)

The admin shows an **SEO score** for every post and page, and (once connected) the
**real Google ranking** — average position, clicks, impressions — per URL.

- **AI SEO score** works as soon as `OPENAI_API_KEY` is set (Admin → AI & Ads Settings,
  or `.env`). Without a key it falls back to a local rule-based score. Analyze a post
  from its editor ("Analyze with AI") or bulk-select posts → **Analyze SEO**. Static
  pages are under **Content → Page SEO**.
- **Real Google ranking (Search Console):**
  1. Verify your domain in [Google Search Console](https://search.google.com/search-console).
  2. In [Google Cloud Console](https://console.cloud.google.com): enable the
     **Search Console API**, create a **service account**, and download its **JSON key**.
  3. In Search Console → *Settings → Users and permissions*, add the service account's
     email (e.g. `name@project.iam.gserviceaccount.com`) as a **Full/Restricted** user.
  4. In Admin → **AI & Ads Settings → Google Search Console**, set the **Property**
     (`sc-domain:yourdomain.com` for a domain property) and paste the **service-account
     JSON key**.
  5. Data pulls automatically once a day. To sync immediately:
     `docker compose exec app php artisan seo:pull-rankings`.

  Search Console only has data for pages that have been indexed and received Google
  impressions, so rankings appear a few days after launch.

## 11. Card payments (Stripe, optional)

Without Stripe, checkout records pending cash-on-delivery orders (as before).
Card payment uses an **on-page card form** (Stripe Elements) — the card data goes
straight to Stripe, never your server. To enable:

1. Create a [Stripe account](https://dashboard.stripe.com). Copy BOTH keys from
   *Developers → API keys* (use **test** keys `pk_test_…`/`sk_test_…` first):
   the **Publishable key** and the **Secret key**.
2. In Admin → **AI & Ads Settings → Payments (Stripe)**, paste the publishable key
   and the secret key, and Save.
3. (Recommended) Stripe dashboard → *Developers → Webhooks* → add endpoint
   `https://yourdomain.com/stripe/webhook`, event **payment_intent.succeeded**, and
   paste its **Signing secret** (`whsec_…`) into the same admin section.
4. Done. `/checkout` now shows a card field; the success page + webhook both confirm
   payment and mark the order **paid**. Test with card **4242 4242 4242 4242**.

**Free + shipping products:** set a product's price to **0** and set its
**Shipping & handling** price — the shop shows "FREE — just pay shipping" and Stripe
charges only the shipping.

## Notes & troubleshooting

- **No domain yet?** Set `APP_DOMAIN=:80` in `.env` to serve over plain HTTP on the
  server IP for testing (web push needs HTTPS, so switch to a real domain before launch).
- **Logs:** `docker compose logs -f queue` / `scheduler` / `web`.
- **Run a command:** `docker compose exec app php artisan <cmd>`.
- **Immutable-image alternative:** for CI/CD you can bake code into the image
  (add `COPY . .` + `composer install` to the Dockerfile and drop the code bind
  mounts) instead of the git-pull flow above.
