<?php
namespace PharmaSure\Tenancy\Services;

use PharmaSure\Core\TenantContext;

class TenantService {
	private $wpdb;
	private $table = 'ps_tenants';

	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->table = $wpdb->prefix . $this->table;
	}

	/**
	 * Create a new tenant (onboarding workflow)
	 */
	public function create_tenant( array $data ) {
		$required = [ 'legal_name', 'trading_name', 'slug', 'owner_email', 'country', 'currency' ];
		$missing  = array_filter( $required, fn( $k ) => empty( $data[ $k ] ), ARRAY_FILTER_USE_KEY );

		if ( ! empty( $missing ) ) {
			return new \WP_Error(
				'missing_fields',
				'Missing required fields: ' . implode( ', ', array_keys( $missing ) )
			);
		}

		// Validate slug is unique
		$exists = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE slug = %s AND status != 'deleted'",
				$data['slug']
			)
		);

		if ( $exists ) {
			return new \WP_Error( 'slug_exists', 'Tenant slug already exists' );
		}

		// Create tenant record
		$tenant_data = [
			'legal_name'          => sanitize_text_field( $data['legal_name'] ),
			'trading_name'        => sanitize_text_field( $data['trading_name'] ),
			'slug'                => sanitize_key( $data['slug'] ),
			'owner_email'         => sanitize_email( $data['owner_email'] ),
			'country'             => sanitize_text_field( $data['country'] ?? '' ),
			'timezone'            => sanitize_text_field( $data['timezone'] ?? 'UTC' ),
			'currency'            => sanitize_text_field( $data['currency'] ?? 'USD' ),
			'plan_type'           => sanitize_text_field( $data['plan_type'] ?? 'starter' ),
			'status'              => 'active',
			'trial_ends_at'       => isset( $data['trial_days'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( "+{$data['trial_days']} days" ) ) : null,
			'created_at'          => current_time( 'mysql', true ),
			'created_by'          => get_current_user_id(),
		];

		$inserted = $this->wpdb->insert( $this->table, $tenant_data );

		if ( ! $inserted ) {
			return new \WP_Error( 'db_error', 'Failed to create tenant' );
		}

		$tenant_id = $this->wpdb->insert_id;

		// Create default main branch
		$branch_service = new BranchService();
		$branch_result  = $branch_service->create_branch(
			$tenant_id,
			[
				'name'    => 'Main Branch',
				'code'    => 'MAIN',
				'address' => $data['address'] ?? '',
				'phone'   => $data['phone'] ?? '',
				'email'   => $data['owner_email'],
			]
		);

		if ( is_wp_error( $branch_result ) ) {
			// Rollback tenant creation
			$this->wpdb->delete( $this->table, [ 'id' => $tenant_id ] );
			return $branch_result;
		}

		// Create tenant owner user and membership
		$owner_id = $this->create_tenant_owner( $tenant_id, $data['owner_email'] );

		// Log audit event
		do_action( 'pharmasure_audit_log', [
			'tenant_id'   => $tenant_id,
			'action'      => 'tenant.created',
			'object_type' => 'tenant',
			'object_id'   => $tenant_id,
			'status'      => 'success',
			'details'     => [ 'email' => $data['owner_email'] ],
		] );

		return [ 'id' => $tenant_id, 'owner_id' => $owner_id ];
	}

	/**
	 * Get tenant by ID
	 */
	public function get_tenant( $tenant_id ) {
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d",
				$tenant_id
			),
			ARRAY_A
		);
	}

	/**
	 * Get tenant by slug
	 */
	public function get_tenant_by_slug( $slug ) {
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE slug = %s AND status != 'deleted'",
				$slug
			),
			ARRAY_A
		);
	}

	/**
	 * Update tenant information
	 */
	public function update_tenant( $tenant_id, array $data ) {
		$context = TenantContext::instance();
		if ( ! current_user_can( 'pharmasure_manage_tenant' ) && $context->get_tenant_id() !== $tenant_id ) {
			return new \WP_Error( 'unauthorized', 'Not authorized to update this tenant' );
		}

		$allowed_fields = [ 'legal_name', 'trading_name', 'timezone', 'currency', 'address', 'phone', 'status' ];
		$update_data    = array_intersect_key( $data, array_flip( $allowed_fields ) );

		if ( isset( $update_data['status'] ) && $update_data['status'] === 'suspended' ) {
			// Log suspension event
			do_action( 'pharmasure_audit_log', [
				'tenant_id'   => $tenant_id,
				'action'      => 'tenant.suspended',
				'object_type' => 'tenant',
				'object_id'   => $tenant_id,
				'details'     => [ 'reason' => $data['suspension_reason'] ?? '' ],
			] );
		}

		$this->wpdb->update( $this->table, $update_data, [ 'id' => $tenant_id ] );
		return $this->get_tenant( $tenant_id );
	}

	/**
	 * List tenants with pagination
	 */
	public function list_tenants( $args = [] ) {
		$per_page = intval( $args['per_page'] ?? 20 );
		$page     = intval( $args['page'] ?? 1 );
		$status   = sanitize_text_field( $args['status'] ?? 'active' );

		$offset = ( $page - 1 ) * $per_page;

		$where = "WHERE status = %s";
		$where_params = [ $status ];

		if ( ! empty( $args['search'] ) ) {
			$where          .= " AND (legal_name LIKE %s OR slug LIKE %s)";
			$search_term     = '%' . $this->wpdb->esc_like( $args['search'] ) . '%';
			$where_params[]  = $search_term;
			$where_params[]  = $search_term;
		}

		$query = "SELECT * FROM {$this->table} $where ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$query = $this->wpdb->prepare(
			$query,
			array_merge( $where_params, [ $per_page, $offset ] )
		);

		$rows = $this->wpdb->get_results( $query, ARRAY_A );

		$total = $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} $where", ...$where_params )
		);

		return [
			'data'  => $rows,
			'total' => intval( $total ),
			'pages' => ceil( $total / $per_page ),
		];
	}

	/**
	 * Create tenant owner user and membership
	 */
	private function create_tenant_owner( $tenant_id, $owner_email ) {
		$user = get_user_by( 'email', $owner_email );

		if ( ! $user ) {
			$user_id = wp_create_user(
				sanitize_user( explode( '@', $owner_email )[0] ),
				wp_generate_password(),
				$owner_email
			);
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
		} else {
			$user_id = $user->ID;
		}

		// Create membership
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'ps_tenant_memberships',
			[
				'tenant_id'   => $tenant_id,
				'user_id'     => $user_id,
				'role'        => 'owner',
				'status'      => 'active',
				'created_at'  => current_time( 'mysql', true ),
			],
			[ '%d', '%d', '%s', '%s', '%s' ]
		);

		return $user_id;
	}
}
