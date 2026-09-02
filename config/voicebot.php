<?php

declare(strict_types=1);

use Monoverse\VoicebotSync\Dto\EntityKind;

/**
 * VoiceBot Sync configuration.
 *
 * The `entities` block is the adaptivity surface: for each kind you declare the
 * Eloquent model + a column->canonical map. Map values are either a column name
 * (string) or a closure that receives the model and returns the value. For full
 * control, bind your own implementation of a Monoverse\VoicebotSync\Contracts
 * source in a service provider instead of using the config map.
 */
return [
    // Backend ingest base URL. Default = VoiceBot production.
    'base_url' => env('VOICEBOT_BASE_URL', 'https://api.monoverse.tech'),

    // Publishable key (pk_...) for pair-by-key; the preferred non-interactive pairing path.
    'public_key' => env('VOICEBOT_PUBLIC_KEY'),

    // One-time pair code (VB-XXXX-XXXX) for non-interactive `voicebot:pair` (CI). Legacy fallback.
    'pair_code' => env('VOICEBOT_PAIR_CODE'),

    // Sent as X-VoiceBot-Site-Url on every request; defaults to APP_URL.
    'site_url' => env('VOICEBOT_SITE_URL', env('APP_URL')),

    'http' => [
        'timeout' => (int) env('VOICEBOT_HTTP_TIMEOUT', 30),
        'connect_timeout' => (int) env('VOICEBOT_HTTP_CONNECT_TIMEOUT', 10),
        'retry' => [
            'times' => (int) env('VOICEBOT_HTTP_RETRY_TIMES', 3),
            'base_ms' => (int) env('VOICEBOT_HTTP_RETRY_BASE_MS', 250),
            'max_ms' => (int) env('VOICEBOT_HTTP_RETRY_MAX_MS', 5000),
        ],
    ],

    // Inbound "Sync products" trigger from the VoiceBot backend. The package never
    // mounts a route by itself; flip `routes` on to load the bundled POST /voicebot/sync
    // route, or leave it off and mount Monoverse\VoicebotSync\Http\SyncTriggerController
    // from your own routes file (recommended — you control the path + CSRF exclusion).
    'inbound' => [
        'routes' => (bool) env('VOICEBOT_INBOUND_ROUTES', false),
    ],

    'sync' => [
        // Rows pulled per DB chunk while streaming (memory-safe; never loads all rows).
        'chunk_size' => (int) env('VOICEBOT_SYNC_CHUNK_SIZE', 200),
        // Delta batch ceilings (also hard-capped by the protocol).
        'batch_max_ops' => 500,
        'batch_max_bytes' => 5 * 1024 * 1024,
        // Queue connection used by `voicebot:sync --queue`; null = default.
        'queue' => env('VOICEBOT_SYNC_QUEUE'),
        // A failed delta op is parked in the dead-letter table; re-failures across runs
        // bump its attempt count. At this many attempts the row is flagged `exhausted`
        // for manual inspection/replay instead of being re-parked forever.
        'dead_letter_max_attempts' => (int) env('VOICEBOT_DEAD_LETTER_MAX_ATTEMPTS', 5),
    ],

    // The package can register its OWN scheduled sync — no need to touch routes/console.php.
    // Flip `enabled` on and run a scheduler (the system cron `* * * * * php artisan schedule:run`,
    // or a `php artisan schedule:work` worker). Off by default, so nothing runs until you opt in.
    'schedule' => [
        'enabled' => (bool) env('VOICEBOT_SCHEDULE_ENABLED', false),
        // Incremental delta cadence + the nightly full snapshot (cron expressions).
        'delta_cron' => env('VOICEBOT_SCHEDULE_DELTA_CRON', '*/15 * * * *'),
        'full_cron' => env('VOICEBOT_SCHEDULE_FULL_CRON', '0 3 * * *'),
    ],

    /*
     * Per-kind producers. Disable kinds you do not have. `model` + `map` drive the
     * default config source; `source` (a container-bound class implementing the
     * matching Contracts interface) overrides it entirely.
     *
     * external_id is wrapped to "laravel:{kind}:{id}" automatically — `external_id`
     * supplies the raw id (column or closure). Money is INTEGER MINOR UNITS; map a
     * decimal price with a closure: fn ($m) => (int) round($m->price * 100).
     */
    'entities' => [
        EntityKind::Product->value => [
            'enabled' => true,
            'source' => null,
            'model' => null,
            'updated_at' => 'updated_at',
            'external_id' => 'id',
            'with' => [],
            // Mapping\Presets::product() builds this from a few columns. Pass a third
            // `attributes` argument to ground attribute filtering (colour/size/material)
            // on a flat product row that has no variation table — the bot needs these to
            // answer "show me white ones". When products push structured attributes (or a
            // `variations` block below), the sync auto-advertises the `catalog.facets`
            // capability so the bot may use apply_filter / filter_by_attribute; declare
            // none and it stays honest (no facet filtering claimed).
            //
            // 'map' => Mapping\Presets::product(
            //     ['name' => 'title', 'price' => 'price', 'stock' => 'in_stock', 'categories' => 'categories'],
            //     attributes: ['Колір' => 'color', 'Розмір' => 'size'],
            // ),
            'map' => [
                // 'payload.name' => 'title',
                // 'payload.sku' => 'sku',
                // 'payload.price_amount' => fn ($m) => (int) round($m->price * 100),
                // 'payload.regular_price_amount' => fn ($m) => (int) round($m->regular_price * 100),
                // 'payload.currency' => fn () => config('voicebot.currency', 'UAH'),
                // 'payload.description' => fn ($m) => strip_tags((string) $m->description),
                // 'payload.permalink' => fn ($m) => route('product.show', $m),
                // 'payload.categories' => fn ($m) => $m->categories->pluck('slug')->all(),
                // 'payload.stock_status' => fn ($m) => $m->in_stock ? 'instock' : 'outofstock',
                // Structured facets on a flat product row (axis label => column / closure):
                // 'payload.attributes' => Mapping\Presets::attributeBuilder(['Колір' => 'color', 'Розмір' => 'size']),
            ],
            // Configurable products with a variation relation: build canonical variant_axes
            // + inline per-variation stock straight from the relation (no custom source).
            // 'variations' => Mapping\Presets::variations(
            //     items: fn ($m) => $m->variants,
            //     externalId: fn ($v) => 'laravel:variation:'.$v->id,
            //     axes: [
            //         'color' => ['name' => 'Колір', 'value' => 'color'],
            //         'size' => ['name' => 'Розмір', 'value' => 'size'],
            //     ],
            //     fields: [
            //         'price_amount' => fn ($v) => (int) round($v->price * 100),
            //         'stock_status' => fn ($v) => $v->stock_qty > 0 ? 'instock' : 'outofstock',
            //     ],
            // ),
        ],
        EntityKind::Category->value => [
            'enabled' => true,
            'source' => null,
            'model' => null,
            'updated_at' => 'updated_at',
            'external_id' => 'id',
            'with' => [],
            'map' => [
                // 'payload.name' => 'name',
                // 'payload.slug' => 'slug',
                // 'payload.parent_external_id' => fn ($m) => $m->parent_id ? 'laravel:category:'.$m->parent_id : null,
            ],
        ],
        EntityKind::Tag->value => [
            'enabled' => false,
            'source' => null,
            'model' => null,
            'updated_at' => 'updated_at',
            'external_id' => 'id',
            'with' => [],
            'map' => [],
        ],
        EntityKind::Attribute->value => [
            'enabled' => false,
            'source' => null,
            'model' => null,
            'updated_at' => 'updated_at',
            'external_id' => 'id',
            'with' => [],
            'map' => [],
        ],
        EntityKind::Page->value => [
            'enabled' => true,
            'source' => null,
            'model' => null,
            'updated_at' => 'updated_at',
            'external_id' => 'id',
            'with' => [],
            'map' => [
                // 'payload.title' => 'title',
                // 'payload.slug' => 'slug',
                // 'payload.content_text' => fn ($m) => trim(strip_tags((string) $m->body)),
                // 'payload.permalink' => fn ($m) => url($m->slug),
            ],
        ],
        EntityKind::ShippingMethod->value => [
            'enabled' => false,
            'source' => null,
            'model' => null,
            'updated_at' => 'updated_at',
            'external_id' => 'id',
            'with' => [],
            'map' => [],
        ],
        EntityKind::PaymentMethod->value => [
            'enabled' => false,
            'source' => null,
            'model' => null,
            'updated_at' => 'updated_at',
            'external_id' => 'id',
            'with' => [],
            'map' => [],
        ],
        EntityKind::Menu->value => [
            'enabled' => false,
            'source' => null,
            'model' => null,
            'updated_at' => 'updated_at',
            'external_id' => 'id',
            'with' => [],
            'map' => [],
        ],
        EntityKind::MenuItem->value => [
            'enabled' => false,
            'source' => null,
            'model' => null,
            'updated_at' => 'updated_at',
            'external_id' => 'id',
            'with' => [],
            'map' => [],
        ],
        // Standalone product variations (only if you model them as their own rows;
        // otherwise emit them inline on the product via 'payload.variations').
        EntityKind::Variation->value => [
            'enabled' => false,
            'source' => null,
            'model' => null,
            'updated_at' => 'updated_at',
            'external_id' => 'id',
            'with' => [],
            'map' => [
                // 'payload.parent_external_id' => fn ($m) => 'laravel:product:'.$m->product_id,
                // 'payload.price' => fn ($m) => (string) $m->price,  // decimal, NOT minor units
                // 'payload.attributes' => fn ($m) => $m->options,    // {name: value}
            ],
        ],
        EntityKind::Post->value => [
            'enabled' => false,
            'source' => null,
            'model' => null,
            'updated_at' => 'updated_at',
            'external_id' => 'id',
            'with' => [],
            'map' => [
                // 'payload.title' => 'title',
                // 'payload.content_text' => fn ($m) => trim(strip_tags((string) $m->body)),
            ],
        ],
        EntityKind::Cpt->value => [
            'enabled' => false,
            'source' => null,
            'model' => null,
            'updated_at' => 'updated_at',
            'external_id' => 'id',
            'with' => [],
            'map' => [
                // 'payload.title' => 'title',
                // 'payload.content_text' => fn ($m) => trim(strip_tags((string) $m->body)),
            ],
        ],
        EntityKind::HostProfile->value => [
            'enabled' => false,
            'source' => null,
            'capabilities' => [],
            'cart_endpoint' => null,
            'metadata' => [],
        ],
        // `site` is a single record, not a per-row table. The config source wraps a row
        // id to laravel:site:{id}; for one global row that is fine, but if you have no
        // such model, bind a tiny custom EntitySource instead (see the mapping docs).
        EntityKind::Site->value => [
            'enabled' => false,
            'source' => null,
            'model' => null,
            'updated_at' => 'updated_at',
            'external_id' => 'id',
            'with' => [],
            'map' => [
                // 'payload.name' => fn () => config('app.name'),
                // 'payload.currency' => fn () => config('voicebot.currency', 'UAH'),
            ],
        ],
    ],

    // Default currency used by example maps; surfaced for convenience only.
    'currency' => env('VOICEBOT_CURRENCY', 'UAH'),
];
