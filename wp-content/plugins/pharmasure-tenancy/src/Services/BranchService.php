<?php
namespace PharmaSure\Tenancy\Services;

class BranchService {
	private $wpdb;
	private $table = 'ps_branches';

	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->table = $wpdb->prefix . $this->table;
	}

	/**
	 * Create a new branch for a tenant
	 */
	public function create_branch( $tenant_id, array $data ) {
		$context = \PharmaSure\Core\TenantContext::instance();
		$is_platform_admin = current_user_can( 'manage_network_options' ) || current_user_can( 'manage_options' );
		if ( ! $is_platform_admin && (int) $context->get_tenant_id() !== (int) $tenant_id ) {
			return new \WP_Error( 'unauthorized', 'Not authorized to create a branch for this tenant' );
		}

		$branch_count = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE tenant_id = %d AND is_active = 1",
				$tenant_id
			)
		);

		if ( ! $is_platform_admin && $branch_count > 0 && ! $context->has_entitlement( 'multi_branch' ) ) {
			return new \WP_Error( 'entitlement_exceeded', 'Multi-branch not enabled for this tenant' );
		}

		$max_branches = $context->check_quota( 'max_branches' );
		if ( ! $is_platform_admin && false !== $max_branches && $branch_count >= $max_branches ) {
			return new \WP_Error( 'quota_exceeded', sprintf( 'Maximum %d branches reached', $max_branches ) );
		}

		$branch_data = [
			'tenant_id'       => $tenant_id,
			'name'            => sanitize_text_field( $data['name'] ?? '' ),
			'code'            => strtoupper( sanitize_key( $data['code'] ?? 'B' . str_pad( rand( 0, 99 ), 2, '0', STR_PAD_LEFT ) ) ),
			'address_line_1'  => sanitize_text_field( $data['address'] ?? '' ),
			'phone'           => sanitize_text_field( $data['phone'] ?? '' ),
			'email'           => sanitize_email( $data['email'] ?? '' ),
			'manager_name'    => sanitize_text_field( $data['manager_name'] ?? '' ),
			'timezone'        => sanitize_text_field( $data['timezone'] ?? wp_timezone_string() ),
			'receipt_header_text' => sanitize_textarea_field( $data['receipt_header'] ?? '' ),
			'receipt_footer_text' => sanitize_textarea_field( $data['receipt_footer'] ?? '' ),
			'is_active'       => 1,
			'created_at'      => current_time( 'mysql', true ),
			'created_by'      => get_current_user_id(),
		];

		$inserted = $this->wpdb->insert( $this->table, $branch_data );

		if ( ! $inserted ) {
			return new \WP_Error( 'db_error', 'Failed to create branch' );
		}

		do_action( 'pharmasure_audit_log', [
			'tenant_id'   => $tenant_id,
			'action'      => 'branch.created',
			'object_type' => 'branch',
			'object_id'   => $this->wpdb->insert_id,
			'status'      => 'success',
		] );

		return [ 'id' => $this->wpdb->insert_id ];
	}

	/**
	 * Get branch by ID
	 */
	public function get_branch( $branch_id ) {
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE id = %d",
				$branch_id
			),
			ARRAY_A
		);
	}

	/**
	 * List branches for a tenant
	 */
	public function list_branches( $tenant_id ) {
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE tenant_id = %d AND is_active = 1 ORDER BY name ASC",
				$tenant_id
			),
			ARRAY_A
		);
	}

	/**
	 * Update branch
	 */
	public function update_branch( $branch_id, array $data ) {
		$branch = $this->get_branch( $branch_id );
		if ( ! $branch ) {
			return new \WP_Error( 'not_found', 'Branch not found' );
		}
		$context = \PharmaSure\Core\TenantContext::instance();
		$is_platform_admin = current_user_can( 'manage_network_options' );
		if ( ! $is_platform_admin && (int) $branch['tenant_id'] !== (int) $context->get_tenant_id() ) {
			return new \WP_Error( 'unauthorized', 'Not authorized to update this branch' );
		}

		$field_map = [
			'name' => 'name',
			'phone' => 'phone',
			'email' => 'email',
			'manager_name' => 'manager_name',
			'receipt_header' => 'receipt_header_text',
			'receipt_footer' => 'receipt_footer_text',
			'is_active' => 'is_active',
		];
		$update_data = [];
		foreach ( $field_map as $input => $column ) {
			if ( array_key_exists( $input, $data ) ) {
				$update_data[ $column ] = 'is_active' === $column ? (int) (bool) $data[ $input ] : sanitize_text_field( $data[ $input ] );
			}
		}

		$this->wpdb->update( $this->table, $update_data, [ 'id' => $branch_id ] );

		return $this->get_branch( $branch_id );
	}
}
