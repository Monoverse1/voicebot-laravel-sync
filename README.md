# monoverse/voicebot-laravel-sync

Sync your Laravel catalog and content to **VoiceBot** for an AI sales assistant — signed,
incremental, swiss-watch reliable.

This package is a **producer client** for the VoiceBot canonical signed-ingest contract
(ADR-045 / ADR-055). It pairs with VoiceBot once, then pushes your products, categories,
pages and more over an HMAC-signed protocol: a full snapshot for the baseline and small
incremental deltas after that. Voice stays on the server — this package never embeds any
LLM SDK; it only streams your data to the ingest endpoint.

- **Signed** — every request is HMAC-SHA256 signed (protocol v2); the shared secret is
  stored **encrypted at rest** and never logged or printed.
- **Incremental** — a per-entity watermark means deltas push only what changed.
- **Memory-safe** — snapshots stream as gzipped NDJSON straight from disk; the full
  catalog is never held in memory.
- **Adaptive** — point each entity kind at your model with a small column map, or bind a
  fully custom source.

---

## Requirements

- PHP 8.2+ (Laravel 13 requires PHP 8.3+)
- Laravel 10, 11, 12 or 13
- An `APP_KEY` (used to encrypt the stored shared secret)

## Install from Packagist

The package is published on public [Packagist](https://packagist.org/packages/monoverse/voicebot-laravel-sync)
as `monoverse/voicebot-laravel-sync` — install it with a plain Composer require:

```bash
composer require monoverse/voicebot-laravel-sync
```

Publish the config and run migrations (auto-discovery registers the service provider):

```bash
php artisan vendor:publish --tag="voicebot-config"
php artisan migrate
```

The package registers its migrations, so a plain `php artisan migrate` creates three
tables: `voicebot_connections` (encrypted secret), `voicebot_sync_state` (per-kind
watermark) and `voicebot_dead_letter` (parked failures). No migration publish step is
required; publish them only if you want to customise the schema.

> The ingest base URL must be `https://` (plaintext `http://` is rejected — an HMAC over
> http would leak the signature). `localhost` / `127.0.0.1` are allowed for local dev.

## Pair

Get a one-time pair code (`VB-XXXX-XXXX`) from your VoiceBot dashboard, then:

```bash
php artisan voicebot:pair VB-XXXX-XXXX
```

The command stores your tenant id + shared secret (encrypted) and prints the tenant id.
**The shared secret is never printed or logged.** For non-interactive CI, set
`VOICEBOT_PAIR_CODE` and run `php artisan voicebot:pair`.

## Map your models

Open `config/voicebot.php` and, for each kind you have, set the model and a column map.
Map keys are dot paths under `payload`; values are either a column name or a closure
receiving the model.

```php
use App\Models\Product;

'entities' => [
    \Monoverse\VoicebotSync\Dto\EntityKind::Product->value => [
        'enabled' => true,
        'model' => Product::class,
        'updated_at' => 'updated_at',   // watermark column for deltas
        'external_id' => 'id',          // wrapped to "laravel:product:{id}" automatically
        'with' => ['categories'],       // eager-load to avoid N+1 while streaming
        'map' => [
            'payload.name'        => 'title',
            'payload.sku'         => 'sku',
            // MONEY IS INTEGER MINOR UNITS — convert decimals with a closure:
            'payload.price_amount'=> fn ($m) => (int) round($m->price * 100),
            'payload.currency'    => fn () => config('voicebot.currency', 'UAH'),
            'payload.description' => fn ($m) => trim(strip_tags((string) $m->description)),
            'payload.permalink'   => fn ($m) => route('product.show', $m),
            // CATEGORIES / TAGS ARE SLUG LISTS:
            'payload.categories'  => fn ($m) => $m->categories->pluck('slug')->all(),
            'payload.stock_status'=> fn ($m) => $m->in_stock ? 'instock' : 'outofstock',
        ],
    ],
],
```

### Mapping rules that matter

- **Money is integer minor units.** `199.99 UAH` → `19999`. Never send a float/decimal.
- **Categories and tags are slug lists** (`['kava', 'napoi']`), not objects.
- **Page `content_text` is plain text** — strip HTML.
- **`external_id`** supplies the raw id only; the package wraps it to
  `laravel:{kind}:{id}`. Parent references follow the same shape, e.g.
  `'payload.parent_external_id' => fn ($m) => $m->parent_id ? 'laravel:category:'.$m->parent_id : null`.
- **Soft deletes** are detected automatically (model uses `SoftDeletes`) and pushed as
  delete ops during deltas. Hard deletes are reconciled by the nightly full snapshot's
  server-side tombstone pass.

### Custom source (full control)

For anything the config map can't express, implement `EntitySource` and point the kind's
`source` at your class (resolved from the container):

```php
use Monoverse\VoicebotSync\Contracts\EntitySource;

final class ProductSource implements EntitySource { /* kind(), upserts(), deletes(), ... */ }

// config/voicebot.php
EntityKind::Product->value => ['enabled' => true, 'source' => \App\VoiceBot\ProductSource::class],
```

`upserts()` and `deletes()` **must** return a streaming `LazyCollection` — never
materialise the whole catalog.

## Verify before the first push

```bash
php artisan voicebot:doctor
```

Doctor checks pairing, backend reachability, and — per enabled kind — a resolvable model,
a non-empty map, and the backend-required payload fields on a sampled mapped row. It emits
one sample mapped record per kind and **pushes nothing**. Fix every `FAIL` before syncing.

## Sync

```bash
php artisan voicebot:sync --full        # complete snapshot (run once, then nightly)
php artisan voicebot:sync               # incremental delta (the cron path)
php artisan voicebot:sync --dry-run     # compute + print what WOULD be sent; push nothing
php artisan voicebot:sync --since="2026-06-01"  # delta from a specific instant (catch-up)
php artisan voicebot:sync --queue       # dispatch to a queue worker and return
```

### Exit codes (cron-friendly)

| Code | Meaning | Action |
|------|---------|--------|
| `0`  | success | — |
| `1`  | transient failure (network/5xx) **or** a delta that dead-lettered ops | cron alerts, retry next run |
| `2`  | config error (not paired, empty snapshot, bad model, bad `--since`) | a retry won't help; fix config |

Signed requests retry on connection errors only — never on 429/5xx — because the
backend consumes the request nonce before it can return an error status, so a blind
retry would be rejected as a replay. The unsigned pair handshake and the snapshot
upload (no HMAC) do retry on 429/5xx with backoff that honours `Retry-After`.

Failed delta ops land in `voicebot_dead_letter` (never silently dropped). Re-failures
of the same op across runs increment its `attempts`; once it hits
`sync.dead_letter_max_attempts` the row is flagged `exhausted` for manual review/replay.

## Unpair

```bash
php artisan voicebot:unpair
```

Tells the backend to disconnect, then wipes the locally stored tenant id + shared
secret. The local credentials are cleared even if the remote call fails, so you can
always re-pair. Run this before pairing a different tenant.

## Schedule

**Prerequisite:** a system cron entry that ticks Laravel's scheduler every minute:

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

### Laravel 11 / 12 — `routes/console.php`

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('voicebot:sync')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('voicebot:sync --full')->dailyAt('03:00')->withoutOverlapping();
```

### Laravel 10 — `app/Console/Kernel.php`

```php
protected function schedule(\Illuminate\Console\Scheduling\Schedule $schedule): void
{
    $schedule->command('voicebot:sync')->everyFifteenMinutes()->withoutOverlapping();
    $schedule->command('voicebot:sync --full')->dailyAt('03:00')->withoutOverlapping();
}
```

Tune cadence to your catalog volatility — the package ships **no** hardcoded schedule.

## Entity coverage

`product`, `variation`, `category`, `tag`, `attribute`, `page`, `post`, `cpt`, `menu`,
`menu_item`, `site`, `shipping_method`, `payment_method`. Enable only the kinds you have.

## Security

- The shared secret is stored **encrypted** (`encrypted` cast over Laravel `Crypt`,
  keyed by `APP_KEY`) and is **never logged, printed, or returned** in any output.
- Every signed request carries an HMAC over `METHOD\npath\nts\nnonce\nbody_sha256`; the
  exact bytes hashed are the exact bytes sent, so the server's body check always matches.
- Requests use a fresh 16-byte nonce and a current timestamp (server enforces a 300s
  replay window). Retries honour `Retry-After`.

## Configuration reference

All knobs are env-overridable; see `config/voicebot.php`. Key ones:

| Env | Default | Purpose |
|-----|---------|---------|
| `VOICEBOT_BASE_URL` | `https://api.monoverse.tech` | Ingest base URL |
| `VOICEBOT_PAIR_CODE` | — | Non-interactive pairing (CI) |
| `VOICEBOT_SITE_URL` | `APP_URL` | Sent as `X-VoiceBot-Site-Url` |
| `VOICEBOT_SYNC_CHUNK_SIZE` | `200` | Rows per DB chunk while streaming |
| `VOICEBOT_SYNC_QUEUE` | default | Queue connection for `--queue` |
| `VOICEBOT_CURRENCY` | `UAH` | Convenience for example maps |

## Publishing (maintainers)

This package lives in the VoiceBot monorepo at `packages/laravel-sync/` and is published to
public Packagist via a **read-only subtree split** of this directory into the standalone
mirror repo `Monoverse1/voicebot-laravel-sync`. Releases are **tag-driven**: push a
monorepo tag `laravel-sync-vX.Y.Z` and the
[`publish-laravel-sync` workflow](../../.github/workflows/publish-laravel-sync.yml) splits +
mirrors the tag, which Packagist then indexes. The full runbook (one-time Packagist setup,
required secrets/vars, the release flow) is in [`PUBLISHING.md`](./PUBLISHING.md);
v0.1.0 was seeded manually and is already live on the mirror.

## License

Proprietary © Monoverse.
