<?php
/**
 * Plugin Name: PharmaSure Licensing
 * Plugin URI: https://pharmasure.co.zw/
 * Description: Manages products, plans, subscriptions, licences, entitlements and usage metering.
 * Version: 1.0.0
 * Requires PHP: 8.2
 * Requires Plugins: pharmasure-core
 * Author: Infinity Lines of Code Pvt Ltd
 * Text Domain: pharmasure-licensing
 * Network: true
 * @package PharmaSure\Licensing
 */

namespace PharmaSure\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '1.0.0';

spl_autoload_register( function ( $class ) {
	if ( strpos( $class, __NAMESPACE__ ) === 0 ) {
		$path = __DIR__ . '/src/' . str_replace( [ __NAMESPACE__ . '\\', '\\' ], [ '', '/' ], $class ) . '.php';
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
} );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'PharmaSure\Core\TenantContext' ) ) {
		add_action( 'admin_notices', function () {
			echo wp_kses_post(
				'<div class="notice notice-error"><p>' .
				esc_html__( 'PharmaSure Licensing requires PharmaSure Core plugin to be activated.', 'pharmasure-licensing' ) .
				'</p></div>'
			);
		} );
		return;
	}

	Plugin::init();
}, 9 );

class Plugin {
	public static function init() {
		add_action( 'rest_api_init', [ Rest\LicenseController::class, 'register_routes' ] );
		add_action( 'rest_api_init', [ Rest\SubscriptionController::class, 'register_routes' ] );
		add_action( 'network_admin_menu', [ Admin\LicenseAdmin::class, 'register_pages' ], 30 );
	}
}

register_activation_hook( __FILE__, [ Activation::class, 'activate' ] );

class Activation {
	public static function activate() {
		update_option( 'pharmasure_licensing_activated', current_time( 'mysql' ) );
	}
}

// License Manager Service
namespace PharmaSure\Licensing\Services;

class LicenseService {
	private $wpdb;

	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/**
	 * Get license for a tenant
	 */
	public function get_license( $tenant_id ) {
		$validated = ( new \PharmaSure\Core\LicenseManager( $tenant_id ) )->validate_license();
		if ( is_wp_error( $validated ) ) { return null; }
		$license = (object) $validated;
		$license->entitlements = $this->get_license_entitlements( $license->id );
		return $license;
	}

	/**
	 * Get entitlements for a license
	 */
	public function get_license_entitlements( $license_id ) {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT entitlement_key AS `key`, is_active
				 FROM {$this->wpdb->prefix}ps_licence_entitlements
				 WHERE licence_id = %d AND is_active = 1",
				$license_id
			)
		);
	}

	/**
	 * Check if tenant has feature entitlement
	 */
	public function has_entitlement( $tenant_id, $feature_key ) {
		$license = $this->get_license( $tenant_id );

		if ( ! $license ) {
			return false;
		}

		foreach ( $license->entitlements as $ent ) {
			if ( $ent->key === $feature_key ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check quota usage
	 */
	public function check_quota( $tenant_id, $quota_key ) {
		$license = $this->get_license( $tenant_id );

		if ( ! $license ) {
			return [ 'used' => 0, 'limit' => 0, 'available' => false ];
		}

		$quota = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT lq.limit_value, COALESCE(um.amount, 0) AS used
				 FROM {$this->wpdb->prefix}ps_licence_quotas lq
				 LEFT JOIN {$this->wpdb->prefix}ps_usage_meters um
				   ON um.tenant_id = %d AND um.meter_key = lq.quota_key
				 WHERE lq.licence_id = %d AND lq.quota_key = %s",
				$tenant_id,
				$license->id,
				$quota_key
			)
		);

		if ( $quota ) {
			return [
				'used'      => intval( $quota->used ),
				'limit'     => intval( $quota->limit_value ),
				'available' => $quota->used < $quota->limit_value,
			];
		}

		return [ 'used' => 0, 'limit' => 0, 'available' => true ];
	}

	/**
	 * Issue offline license token (JWT)
	 */
	public function issue_offline_token( $tenant_id, $device_id, $valid_days = 7 ) {
		$manager = new \PharmaSure\Core\LicenseManager( $tenant_id );
		return $manager->issue_offline_token( $device_id, $valid_days );
	}
}

// REST Controller
namespace PharmaSure\Licensing\Rest;

class LicenseController {
	private $service;

	public function __construct() {
		$this->service = new \PharmaSure\Licensing\Services\LicenseService();
	}

