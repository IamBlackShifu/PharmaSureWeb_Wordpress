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
		add_action( 'admin_menu', [ Admin\LicenseAdmin::class, 'register_pages' ] );
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
		$license = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT l.*, p.name as plan_name FROM {$this->wpdb->prefix}ps_licences l
				 LEFT JOIN {$this->wpdb->prefix}ps_plans p ON l.plan_id = p.id
				 WHERE l.tenant_id = %d AND l.status IN ('active', 'grace') 
				 ORDER BY l.created_at DESC LIMIT 1",
				$tenant_id
			)
		);

		if ( $license ) {
			// Load entitlements
			$license->entitlements = $this->get_license_entitlements( $license->id );
		}

		return $license;
	}

	/**
	 * Get entitlements for a license
	 */
	public function get_license_entitlements( $license_id ) {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT e.*, le.quota_value FROM {$this->wpdb->prefix}ps_entitlements e
				 JOIN {$this->wpdb->prefix}ps_licence_entitlements le ON e.id = le.entitlement_id
				 WHERE le.licence_id = %d",
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

		$usage = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT lq.quota_value, um.usage_count FROM {$this->wpdb->prefix}ps_licence_quotas lq
				 LEFT JOIN {$this->wpdb->prefix}ps_usage_meters um ON lq.metre_id = um.id
				 WHERE lq.licence_id = %d AND lq.metre_id = (SELECT id FROM {$this->wpdb->prefix}ps_usage_meters WHERE tenant_id = %d AND key = %s)",
				$license->id,
				$tenant_id,
				$quota_key
			)
		);

		if ( $usage ) {
			return [
				'used'      => intval( $usage->usage_count ),
				'limit'     => intval( $usage->quota_value ),
				'available' => $usage->usage_count < $usage->quota_value,
			];
		}

		return [ 'used' => 0, 'limit' => 0, 'available' => true ];
	}

	/**
	 * Issue offline license token (JWT)
	 */
	public function issue_offline_token( $tenant_id, $device_id, $valid_days = 7 ) {
		$license = $this->get_license( $tenant_id );

		if ( ! $license || $license->status !== 'active' ) {
			return new \WP_Error( 'invalid_license', 'No active license to generate token from' );
		}

		$issued_at = time();
		$expires_at = $issued_at + ( $valid_days * DAY_IN_SECONDS );

		$payload = [
			'iss'          => home_url(),
			'tenant_id'    => $tenant_id,
			'device_id'    => $device_id,
			'license_id'   => $license->id,
			'iat'          => $issued_at,
			'exp'          => $expires_at,
			'entitlements' => array_map( fn( $e ) => $e->key, $license->entitlements ),
		];

		// Sign token with private key
		$private_key = getenv( 'PHARMASURE_LICENSE_PRIVATE_KEY' );
		if ( ! $private_key ) {
			return new \WP_Error( 'no_signing_key', 'License signing key not configured' );
		}

		// In production, use proper JWT library. This is simplified:
		$token = json_encode( $payload );
		$signature = hash_hmac( 'sha256', $token, $private_key );
		$jwt = base64_encode( $token ) . '.' . $signature;

		// Store token issuance in database
		$this->wpdb->insert(
			$this->wpdb->prefix . 'ps_token_issuance_log',
			[
				'tenant_id'  => $tenant_id,
				'device_id'  => $device_id,
				'token_hash' => hash( 'sha256', $jwt ),
				'issued_at'  => gmdate( 'Y-m-d H:i:s', $issued_at ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', $expires_at ),
			]
		);

		return $jwt;
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
			return new \WP_REST_Response( [ 'error' => 'No active license' ], 404 );
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
			'pharmasure-tenants',
			'Licenses',
			'Licenses',
			'manage_options',
			'pharmasure-licenses',
			[ self::class, 'render_licenses' ]
		);
	}

	public static function render_licenses() {
		global $wpdb;
		$licenses = $wpdb->get_results(
			"SELECT l.*, t.legal_name FROM {$wpdb->prefix}ps_licences l
			 JOIN {$wpdb->prefix}ps_tenants t ON l.tenant_id = t.id
			 ORDER BY l.created_at DESC"
		);
		?>
		<div class="wrap">
			<h1>Active Licenses</h1>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>Tenant</th>
						<th>Plan</th>
						<th>Status</th>
						<th>Expires</th>
						<th>Active Users</th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $licenses as $license ) {
						echo '<tr>';
						echo '<td>' . esc_html( $license->legal_name ) . '</td>';
						echo '<td>-</td>';
						echo '<td><span class="badge">' . esc_html( $license->status ) . '</span></td>';
						echo '<td>' . esc_html( mysql2date( 'Y-m-d', $license->expires_at ) ) . '</td>';
						echo '<td>-</td>';
						echo '</tr>';
					}
					?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
