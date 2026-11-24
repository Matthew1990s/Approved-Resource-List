<?php
// Run these tests from the command line only. WordPress functions are mocked here.
if ( defined( 'ABSPATH' ) || PHP_SAPI !== 'cli' ) {
    exit( 1 );
}
define( 'ABSPATH', __DIR__ );
function add_action( ...$args ) {}
function wp_parse_url( $url ) { return parse_url( $url ); }
function sanitize_text_field( $text ) { return trim( strip_tags( $text ) ); }
function esc_url_raw( $url, $protocols ) { return str_starts_with( $url, 'https://' ) ? $url : ''; }
function __( $text, $domain ) { return $text; }
class WP_REST_Request {
    public array $params = array();
    public function __construct( public string $method, public string $route ) {}
    public function set_query_params( $params ) { $this->params = $params; }
    public function get_param( $name ) { return $this->params[ $name ] ?? null; }
}
class WP_REST_Response {
    public array $headers = array();
    public function __construct( private mixed $data, private int $status = 200 ) {}
    public function get_status() { return $this->status; }
    public function get_data() { return $this->data; }
    public function header( $key, $value ) { $this->headers[ $key ] = $value; }
}
function rest_do_request( $request ) {
    $GLOBALS['requests'][] = $request;
    return $GLOBALS['response'];
}
require dirname( __DIR__ ) . '/approved-resource-list.php';
use ApprovedResourceList\{SiteContext, BlockSettings, ResourceReader, ResourceBlock};
$checks = 0;
function check( $condition, $label ) {
    if ( ! $condition ) { throw new RuntimeException( $label ); }
    $GLOBALS['checks']++;
    echo "PASS: $label\n";
}
$source = 'https://example.org/ca/feed';
$context = new SiteContext( 'CA', 'en', $source, $source );
$reader = new ResourceReader( $context );
$item = array( 'id'=>'a', 'title'=>'<b>Example</b>', 'summary'=>'Example summary', 'url'=>'https://example.org/a', 'locale'=>'en', 'secret'=>'discard' );
function feed( $items, $state = 'fresh' ) { return new WP_REST_Response( array( 'state'=>$state, 'items'=>$items ) ); }
$GLOBALS['requests'] = array();
$GLOBALS['response'] = feed( array( $item ) );
check( $context->is_valid(), 'valid deployment binding' );
check( ! (new SiteContext( 'CA', 'en', $source, 'https://example.org/us/feed' ))->is_valid(), 'reject source mismatch' );
check( ! (new SiteContext( 'CA', 'de', $source, $source ))->is_valid(), 'reject unsupported locale' );
check( ! (new SiteContext( 'canada', 'en', $source, $source ))->is_valid(), 'reject invalid market format' );
check( ! SiteContext::is_https( 'https://user:pass@example.org/a' ), 'reject embedded credentials' );
$settings = BlockSettings::from_attributes( array( 'heading'=>'<b>Help</b>', 'headingLevel'=>1, 'limit'=>99, 'showSummary'=>'false' ) );
check( 'Help' === $settings->heading && 2 === $settings->level, 'normalize heading and heading level' );
check( 12 === $settings->limit && false === $settings->show_summary, 'bound limit and validate boolean' );
check( 1 === BlockSettings::limit( -5 ) && 6 === BlockSettings::limit( '7' ), 'limit lower bound and default' );
$result = $reader->read( 4 );
check( 'ready' === $result['state'] && 'Example' === $result['items'][0]['title'], 'fresh resources normalized' );
check( ! array_key_exists( 'secret', $result['items'][0] ), 'response field allowlist' );
$req = end( $GLOBALS['requests'] );
check( '/public-resources/v1/resources' === $req->route && array( 'locale'=>'en','page'=>1,'per_page'=>4 ) === $req->params, 'local REST contract and site locale' );
$GLOBALS['response'] = feed( array() );
check( 'empty' === $reader->read( 4 )['state'], 'valid empty collection' );
$GLOBALS['response'] = feed( array( $item ), 'stale' );
check( 'unavailable' === $reader->read( 4 )['state'], 'reject stale content' );
$GLOBALS['response'] = new WP_REST_Response( array(), 503 );
check( 'unavailable' === $reader->read( 4 )['state'], 'upstream snapshot unavailable' );
foreach ( array(
    'wrong locale'=>array_replace( $item, array('locale'=>'fr') ),
    'unsafe link'=>array_replace( $item, array('url'=>'javascript:alert(1)') ),
    'missing title'=>array_diff_key( $item, array('title'=>true) ),
    'empty title'=>array_replace( $item, array('title'=>'<b></b>') ),
) as $label=>$invalid ) {
    $GLOBALS['response'] = feed( array( $invalid ) );
    check( 'unavailable' === $reader->read( 4 )['state'], $label );
}
$GLOBALS['response'] = feed( array( $item, $item ) );
check( 'unavailable' === $reader->read( 4 )['state'], 'duplicate IDs rejected' );
check( 'unavailable' === $reader->read( 1 )['state'], 'over-limit response rejected' );
$count = count( $GLOBALS['requests'] );
$bad = new ResourceReader( new SiteContext( 'CA', 'en', $source, 'https://example.org/us/feed' ) );
check( 'unavailable' === $bad->read( 4 )['state'] && $count === count( $GLOBALS['requests'] ), 'invalid binding fails before REST dispatch' );
$block = new ResourceBlock( $reader );
$request = new WP_REST_Request( 'GET', '/approved-resource-list/v1/preview' );
$request->set_query_params( array('limit'=>4) );
$GLOBALS['response'] = feed( array( $item ) );
$preview = $block->preview( $request );
check( 200 === $preview->get_status() && 'no-store' === $preview->headers['Cache-Control'], 'preview response policy' );
$GLOBALS['response'] = feed( array( $item ), 'stale' );
check( 503 === $block->preview( $request )->get_status(), 'preview fails closed like frontend reader' );
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_url( $url, $protocols ) { return esc_html( esc_url_raw( $url, $protocols ) ); }
function esc_html_e( $text, $domain ) { echo esc_html( $text ); }
function wp_unique_id( $prefix ) { static $i = 0; return $prefix . ++$i; }
function get_block_wrapper_attributes() { return 'class="wp-block-platform-resource-list"'; }
$GLOBALS['response'] = feed( array( array_replace( $item, array('title'=>'A & B', 'url'=>'https://example.org/a?x=1&y=2') ) ) );
$html = $block->render( array( 'heading'=>'<b>Help & advice</b>', 'headingLevel'=>3, 'showSummary'=>false ) );
check( str_contains( $html, '<h3 ' ) && str_contains( $html, 'Help &amp; advice</h3>' ), 'render heading and escape text' );
check( str_contains( $html, '<ul class="arl-list">' ) && str_contains( $html, 'A &amp; B</a>' ), 'semantic list and escaped link text' );
check( str_contains( $html, 'x=1&amp;y=2' ) && ! str_contains( $html, 'Example summary' ), 'escape URL and hide summary' );
$GLOBALS['response'] = feed( array( $item ), 'stale' );
$html = $block->render( array() );
check( str_contains( $html, 'temporarily unavailable' ) && ! str_contains( $html, '<a href=' ), 'stale content absent from rendered output' );
echo "$checks isolated checks passed.\n";
