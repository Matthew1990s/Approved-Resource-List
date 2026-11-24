# Approved Resource List

A Gutenberg block for displaying approved public healthcare resources on WordPress pages.

## Overview

Editors can set the heading, item count and summary visibility. The block reads normalized resource data from a local REST API. The integration service handles authentication, synchronization and approval filtering; the site's deployment configuration sets the market and language.

The block works with hybrid and block themes, uses theme design presets, and stores only presentation settings in post content. It does not fetch upstream content or schedule synchronization.

## Architecture

```mermaid
flowchart TD
    U[Approved market-specific feed] --> B[Integration service: scheduled sync]
    B --> S[(Local validated snapshot)]
    S --> R[GET public-resources/v1/resources]
    C[Site configuration] --> P[ResourceReader]
    R --> P
    P --> E[Editor preview REST route]
    E --> G[React Gutenberg editor]
    P --> F[PHP dynamic renderer]
    F --> V[Public page]
```

| Component | Responsibility |
| --- | --- |
| `SiteContext` | Checks the market format, supported language and configured source URL. |
| `BlockSettings` | Normalizes saved attributes, limits item count and validates heading levels. |
| `ResourceReader` | Calls the local REST API, requires fresh data and validates resource fields. |
| `ResourceBlock` | Registers the block, provides the protected preview route and renders escaped HTML. |
| `index.js` | Provides editor controls and preview loading, empty and error states. |
| `style.css` | Applies theme-aware styles with CSS logical properties. |

The integration service must use a source authorized for the site's market. The v1 API does not include market or review-expiry fields on each resource, so this block cannot verify market approval for individual records. It checks that the configured source URLs match, but that check does not replace market authorization. Per-resource market and expiry checks require an API contract that provides those fields.

## Project files

```text
approved-resource-list/
├── approved-resource-list.php
├── block/
│   ├── block.json
│   ├── index.js
│   ├── index.asset.php
│   └── style.css
├── README.md
└── tests/
    ├── smoke.php
    └── smoke-results.txt
```

The editor uses WordPress's bundled React packages and does not require a JSX build step.

## Local setup

Use a local WordPress 6.5+ installation running PHP 8.1 or later. The local resource endpoint must follow the API contract below.

Copy the plugin into `wp-content/plugins/approved-resource-list`. Add the site settings before WordPress loads:

```php
// wp-config.php
define( 'PRB_API_URL', 'https://example.org/resources-feed' );
define( 'ARL_MARKET', 'CA' );
define( 'ARL_LOCALE', 'en' );
define( 'ARL_SOURCE_URL', 'https://example.org/resources-feed' );
```

The URLs above are examples, not working endpoints. `PRB_API_URL` and `ARL_SOURCE_URL` must match exactly. Configure the integration separately and store any credentials using your site's approved secret-management process. `ARL_LOCALE` accepts `en` or `fr`, not region codes such as `en-CA`.

From the WordPress root, activate the plugin:

```bash
wp plugin activate approved-resource-list
```

### Local fixture

If the integration service is unavailable, you can add this fixture as `wp-content/mu-plugins/arl-fixture.php` on a disposable local site. Do not use it alongside another implementation of the same REST route.

```php
<?php
add_action( 'rest_api_init', static function () {
    register_rest_route( 'public-resources/v1', '/resources', array(
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => static function ( WP_REST_Request $request ) {
            return rest_ensure_response( array(
                'state' => 'fresh',
                'fetched_at' => gmdate( DATE_ATOM ),
                'items' => array( array(
                    'id' => 'example-001',
                    'title' => 'Example public resource',
                    'summary' => 'Example summary text.',
                    'url' => 'https://example.org/resources/example-001',
                    'locale' => 'en',
                ) ),
            ) );
        },
    ) );
} );
```

Insert **Approved Resource List** in the editor, choose a heading and item count, then compare the preview with the frontend. Change the fixture state to `stale`, change its locale to `fr`, or return an empty `items` array to check those states. Remove the fixture after testing. It is only for local testing and does not provide synchronization or production authorization.

## API contracts

### Local resource read

```http
GET /wp-json/public-resources/v1/resources?locale=en&page=1&per_page=6
```

The block calls this endpoint with `rest_do_request()` instead of making an HTTP request back to WordPress. It expects a response like this:

```json
{
  "state": "fresh",
  "fetched_at": "2026-11-24T12:00:00+00:00",
  "items": [
    {
      "id": "example-001",
      "title": "Example public resource",
      "summary": "Example summary text.",
      "url": "https://example.org/resources/example-001",
      "locale": "en"
    }
  ]
}
```

