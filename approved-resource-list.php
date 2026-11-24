<?php
/*
Plugin Name: Approved Resource List
Description: Displays approved public resources in dynamic Gutenberg blocks.
Version: 1.0.0
Requires at least: 6.5
Requires PHP: 8.1
Text Domain: approved-resource-list
*/

declare(strict_types=1);

namespace ApprovedResourceList;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const MAX_ITEMS = 12;
const DEFAULT_LIMIT = 6;

final class SiteContext {
    public function __construct(
        public readonly string $market,
        public readonly string $locale,
        private readonly string $expected_source,
        private readonly string $actual_source
    ) {}

    public static function from_environment(): self {
        return new self(
            defined( 'ARL_MARKET' ) ? (string) ARL_MARKET : '',
            defined( 'ARL_LOCALE' ) ? (string) ARL_LOCALE : '',
            defined( 'ARL_SOURCE_URL' ) ? (string) ARL_SOURCE_URL : '',
            defined( 'PRB_API_URL' ) ? (string) PRB_API_URL : ''
        );
    }

    public function is_valid(): bool {
        return 1 === preg_match( '/^[A-Z]{2}$/D', $this->market )
            && in_array( $this->locale, array( 'en', 'fr' ), true )
            && self::is_https( $this->expected_source )
            && $this->expected_source === $this->actual_source;
    }

    public static function is_https( string $url ): bool {
        $parts = wp_parse_url( $url );
        return is_array( $parts ) && 'https' === ( $parts['scheme'] ?? '' )
            && ! empty( $parts['host'] ) && ! isset( $parts['user'] )
            && ! isset( $parts['pass'] ) && ! preg_match( '/[\x00-\x20\x7f]/', $url );
    }
}

final class BlockSettings {
    public function __construct(
        public readonly string $heading,
        public readonly int $level,
        public readonly int $limit,
        public readonly bool $show_summary
    ) {}

    public static function from_attributes( array $attributes ): self {
        $heading = $attributes['heading'] ?? '';
        $heading = is_string( $heading ) ? sanitize_text_field( $heading ) : '';
        $level = $attributes['headingLevel'] ?? 2;
        $level = in_array( $level, array( 2, 3, 4 ), true ) ? $level : 2;
        return new self(
            '' !== $heading ? $heading : __( 'Resources', 'approved-resource-list' ),
            $level,
            self::limit( $attributes['limit'] ?? DEFAULT_LIMIT ),
            true === ( $attributes['showSummary'] ?? true )
        );
    }

    public static function limit( mixed $value ): int {
        if ( ! is_int( $value ) ) {
            return DEFAULT_LIMIT;
        }
        return max( 1, min( MAX_ITEMS, $value ) );
    }
}

final class ResourceReader {
    public function __construct( private readonly SiteContext $context ) {}

    public function read( int $limit ): array {
        $unavailable = array( 'state' => 'unavailable', 'items' => array() );
        if ( ! $this->context->is_valid() ) {
            return $unavailable;
        }

        // Internal REST dispatch: no loopback HTTP or new upstream request.
        $request = new WP_REST_Request( 'GET', '/public-resources/v1/resources' );
        $request->set_query_params( array(
            'locale'   => $this->context->locale,
            'page'     => 1,
            'per_page' => BlockSettings::limit( $limit ),
        ) );
        $response = rest_do_request( $request );
        if ( 200 !== $response->get_status() ) {
            return $unavailable;
        }

        $data = $response->get_data();
        // This component deliberately rejects the bridge's stale fallback.
        if ( ! is_array( $data ) || 'fresh' !== ( $data['state'] ?? '' )
            || ! is_array( $data['items'] ?? null ) || ! array_is_list( $data['items'] )
            || count( $data['items'] ) > BlockSettings::limit( $limit ) ) {
            return $unavailable;
        }

        $items = array();
        $seen  = array();
        foreach ( $data['items'] as $item ) {
            if ( ! is_array( $item ) || ! $this->is_valid_item( $item )
                || isset( $seen[ $item['id'] ] ) ) {
                return $unavailable; // A malformed collection is not an empty collection.
            }
            $seen[ $item['id'] ] = true;
            $items[] = array(
                'id'      => $item['id'],
                'title'   => sanitize_text_field( $item['title'] ),
                'summary' => sanitize_text_field( $item['summary'] ),
                'url'     => esc_url_raw( $item['url'], array( 'https' ) ),
            );
        }
        return array( 'state' => $items ? 'ready' : 'empty', 'items' => $items );
    }

