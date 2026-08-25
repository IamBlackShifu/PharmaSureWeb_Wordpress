<?php
/** Standalone application launcher regression test. Run on a seeded tenant site. */

if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

use PharmaSure\Core\AppLauncher;
use PharmaSure\Core\TenantContext;

$pass = 0;
$fail = 0;
$assert = static function ( $condition, $message ) use ( &$pass, &$fail ) {
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$condition ? ++$pass : ++$fail;
};

$owner = get_user_by( 'login', 'owner' );
$assert( $owner instanceof WP_User, 'seeded tenant owner is available' );
if ( $owner ) { wp_set_current_user( $owner->ID ); }
$context_property = new ReflectionProperty( TenantContext::class, 'instance' );
$context_property->setValue( null, null );
$context = TenantContext::instance();

$launcher = file_get_contents( PHARMASURE_CORE_PATH . 'src/AppLauncher.php' );
$template = file_get_contents( PHARMASURE_CORE_PATH . 'templates/app-shell.php' );
$css      = file_get_contents( PHARMASURE_CORE_PATH . 'assets/css/crisp-theme.css' );
$js       = file_get_contents( PHARMASURE_CORE_PATH . 'assets/js/app.js' );

$assert( class_exists( AppLauncher::class ), 'standalone application launcher autoloads' );
$assert( str_contains( $launcher, "add_rewrite_rule( '^app(?:/.*)?/?$'" ), '/app and nested routes are registered' );
$assert( str_contains( $launcher, 'auth_redirect()' ) && str_contains( $launcher, 'redirect_staff_admin' ), 'authentication and pharmacy-staff redirect guards are present' );
$assert( str_contains( $launcher, "register_rest_route(\n\t\t\t'pharmasure/v1'" ) && str_contains( $launcher, "'/app/overview'" ), 'headless overview REST route is registered' );
$assert( ! str_contains( $template, 'wp_head' ) && ! str_contains( $template, 'wp_footer' ) && ! str_contains( $template, 'admin_url' ), 'standalone template has no WordPress presentation hooks' );
$assert( str_contains( $template, 'window.PharmaSureConfig' ) && str_contains( $template, 'crisp-theme.css' ) && str_contains( $template, 'app.js' ), 'template exposes config and only dedicated application assets' );
$assert( str_contains( $template, 'data-theme=' ) && strpos( $template, 'window.PharmaSureConfig' ) < strpos( $template, 'crisp-theme.css' ), 'saved theme is applied before the stylesheet to prevent a color flash' );
$assert( str_contains( $css, '--ps-canvas:#09090b' ) && str_contains( $css, '--ps-surface:#121215' ) && str_contains( $css, '--ps-emerald:#10b981' ), 'crisp obsidian and medical emerald tokens are canonical' );
$assert( str_contains( $css, ':root[data-theme=light]' ) && str_contains( $css, '--ps-canvas:#f4f6f7' ), 'light theme has an explicit clinical token set' );
$assert( str_contains( $css, '--ps-mono:' ) && str_contains( $css, 'prefers-reduced-motion' ) && str_contains( $css, ':focus-visible' ), 'data typography and accessibility states are defined' );
$assert( ! str_contains( $js, 'innerHTML' ) && str_contains( $js, 'textContent' ) && str_contains( $js, 'replaceChildren' ), 'client data renders through safe DOM APIs' );
$assert( str_contains( $js, "event.key.toLowerCase() === 'k'" ) && str_contains( $js, 'showModal()' ), 'Ctrl+K command launcher is keyboard accessible' );
$assert( str_contains( $js, 'ps-theme-toggle' ) && str_contains( $js, "api('app/preferences/theme'" ), 'theme control persists through the authenticated preference API' );
$assert( (int) $context->get_tenant_id() > 0 && (int) $context->get_branch_id() > 0, 'authenticated tenant and branch scope resolve server-side' );

$overview = AppLauncher::overview();
$data = $overview instanceof WP_REST_Response ? $overview->get_data() : array();
$assert( isset( $data['scope']['pharmacy'], $data['metrics']['units_available'], $data['movements'] ), 'overview API returns scoped dashboard data' );
$assert( ! isset( $data['tenant_id'] ) && ! isset( $data['scope']['tenant_id'] ), 'overview response does not expose or accept tenant identifiers' );

$invalid = AppLauncher::select_branch( new WP_REST_Request( 'POST', '/pharmasure/v1/app/branch' ) );
$assert( is_wp_error( $invalid ) && 'invalid_branch' === $invalid->get_error_code(), 'invalid branch selection fails closed' );

$original_theme = sanitize_key( (string) get_user_meta( $owner->ID, 'pharmasure_app_theme', true ) );
if ( ! in_array( $original_theme, array( 'dark', 'light' ), true ) ) { $original_theme = 'dark'; }
$next_theme = 'dark' === $original_theme ? 'light' : 'dark';
$theme_request = new WP_REST_Request( 'POST', '/pharmasure/v1/app/preferences/theme' );
$theme_request->set_param( 'theme', $next_theme );
$saved_theme = AppLauncher::save_theme( $theme_request );
$assert( $saved_theme instanceof WP_REST_Response && $next_theme === get_user_meta( $owner->ID, 'pharmasure_app_theme', true ), 'theme preference is persisted for the authenticated user' );
$theme_request->set_param( 'theme', $original_theme );
AppLauncher::save_theme( $theme_request );
$theme_request->set_param( 'theme', 'neon' );
$invalid_theme = AppLauncher::save_theme( $theme_request );
$assert( is_wp_error( $invalid_theme ) && 'invalid_theme' === $invalid_theme->get_error_code(), 'unsupported themes fail closed' );

echo "App launcher tests: {$pass} passed, {$fail} failed.\n";
if ( $fail ) { throw new RuntimeException( 'App launcher tests failed.' ); }