The block requires `state: fresh`, a list no larger than the requested count, unique IDs, bounded plain text, HTTPS links without embedded credentials and the configured language. It discards unrecognized fields. The integration service determines whether a snapshot is fresh; agree on the freshness period with the content owner.

### Editor preview

```http
GET /wp-json/approved-resource-list/v1/preview?limit=6
```

The route requires `edit_posts`, `edit_pages` or `edit_theme_options`. WordPress's authenticated REST setup is used by `wp.apiFetch`. The only request value that affects resource retrieval is the bounded integer `limit`.

| Response | Meaning |
| --- | --- |
| 200, `state: ready` | Resources are available. |
| 200, `state: empty` | No matching resources were found. |
| 400 | The limit is invalid. |
| 401/403 | Authentication or capability check failed. |
| 503, `state: unavailable` | The site configuration or integration is invalid, or the snapshot is not fresh. |

Responses use `Cache-Control: no-store`. The route does not return upstream tokens, raw responses or internal configuration.

## Implementation choices

### Dynamic rendering

The block saves editorial settings, not resource HTML. Its PHP renderer uses the current local snapshot, so resource data does not become a stale copy in post content. The frontend does not need JavaScript. If the plugin is deactivated, the dynamic block output is no longer available.

### Shared integration

The block uses the existing integration rather than duplicating upstream requests or calling the WordPress REST API over loopback HTTP. The reader applies the rules needed for presentation while the integration manages the local snapshot.

### Fresh data and site settings

The block does not display stale snapshots. It shows an unavailable message instead. A resource can still appear in a fresh snapshot after it has been withdrawn, and page or CDN caches may keep previously rendered output. Account for withdrawal and cache invalidation when setting the site's content policy.

Market and language are set by the deployment, not by editors. Language alone does not determine which market's resources are approved.

### Editor preview and presentation

The editor preview and PHP renderer use the same resource reader. Changing presentation settings does not refetch data; changing the item count or retrying does. The editor displays noninteractive resource titles, while the frontend uses links and semantic headings and lists.

The block uses theme preset variables rather than hard-coded brand colors. Heading levels are limited to h2–h4. Check translated text, keyboard focus and screen-reader output in the site's themes.

## Verification

Run these checks from the plugin directory:

```bash
php -l approved-resource-list.php
php -l block/index.asset.php
php -l tests/smoke.php
node --check block/index.js
php tests/smoke.php
```

The recorded checks used PHP 8.4 and Node 22; the smoke test reported 27 passing checks. See `tests/smoke-results.txt`. The PHP test uses WordPress function doubles, so it checks the plugin's control flow rather than WordPress core behavior. Test in a real local WordPress site before deployment.

In that site, check block insertion and saving, preview permissions, fresh and stale data, invalid configuration, both hybrid and block themes, keyboard navigation and screen-reader output. Also check how withdrawal and cache invalidation work in your environment.

## Maintenance and troubleshooting

| Symptom | What to check |
| --- | --- |
| Block is missing from the inserter | Confirm the plugin is active and the `block/` metadata and assets are present. Check the PHP log and editor console. |
| Preview returns 401 or 403 | Confirm the editor is signed in and has an allowed capability. Check REST authentication and nonce handling. |
| Preview returns 503 or resources are unavailable | Check `ARL_MARKET`, `ARL_LOCALE`, the match between `ARL_SOURCE_URL` and `PRB_API_URL`, and the integration snapshot. |
| No resources appear | Check whether the feed has eligible resources in the configured language. An empty response differs from a failed request. |
| Styling differs between sites | Check the active theme's preset variables and the block stylesheet. |
| A withdrawn resource is still visible | Check its approval status, snapshot freshness and any page or CDN cache. Follow the site's withdrawal process. |

For a configured local or staging site, inspect the plugin and scheduled events with:

```bash
wp plugin status approved-resource-list
wp cron event list --fields=hook,next_run_gmt,recurrence
```

Do not change the market, feed or language to work around unavailable content. Resolve configuration and approval issues with the integration owner. Do not include upstream credentials in logs or screenshots.

### Change and release checklist

- Review changes to `block.json`, saved attributes, the REST response and rendering together.
- Run the checks above and test in WordPress, in a browser and with accessibility tools.
- Check existing pages in hybrid and block themes, including empty, unavailable and wrong-language responses.
- Coordinate integration API or freshness changes with the integration owner. Verify withdrawal and cache invalidation.
- To roll back a release, restore the previous compatible plugin version. Deactivation alone removes the dynamic output; it does not restore a saved resource list.
