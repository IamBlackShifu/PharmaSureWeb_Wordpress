<?php
/**
 * PharmaSure Clinical Plugin - Patients, Prescriptions, Dispensing
 */

namespace PharmaSure\Clinical;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin Name: PharmaSure Clinical
 * Description: Manages patients, prescriptions, dispensing, and medication tracking
 * Version: 1.0.0
 * Requires Plugins: pharmasure-core, pharmasure-inventory
 */

spl_autoload_register( function ( $class ) {
	if ( strpos( $class, __NAMESPACE__ ) === 0 ) {
		$path = __DIR__ . '/src/' . str_replace( [ __NAMESPACE__ . '\\', '\\' ], [ '', '/' ], $class ) . '.php';
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
} );

add_action( 'plugins_loaded', [ Plugin::class, 'init' ], 10 );

class Plugin {
	public static function init() {
		if ( ! class_exists( 'PharmaSure\Core\TenantContext' ) ) {
			return;
		}

		add_action( 'rest_api_init', [ Rest\PatientController::class, 'register_routes' ] );
		add_action( 'rest_api_init', [ Rest\PrescriptionController::class, 'register_routes' ] );
		add_action( 'admin_menu', [ Admin\ClinicalAdmin::class, 'register_pages' ] );
	}
}

// Services
namespace PharmaSure\Clinical\Services;

class PatientService {
	private $wpdb;

	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/**
	 * Create patient (tenant/branch scoped)
	 */
	public function create_patient( $tenant_id, $branch_id, array $data ) {
		$patient_data = [
			'tenant_id'    => $tenant_id,
			'branch_id'    => $branch_id,
			'patient_number' => sanitize_text_field( $data['patient_number'] ?? '' ),
			'first_name'   => sanitize_text_field( $data['first_name'] ),
			'last_name'    => sanitize_text_field( $data['last_name'] ),
			'date_of_birth' => sanitize_text_field( $data['date_of_birth'] ?? '' ),
			'phone'        => sanitize_text_field( $data['phone'] ?? '' ),
			'email'        => sanitize_email( $data['email'] ?? '' ),
			'address'      => sanitize_textarea_field( $data['address'] ?? '' ),
			'status'       => 'active',
			'created_at'   => current_time( 'mysql', true ),
		];

		$this->wpdb->insert( $this->wpdb->prefix . 'ps_patients', $patient_data );

		return [ 'id' => $this->wpdb->insert_id ];
	}

	/**
	 * Search patients (tenant/branch scoped)
	 */
	public function search_patients( $tenant_id, $branch_id, $search_term ) {
		$search = '%' . $this->wpdb->esc_like( $search_term ) . '%';

		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->wpdb->prefix}ps_patients 
				 WHERE tenant_id = %d AND branch_id = %d 
				 AND (first_name LIKE %s OR last_name LIKE %s OR phone LIKE %s OR patient_number LIKE %s)
				 ORDER BY last_name ASC",
				$tenant_id,
				$branch_id,
				$search,
				$search,
				$search,
				$search
			),
			ARRAY_A
		);
	}

	/**
	 * Get patient history
	 */
	public function get_patient_history( $tenant_id, $patient_id, $limit = 20 ) {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT 'prescription' as type, p.id, p.status, p.created_at FROM {$this->wpdb->prefix}ps_prescriptions p
				 WHERE p.tenant_id = %d AND p.patient_id = %d
				 UNION ALL
				 SELECT 'sale' as type, s.id, s.status, s.created_at FROM {$this->wpdb->prefix}ps_sales s
				 WHERE s.tenant_id = %d AND s.patient_id = %d
				 ORDER BY created_at DESC LIMIT %d",
				$tenant_id,
				$patient_id,
				$tenant_id,
				$patient_id,
				$limit
			),
			ARRAY_A
		);
	}
}

