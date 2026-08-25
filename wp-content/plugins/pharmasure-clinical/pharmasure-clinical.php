<?php
/**
 * PharmaSure Clinical Plugin - Patients, Prescriptions, Dispensing
 */

namespace PharmaSure\Clinical;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '1.2.0';
const DB_VERSION = '1';

/**
 * Plugin Name: PharmaSure Clinical
 * Description: Manages patients, prescriptions, dispensing, and medication tracking
 * Version: 1.2.0
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
register_activation_hook( __FILE__, [ Installer::class, 'install' ] );

class Plugin {
	public static function init() {
		if ( ! class_exists( 'PharmaSure\Core\TenantContext' ) ) {
			return;
		}

		if ( DB_VERSION !== get_site_option( 'pharmasure_clinical_db_version' ) ) {
			Installer::install();
		}

		add_action( 'rest_api_init', [ Rest\PatientController::class, 'register_routes' ] );
		add_action( 'rest_api_init', [ Rest\PrescriptionController::class, 'register_routes' ] );
		// Inventory registers the shared PharmaSure parent menu at the default
		// priority, so child pages must be added afterwards.
		add_action( 'admin_menu', [ Admin\ClinicalAdmin::class, 'register_pages' ], 30 );
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
		if ( ! $tenant_id || ! $branch_id || empty( $data['first_name'] ) || empty( $data['last_name'] ) ) {
			return new \WP_Error( 'invalid_patient', 'Tenant, branch, first name and last name are required' );
		}
		$branch_exists = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT id FROM {$this->wpdb->prefix}ps_branches WHERE id = %d AND tenant_id = %d AND is_active = 1", $branch_id, $tenant_id ) );
		if ( ! $branch_exists ) {
			return new \WP_Error( 'invalid_scope', 'Branch does not belong to the tenant' );
		}
		$patient_number = sanitize_text_field( $data['patient_number'] ?? '' );
		if ( ! $patient_number ) {
			$patient_number = 'PAT-' . strtoupper( wp_generate_password( 10, false, false ) );
		}
		$patient_data = [
			'tenant_id'    => $tenant_id,
			'branch_id'    => $branch_id,
			'patient_number' => $patient_number,
			'first_name'   => sanitize_text_field( $data['first_name'] ),
			'last_name'    => sanitize_text_field( $data['last_name'] ),
			'date_of_birth' => sanitize_text_field( $data['date_of_birth'] ?? '' ),
			'phone'        => sanitize_text_field( $data['phone'] ?? '' ),
			'email'        => sanitize_email( $data['email'] ?? '' ),
			'address'      => sanitize_textarea_field( $data['address'] ?? '' ),
			'status'       => 'active',
			'created_at'   => current_time( 'mysql', true ),
		];

		$inserted = $this->wpdb->insert( $this->wpdb->prefix . 'ps_patients', $patient_data );
		if ( ! $inserted ) {
			return new \WP_Error( 'db_error', 'Unable to create patient' );
		}

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
		if ( ! $tenant_id || ! $branch_id || empty( $data['patient_id'] ) || empty( $data['prescriber'] ) || empty( $data['prescription_date'] ) || empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return new \WP_Error( 'invalid_prescription', 'Tenant, branch, patient, prescriber, date and at least one item are required' );
		}
		$patient_exists = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT id FROM {$this->wpdb->prefix}ps_patients WHERE id = %d AND tenant_id = %d AND branch_id = %d AND status = 'active'", $data['patient_id'], $tenant_id, $branch_id ) );
		if ( ! $patient_exists ) {
			return new \WP_Error( 'invalid_scope', 'Patient does not belong to the active tenant and branch' );
		}

		$this->wpdb->query( 'START TRANSACTION' );
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

		if ( ! $this->wpdb->insert( $this->wpdb->prefix . 'ps_prescriptions', $prescription_data ) ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'db_error', 'Unable to create prescription' );
		}
		$prescription_id = $this->wpdb->insert_id;

		// Add prescription items
		foreach ( $data['items'] as $item ) {
			if ( empty( $item['dose'] ) || empty( $item['frequency'] ) || empty( $item['quantity'] ) || (float) $item['quantity'] <= 0 ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'invalid_prescription_item', 'Each item requires dose, frequency and a positive quantity' );
			}
			$inserted = $this->wpdb->insert(
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
			if ( ! $inserted ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'db_error', 'Unable to create prescription item' );
			}
		}
		$this->wpdb->query( 'COMMIT' );

		return [ 'id' => $prescription_id ];
	}

	/**
	 * Submit prescription for review
	 */
	public function submit_for_review( $tenant_id, $branch_id, $prescription_id ) {
		$updated = $this->wpdb->update(
			$this->wpdb->prefix . 'ps_prescriptions',
			[ 'status' => 'pending_review' ],
			[ 'id' => $prescription_id, 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'status' => 'draft' ]
		);
		return 1 === $updated ? [ 'status' => 'pending_review' ] : new \WP_Error( 'invalid_status', 'Only a tenant-owned draft prescription can be submitted' );
	}

	/**
	 * Approve prescription (pharmacist action)
	 */
	public function approve_prescription( $tenant_id, $branch_id, $prescription_id, $pharmacist_user_id ) {
		$updated = $this->wpdb->update(
			$this->wpdb->prefix . 'ps_prescriptions',
			[
				'status'            => 'approved',
				'approved_at'       => current_time( 'mysql', true ),
				'approved_by_user'  => $pharmacist_user_id,
			],
			[ 'id' => $prescription_id, 'tenant_id' => $tenant_id, 'branch_id' => $branch_id, 'status' => 'pending_review' ]
		);
		if ( 1 !== $updated ) {
			return new \WP_Error( 'invalid_status', 'Only a tenant-owned pending prescription can be approved' );
		}

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
		$this->wpdb->query( 'START TRANSACTION' );
		$prescription = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->wpdb->prefix}ps_prescriptions WHERE id = %d AND tenant_id = %d FOR UPDATE",
				$prescription_id,
				$tenant_id
			)
		);

		if ( ! $prescription || $prescription->status !== 'approved' || (int) $prescription->branch_id !== (int) $branch_id ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'invalid_status', 'Prescription not in approved state' );
		}
		if ( ! isset( $dispensing_data['total_amount_minor'] ) || (int) $dispensing_data['total_amount_minor'] < 0 ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'invalid_sale', 'A non-negative total amount is required' );
		}
		if ( ! class_exists( '\PharmaSure\Inventory\Services\StockAllocator' ) ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'inventory_unavailable', 'Inventory must be active before prescriptions can be dispensed.', [ 'status' => 503 ] );
		}
		$items = $this->wpdb->get_results( $this->wpdb->prepare(
			"SELECT id,drug_id,drug_name,quantity FROM {$this->wpdb->prefix}ps_prescription_items WHERE prescription_id=%d ORDER BY id FOR UPDATE",
			$prescription_id
		), ARRAY_A );
		if ( ! $items || array_filter( $items, static fn( $item ) => empty( $item['drug_id'] ) || (float) $item['quantity'] <= 0 ) ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'unmapped_prescription_item', 'Every prescription item must map to an inventory drug with a positive quantity before dispensing.', [ 'status' => 422 ] );
		}
		try {
		$inserted = $this->wpdb->insert(
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
		if ( ! $inserted ) {
			throw new \RuntimeException( 'Unable to create sale' );
		}

		$sale_id = (int) $this->wpdb->insert_id;
		$allocator = new \PharmaSure\Inventory\Services\StockAllocator();
		$allocations = array();
		foreach ( $items as $item ) {
			$allocated = $allocator->consume_fefo( $tenant_id, $branch_id, (int) $item['drug_id'], (float) $item['quantity'], 'dispensing', $sale_id, \PharmaSure\Core\TenantContext::instance()->get_correlation_id(), 'Prescription ' . $prescription_id );
			if ( is_wp_error( $allocated ) ) {
				$this->wpdb->query( 'ROLLBACK' );
				return $allocated;
			}
			$allocations[ (int) $item['id'] ] = $allocated;
		}

		// Update prescription status
		$updated = $this->wpdb->update(
			$this->wpdb->prefix . 'ps_prescriptions',
			[ 'status' => 'dispensed', 'dispensed_at' => current_time( 'mysql', true ) ],
			[ 'id' => $prescription_id, 'tenant_id' => $tenant_id, 'status' => 'approved' ]
		);
		if ( 1 !== $updated ) {
			throw new \RuntimeException( 'Prescription state changed before dispensing completed' );
		}
		$this->wpdb->query( 'COMMIT' );
		do_action( 'pharmasure_audit_log', [ 'tenant_id' => $tenant_id, 'actor_id' => get_current_user_id(), 'action' => 'prescription.dispensed', 'object_type' => 'prescription', 'object_id' => $prescription_id, 'details' => [ 'branch_id' => $branch_id, 'sale_id' => $sale_id, 'items' => count( $items ) ] ] );
		return [ 'sale_id' => $sale_id, 'allocations' => $allocations ];
		} catch ( \Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'dispensing_failed', 'No sale or stock movement was saved because dispensing could not be completed.', [ 'status' => 409 ] );
		}
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
		$scope = $this->scope( $request );
		if ( is_wp_error( $scope ) ) { return $scope; }
		$q = sanitize_text_field( $request->get_param( 'q' ) );

		$patients = $this->service->search_patients(
			$scope['tenant_id'],
			$scope['branch_id'],
			$q
		);

		return rest_ensure_response( $patients );
	}

	public function create( $request ) {
		$scope = $this->scope( $request );
		if ( is_wp_error( $scope ) ) { return $scope; }
		$params = $request->get_json_params();

		$result = $this->service->create_patient(
			$scope['tenant_id'],
			$scope['branch_id'],
			$params
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( [ 'success' => true, 'data' => $result ] );
	}

	private function scope( $request ) {
		$context = \PharmaSure\Core\TenantContext::instance();
		$tenant_id = (int) $context->get_tenant_id();
		$branch_id = (int) $context->get_branch_id();
		if ( ! $tenant_id ) { return new \WP_Error( 'tenant_context_required', 'No authorized tenant context is active.', [ 'status' => 403 ] ); }
		if ( ! $branch_id ) {
			$branch_id = absint( $request->get_header( 'X-PharmaSure-Branch' ) );
			if ( ! $branch_id || ! $context->set_branch( $branch_id ) ) {
				return new \WP_Error( 'branch_context_required', 'Supply an authorized branch in X-PharmaSure-Branch.', [ 'status' => 403 ] );
			}
		}
		return [ 'tenant_id' => $tenant_id, 'branch_id' => $branch_id ];
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
			'/prescriptions/(?P<id>\d+)/submit',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $controller, 'submit' ],
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
		$scope = $this->scope( $request );
		if ( is_wp_error( $scope ) ) { return $scope; }
		$params = $request->get_json_params();

		$result = $this->service->create_prescription(
			$scope['tenant_id'],
			$scope['branch_id'],
			$params
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( [ 'success' => true, 'data' => $result ] );
	}

	public function submit( $request ) {
		$scope = $this->scope( $request );
		if ( is_wp_error( $scope ) ) { return $scope; }
		return $this->service->submit_for_review( $scope['tenant_id'], $scope['branch_id'], intval( $request->get_param( 'id' ) ) );
	}

	public function approve( $request ) {
		$scope = $this->scope( $request );
		if ( is_wp_error( $scope ) ) { return $scope; }
		$prescription_id = intval( $request->get_param( 'id' ) );

		$result = $this->service->approve_prescription(
			$scope['tenant_id'],
			$scope['branch_id'],
			$prescription_id,
			get_current_user_id()
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	public function dispense( $request ) {
		$scope = $this->scope( $request );
		if ( is_wp_error( $scope ) ) { return $scope; }
		$prescription_id = intval( $request->get_param( 'id' ) );
		$params = $request->get_json_params();

		$result = $this->service->dispense_prescription(
			$scope['tenant_id'],
			$scope['branch_id'],
			$prescription_id,
			$params
		);

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( $result->get_error_data(), 400 );
		}

		return rest_ensure_response( $result );
	}

	private function scope( $request ) {
		$context = \PharmaSure\Core\TenantContext::instance();
		$tenant_id = (int) $context->get_tenant_id();
		$branch_id = (int) $context->get_branch_id();
		if ( ! $tenant_id ) { return new \WP_Error( 'tenant_context_required', 'No authorized tenant context is active.', [ 'status' => 403 ] ); }
		if ( ! $branch_id ) {
			$branch_id = absint( $request->get_header( 'X-PharmaSure-Branch' ) );
			if ( ! $branch_id || ! $context->set_branch( $branch_id ) ) {
				return new \WP_Error( 'branch_context_required', 'Supply an authorized branch in X-PharmaSure-Branch.', [ 'status' => 403 ] );
			}
		}
		return [ 'tenant_id' => $tenant_id, 'branch_id' => $branch_id ];
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
