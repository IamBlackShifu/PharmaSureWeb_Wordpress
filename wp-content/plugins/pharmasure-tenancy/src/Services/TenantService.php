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
		if ( is_multisite() && current_user_can( 'manage_network_options' ) ) {
			return ( new TenantProvisioningService() )->create( $data );
		}
		$required = [ 'legal_name', 'trading_name', 'slug', 'owner_email', 'country', 'currency' ];
		$missing  = array_values( array_filter( $required, fn( $field ) => empty( $data[ $field ] ) ) );

		if ( ! empty( $missing ) ) {
			return new \WP_Error(
				'missing_fields',
				'Missing required fields: ' . implode( ', ', $missing )
			);
		}

		// Validate slug is unique
		$exists = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE slug = %s AND status != 'archived'",
				$data['slug']
			)
		);

		if ( $exists ) {
			return new \WP_Error( 'slug_exists', 'Tenant slug already exists' );
		}

		// Create tenant record
		$tenant_data = [
			'name'                => sanitize_text_field( $data['legal_name'] ),
			'trading_name'        => sanitize_text_field( $data['trading_name'] ),
			'slug'                => sanitize_key( $data['slug'] ),
			'primary_contact_email' => sanitize_email( $data['owner_email'] ),
			'country'             => sanitize_text_field( $data['country'] ?? '' ),
			'timezone'            => sanitize_text_field( $data['timezone'] ?? 'UTC' ),
			'currency'            => sanitize_text_field( $data['currency'] ?? 'USD' ),
			'status'              => 'active',
			'onboarded_at'        => current_time( 'mysql', true ),
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
				"SELECT * FROM {$this->table} WHERE slug = %s AND status != 'archived'",
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

		$field_map = [
			'legal_name' => 'name',
			'trading_name' => 'trading_name',
			'timezone' => 'timezone',
			'currency' => 'currency',
			'address' => 'address_line_1',
			'phone' => 'primary_contact_phone',
			'status' => 'status',
		];
		$update_data = [];
		foreach ( $field_map as $input => $column ) {
			if ( array_key_exists( $input, $data ) ) {
				$update_data[ $column ] = sanitize_text_field( $data[ $input ] );
			}
		}

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
			$where          .= " AND (name LIKE %s OR trading_name LIKE %s OR slug LIKE %s)";
			$search_term     = '%' . $this->wpdb->esc_like( $args['search'] ) . '%';
			$where_params[]  = $search_term;
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
			$base_username = sanitize_user( explode( '@', $owner_email )[0], true );
			$base_username = $base_username ?: 'tenant-owner';
			$username      = $base_username;
			$suffix        = 0;
			while ( username_exists( $username ) ) {
				$username = $base_username . '-' . $tenant_id . ( $suffix ? '-' . $suffix : '' );
				++$suffix;
			}
			$user_id = wp_create_user(
				$username,
				wp_generate_password(),
				$owner_email
			);
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
			// Network-level onboarding must not make a tenant owner a member of
			// the platform/root site. The tenant provisioning workflow attaches
			// the owner only to their mapped tenant site.
			if ( is_multisite() && is_main_site() ) {
				remove_user_from_blog( $user_id, get_current_blog_id() );
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
				'is_admin'    => 1,
				'is_active'   => 1,
				'activated_at'=> current_time( 'mysql', true ),
				'created_at'  => current_time( 'mysql', true ),
			],
			[ '%d', '%d', '%s', '%d', '%d', '%s', '%s' ]
		);

		return $user_id;
	}
}