    private function is_valid_item( array $item ): bool {
        foreach ( array( 'id', 'title', 'summary', 'url', 'locale' ) as $field ) {
            if ( ! is_string( $item[ $field ] ?? null ) ) {
                return false;
            }
        }
        return $this->context->locale === $item['locale']
            && 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/D', $item['id'] )
            && strlen( $item['title'] ) <= 300
            && '' !== sanitize_text_field( $item['title'] )
            && strlen( $item['summary'] ) <= 2000
            && strlen( $item['url'] ) <= 2048
            && SiteContext::is_https( $item['url'] )
            && '' !== esc_url_raw( $item['url'], array( 'https' ) );
    }
}

final class ResourceBlock {
    public function __construct( private readonly ResourceReader $reader ) {}

    public function register(): void {
        register_block_type( __DIR__ . '/block', array(
            'render_callback' => array( $this, 'render' ),
        ) );
    }

    public function register_preview(): void {
        register_rest_route( 'approved-resource-list/v1', '/preview', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'preview' ),
            'permission_callback' => static function (): bool {
                return current_user_can( 'edit_posts' ) || current_user_can( 'edit_pages' )
                    || current_user_can( 'edit_theme_options' );
            },
            'args' => array(
                'limit' => array(
                    'type'    => 'integer',
                    'minimum' => 1,
                    'maximum' => MAX_ITEMS,
                    'default' => DEFAULT_LIMIT,
                ),
            ),
        ) );
    }

    public function preview( WP_REST_Request $request ): WP_REST_Response {
        $result = $this->reader->read( (int) $request->get_param( 'limit' ) );
        $response = new WP_REST_Response( $result, 'unavailable' === $result['state'] ? 503 : 200 );
        $response->header( 'Cache-Control', 'no-store' );
        return $response;
    }

    public function render( array $attributes ): string {
        $settings = BlockSettings::from_attributes( $attributes );
        $result   = $this->reader->read( $settings->limit );
        $tag      = 'h' . $settings->level; // Only h2, h3 or h4.
        $id       = wp_unique_id( 'approved-resources-' );
        ob_start();
        ?>
        <section <?php echo get_block_wrapper_attributes(); ?> aria-labelledby="<?php echo esc_attr( $id ); ?>">
            <<?php echo $tag; ?> id="<?php echo esc_attr( $id ); ?>" class="arl-heading"><?php echo esc_html( $settings->heading ); ?></<?php echo $tag; ?>>
            <?php if ( 'ready' === $result['state'] ) : ?>
                <ul class="arl-list">
                    <?php foreach ( $result['items'] as $item ) : ?>
                        <li class="arl-item">
                            <a href="<?php echo esc_url( $item['url'], array( 'https' ) ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
                            <?php if ( $settings->show_summary && '' !== $item['summary'] ) : ?>
                                <p><?php echo esc_html( $item['summary'] ); ?></p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php elseif ( 'empty' === $result['state'] ) : ?>
                <p><?php esc_html_e( 'No resources are available at the moment.', 'approved-resource-list' ); ?></p>
            <?php else : ?>
                <p><?php esc_html_e( 'Resources are temporarily unavailable. Please check back later.', 'approved-resource-list' ); ?></p>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }
}

add_action( 'plugins_loaded', static function (): void {
    $block = new ResourceBlock( new ResourceReader( SiteContext::from_environment() ) );
    add_action( 'init', array( $block, 'register' ) );
    add_action( 'rest_api_init', array( $block, 'register_preview' ) );
} );