class PrescriptionService {
	private $wpdb;

	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/**
	 * Create prescription (draft state)
	 */
	public function create_prescription( $tenant_id, $branch_id, array $data ) {
		$prescription_data = [
			'tenant_id'     => $tenant_id,
			'branch_id'     => $branch_id,
			'patient_id'    => intval( $data['patient_id'] ),
			'prescriber'    => sanitize_text_field( $data['prescriber'] ),
			'prescription_date' => sanitize_text_field( $data['prescription_date'] ),
			'status'        => 'draft',
			'notes'         => sanitize_textarea_field( $data['notes'] ?? '' ),
			'created_at'    => current_time( 'mysql', true ),
		];

		$this->wpdb->insert( $this->wpdb->prefix . 'ps_prescriptions', $prescription_data );
		$prescription_id = $this->wpdb->insert_id;

		// Add prescription items
		foreach ( $data['items'] as $item ) {
			$this->wpdb->insert(
				$this->wpdb->prefix . 'ps_prescription_items',
				[
					'prescription_id' => $prescription_id,
					'drug_id'        => intval( $item['drug_id'] ?? 0 ),
					'drug_name'      => sanitize_text_field( $item['drug_name'] ?? '' ),
					'strength'       => sanitize_text_field( $item['strength'] ?? '' ),
					'dose'           => sanitize_text_field( $item['dose'] ),
					'route'          => sanitize_text_field( $item['route'] ?? '' ),
					'frequency'      => sanitize_text_field( $item['frequency'] ),
					'duration'       => sanitize_text_field( $item['duration'] ?? '' ),
					'quantity'       => floatval( $item['quantity'] ),
					'repeats'        => intval( $item['repeats'] ?? 0 ),
				]
			);
		}

		return [ 'id' => $prescription_id ];
	}

	/**
	 * Submit prescription for review
	 */
	public function submit_for_review( $tenant_id, $prescription_id ) {
		$this->wpdb->update(
			$this->wpdb->prefix . 'ps_prescriptions',
			[ 'status' => 'pending_review' ],
			[ 'id' => $prescription_id, 'tenant_id' => $tenant_id ]
		);

		return [ 'status' => 'pending_review' ];
	}

	/**
	 * Approve prescription (pharmacist action)
	 */
	public function approve_prescription( $tenant_id, $prescription_id, $pharmacist_user_id ) {
		$this->wpdb->update(
			$this->wpdb->prefix . 'ps_prescriptions',
			[
				'status'            => 'approved',
				'approved_at'       => current_time( 'mysql', true ),
				'approved_by_user'  => $pharmacist_user_id,
			],
			[ 'id' => $prescription_id, 'tenant_id' => $tenant_id ]
		);

		do_action( 'pharmasure_audit_log', [
			'tenant_id'   => $tenant_id,
			'action'      => 'prescription.approved',
			'object_type' => 'prescription',
			'object_id'   => $prescription_id,
			'actor_id'    => $pharmacist_user_id,
		] );

		return [ 'status' => 'approved' ];
	}

	/**
	 * Dispense prescription (convert to sale)
	 */
	public function dispense_prescription( $tenant_id, $branch_id, $prescription_id, array $dispensing_data ) {
		$prescription = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->wpdb->prefix}ps_prescriptions WHERE id = %d AND tenant_id = %d",
				$prescription_id,
				$tenant_id
			)
		);

		if ( ! $prescription || $prescription->status !== 'approved' ) {
			return new \WP_Error( 'invalid_status', 'Prescription not in approved state' );
		}

		// Create sale record
		$this->wpdb->insert(
			$this->wpdb->prefix . 'ps_sales',
			[
				'tenant_id'       => $tenant_id,
				'branch_id'       => $branch_id,
				'patient_id'      => $prescription->patient_id,
				'prescription_id' => $prescription_id,
				'total_amount_minor' => intval( $dispensing_data['total_amount_minor'] ),
				'status'          => 'completed',
				'created_at'      => current_time( 'mysql', true ),
			]
		);

		$sale_id = $this->wpdb->insert_id;

		// Update prescription status
		$this->wpdb->update(
			$this->wpdb->prefix . 'ps_prescriptions',
			[ 'status' => 'dispensed' ],
			[ 'id' => $prescription_id ]
		);

		return [ 'sale_id' => $sale_id ];
	}
}

