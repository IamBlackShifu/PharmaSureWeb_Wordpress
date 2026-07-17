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
		// Check entitlement: branches allowed
		$context = \PharmaSure\Core\TenantContext::instance();
		$context->load( $tenant_id );

		if ( ! $context->has_entitlement( 'multi_branch' ) ) {
			// Single tenant only gets main branch
			$existing = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(*) FROM {$this->table} WHERE tenant_id = %d AND status != 'deleted'",
					$tenant_id
				)
			);

			if ( $existing > 0 ) {
				return new \WP_Error( 'entitlement_exceeded', 'Multi-branch not enabled for this tenant' );
			}
		}

		// Check quota
		$max_branches = $context->get_quota( 'max_branches' );
		$branch_count = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE tenant_id = %d AND status != 'deleted'",
				$tenant_id
			)
		);

		if ( $branch_count >= $max_branches ) {
			return new \WP_Error( 'quota_exceeded', sprintf( 'Maximum %d branches reached', $max_branches ) );
		}

		$branch_data = [
			'tenant_id'       => $tenant_id,
			'name'            => sanitize_text_field( $data['name'] ?? '' ),
			'code'            => sanitize_key( $data['code'] ?? 'B' . str_pad( rand( 0, 99 ), 2, '0', STR_PAD_LEFT ) ),
			'address'         => sanitize_textarea_field( $data['address'] ?? '' ),
			'phone'           => sanitize_text_field( $data['phone'] ?? '' ),
			'email'           => sanitize_email( $data['email'] ?? '' ),
			'manager_user_id' => intval( $data['manager_user_id'] ?? 0 ),
			'timezone'        => sanitize_text_field( $data['timezone'] ?? wp_timezone_string() ),
			'receipt_header'  => sanitize_textarea_field( $data['receipt_header'] ?? '' ),
			'receipt_footer'  => sanitize_textarea_field( $data['receipt_footer'] ?? '' ),
			'status'          => 'active',
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
				"SELECT * FROM {$this->table} WHERE tenant_id = %d AND status != 'deleted' ORDER BY name ASC",
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

		$allowed_fields = [ 'name', 'phone', 'email', 'manager_user_id', 'receipt_header', 'receipt_footer', 'status' ];
		$update_data    = array_intersect_key( $data, array_flip( $allowed_fields ) );

		$this->wpdb->update( $this->table, $update_data, [ 'id' => $branch_id ] );

		return $this->get_branch( $branch_id );
	}
}