	public static function register_routes() {
		$controller = new self();

		register_rest_route(
			'pharmasure/v1',
			'/license',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $controller, 'get_current_license' ],
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			]
		);

		register_rest_route(
			'pharmasure/v1',
			'/license/check-entitlement/(?P<feature>\w+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $controller, 'check_entitlement' ],
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			]
		);

		register_rest_route(
			'pharmasure/v1',
			'/license/offline-token',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $controller, 'issue_offline_token' ],
				'permission_callback' => function () {
					return current_user_can( 'pharmasure_manage_tenant' );
				},
			]
		);
	}

	public function get_current_license( $request ) {
		$context = \PharmaSure\Core\TenantContext::instance();
		$license = $this->service->get_license( $context->get_tenant_id() );

		if ( ! $license ) {
			return ( new \PharmaSure\Core\LicenseManager( $context->get_tenant_id() ) )->validate_license();
		}

		return rest_ensure_response( (array) $license );
	}

	public function check_entitlement( $request ) {
		$feature = sanitize_key( $request->get_param( 'feature' ) );
		$context = \PharmaSure\Core\TenantContext::instance();

		$has = $this->service->has_entitlement( $context->get_tenant_id(), $feature );

		return rest_ensure_response( [ 'entitlement' => $feature, 'granted' => $has ] );
	}

	public function issue_offline_token( $request ) {
		$params = $request->get_json_params();
		$context = \PharmaSure\Core\TenantContext::instance();
		$device_id = sanitize_text_field( $params['device_id'] ?? '' );

		if ( ! $device_id ) {
			return new \WP_REST_Response( [ 'error' => 'device_id required' ], 400 );
		}

		$token = $this->service->issue_offline_token( $context->get_tenant_id(), $device_id );

		if ( is_wp_error( $token ) ) {
			return new \WP_REST_Response( $token->get_error_data(), 400 );
		}

		return rest_ensure_response( [ 'token' => $token, 'expires_in' => 7 * DAY_IN_SECONDS ] );
	}
}

class SubscriptionController {
	public static function register_routes() {
		register_rest_route(
			'pharmasure/v1',
			'/subscription',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => function () {
					$context = \PharmaSure\Core\TenantContext::instance();
					global $wpdb;
					$sub = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT * FROM {$wpdb->prefix}ps_subscriptions WHERE tenant_id = %d ORDER BY id DESC LIMIT 1",
							$context->get_tenant_id()
						)
					);
					return rest_ensure_response( (array) $sub );
				},
				'permission_callback' => function () {
					return current_user_can( 'pharmasure_manage_tenant' );
				},
			]
		);
	}
}

// Admin Pages
namespace PharmaSure\Licensing\Admin;

class LicenseAdmin {
	public static function register_pages() {
		add_submenu_page(
			'pharmasure-admin',
			'Licenses',
			'Licenses',
			'manage_network_options',
			'pharmasure-licenses',
			[ self::class, 'render_licenses' ]
		);
	}

	public static function render_licenses() {
		if ( ! is_network_admin() || ! current_user_can( 'manage_network_options' ) ) { wp_die( esc_html__( 'Network super-administrator access is required.', 'pharmasure-licensing' ), 403 ); }
		$tenants = \PharmaSure\Tenancy\Admin\TenantAdmin::platform_tenants();
		?>
		<div class="wrap ps-platform-admin">
			<header class="ps-platform-masthead"><div><span class="ps-platform-kicker">Network control / licensing</span><h1>Pharmacy licences</h1><p>Plans, validity and grace deadlines across the pharmacy tenants.</p></div></header>
			<?php \PharmaSure\Tenancy\Admin\TenantAdmin::navigation(); ?>
			<div class="ps-platform-table-wrap">
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>Tenant</th>
						<th>Plan</th>
						<th>Status</th>
						<th>Expires</th>
						<th>Grace ends</th>
						<th>Active Users</th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $tenants as $tenant ) {
						$license = $tenant['licence'];
						$expires = ! empty( $license['expires_at'] ) ? strtotime( $license['expires_at'] . ' UTC' ) : null;
						$grace = $expires ? $expires + max( 0, (int) $license['grace_period_days'] ) * DAY_IN_SECONDS : null;
						$status = $tenant['licence_status'] ?: 'Missing licence';
						echo '<tr>';
						echo '<td>' . esc_html( $tenant['trading_name'] ) . '<br><small>/' . esc_html( $tenant['slug'] ) . '</small></td>';
						echo '<td>' . esc_html( $license['plan_name'] ?? 'Unassigned' ) . '</td>';
						echo '<td><span class="badge">' . esc_html( $status ) . '</span></td>';
						echo '<td>' . esc_html( $expires ? gmdate( 'Y-m-d H:i:s', $expires ) . ' UTC' : ( $license ? 'No expiry' : 'Not applicable' ) ) . '</td>';
						echo '<td>' . esc_html( $grace ? gmdate( 'Y-m-d H:i:s', $grace ) . ' UTC' : 'Not applicable' ) . '</td>';
						echo '<td>' . (int) $tenant['member_count'] . '</td>';
						echo '</tr>';
					}
					if ( ! $tenants ) { echo '<tr><td colspan="6">No pharmacy tenants have been provisioned.</td></tr>'; }
					?>
				</tbody>
			</table>
			</div>
		</div>
		<?php
	}
}