// REST Controllers
namespace PharmaSure\Clinical\Rest;

class PatientController {
	private $service;

	public function __construct() {
		$this->service = new \PharmaSure\Clinical\Services\PatientService();
	}

	public static function register_routes() {
		$controller = new self();

		register_rest_route(
			'pharmasure/v1',
			'/patients/search',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $controller, 'search' ],
				'permission_callback' => function () {
					return current_user_can( 'pharmasure_view_patients' );
				},
			]
		);

		register_rest_route(
			'pharmasure/v1',
			'/patients',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $controller, 'create' ],
				'permission_callback' => function () {
					return current_user_can( 'pharmasure_manage_patients' );
				},
			]
		);
	}

	public function search( $request ) {
		$context = \PharmaSure\Core\TenantContext::instance();
		$q = sanitize_text_field( $request->get_param( 'q' ) );

		$patients = $this->service->search_patients(
			$context->get_tenant_id(),
			$context->get_branch_id(),
			$q
		);

		return rest_ensure_response( $patients );
	}

	public function create( $request ) {
		$context = \PharmaSure\Core\TenantContext::instance();
		$params = $request->get_json_params();

		$result = $this->service->create_patient(
			$context->get_tenant_id(),
			$context->get_branch_id(),
			$params
		);

		return rest_ensure_response( [ 'success' => true, 'data' => $result ] );
	}
}

class PrescriptionController {
	private $service;

	public function __construct() {
		$this->service = new \PharmaSure\Clinical\Services\PrescriptionService();
	}

	public static function register_routes() {
		$controller = new self();

		register_rest_route(
			'pharmasure/v1',
			'/prescriptions',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $controller, 'create' ],
				'permission_callback' => function () {
					return current_user_can( 'pharmasure_manage_prescriptions' );
				},
			]
		);

		register_rest_route(
			'pharmasure/v1',
			'/prescriptions/(?P<id>\d+)/approve',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $controller, 'approve' ],
				'permission_callback' => function () {
					return current_user_can( 'pharmasure_review_prescriptions' );
				},
			]
		);

		register_rest_route(
			'pharmasure/v1',
			'/prescriptions/(?P<id>\d+)/dispense',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $controller, 'dispense' ],
				'permission_callback' => function () {
					return current_user_can( 'pharmasure_dispense_medications' );
				},
			]
		);
	}

	public function create( $request ) {
		$context = \PharmaSure\Core\TenantContext::instance();
		$params = $request->get_json_params();

		$result = $this->service->create_prescription(
			$context->get_tenant_id(),
			$context->get_branch_id(),
			$params
		);

		return rest_ensure_response( [ 'success' => true, 'data' => $result ] );
	}

	public function approve( $request ) {
		$context = \PharmaSure\Core\TenantContext::instance();
		$prescription_id = intval( $request->get_param( 'id' ) );

		$result = $this->service->approve_prescription(
			$context->get_tenant_id(),
			$prescription_id,
			get_current_user_id()
		);

		return rest_ensure_response( $result );
	}

	public function dispense( $request ) {
		$context = \PharmaSure\Core\TenantContext::instance();
		$prescription_id = intval( $request->get_param( 'id' ) );
		$params = $request->get_json_params();

		$result = $this->service->dispense_prescription(
			$context->get_tenant_id(),
			$context->get_branch_id(),
			$prescription_id,
			$params
		);

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( $result->get_error_data(), 400 );
		}

		return rest_ensure_response( $result );
	}
}

// Admin
namespace PharmaSure\Clinical\Admin;

class ClinicalAdmin {
	public static function register_pages() {
		add_submenu_page(
			'pharmasure-core',
			'Clinical',
			'Clinical',
			'pharmasure_view_prescriptions',
			'pharmasure-clinical',
			[ self::class, 'render_dashboard' ]
		);
	}

	public static function render_dashboard() {
		?>
		<div class="wrap">
			<h1>Clinical Management</h1>
			<p>Patients, prescriptions, dispensing and records</p>
		</div>
		<?php
	}
}
