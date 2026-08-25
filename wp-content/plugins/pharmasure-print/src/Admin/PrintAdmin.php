<?php
namespace PharmaSure\PrintModule\Admin;

final class PrintAdmin {
	public static function register_page() {
		add_submenu_page( 'pharmasure-core', 'Printing', 'Printing', 'pharmasure_print_documents', 'pharmasure-print', array( self::class, 'render' ) );
	}

	public static function render() {
		global $wpdb;
		$context = \PharmaSure\Core\TenantContext::instance();
		$tenant_id = (int) $context->get_tenant_id();
		$branches = $tenant_id ? $wpdb->get_results( $wpdb->prepare( "SELECT id,name FROM {$wpdb->prefix}ps_branches WHERE tenant_id=%d AND is_active=1 ORDER BY name", $tenant_id ) ) : array();
		$branches = array_values( array_filter( $branches, static fn( $branch ) => $context->can_access_branch( (int) $branch->id ) ) );
		$branch_id = absint( $_REQUEST['branch_id'] ?? $context->get_branch_id() ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only scope selector.
		if ( $branch_id && ! $context->set_branch( $branch_id ) ) { $branch_id = 0; }
		$notice = null;
		if ( $tenant_id && $branch_id && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['pharmasure_print_nonce'] ) ) {
			check_admin_referer( 'pharmasure_create_print_job', 'pharmasure_print_nonce' );
			$service = new \PharmaSure\PrintModule\Services\PrintService();
			$result = $service->create_job(
				$tenant_id,
				sanitize_key( wp_unslash( $_POST['document_type'] ?? '' ) ),
				absint( $_POST['entity_id'] ?? 0 ),
				get_current_user_id(),
				$context->get_correlation_id(),
				$branch_id
			);
			$notice = is_wp_error( $result )
				? array( 'type' => 'error', 'message' => $result->get_error_message() )
				: array( 'type' => 'success', 'message' => 'Print job created. Use Print / Open below.' );
		}
		$service = new \PharmaSure\PrintModule\Services\PrintService();
		$jobs = $tenant_id && $branch_id ? array_map( static fn( $job ) => (object) $job, $service->list_jobs( $tenant_id, 25, $branch_id ) ) : array();
		?>
		<div class="wrap"><h1>PharmaSure Printing</h1>
		<p>Create print jobs from dispensing, inventory and sales workflows. Browser printing is currently enabled.</p>
		<?php if ( $notice ) : ?><div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div><?php endif; ?>
		<?php if ( ! $tenant_id ) : ?><div class="notice notice-warning"><p>Select or sign in to a tenant context to view print jobs.</p></div><?php endif; ?>
		<?php if ( $tenant_id ) : ?><form method="get"><input type="hidden" name="page" value="pharmasure-print"><label for="branch_id"><b>Authorized branch</b></label> <select id="branch_id" name="branch_id" required><option value="">Select branch</option><?php foreach ( $branches as $branch ) : ?><option value="<?php echo esc_attr( $branch->id ); ?>" <?php selected( $branch_id, $branch->id ); ?>><?php echo esc_html( $branch->name ); ?></option><?php endforeach; ?></select> <?php submit_button( 'Use branch', 'secondary', 'submit', false ); ?></form><?php endif; ?>
		<?php if ( $tenant_id && $branch_id ) : ?>
		<form method="post" style="display:flex;gap:10px;align-items:end;margin:20px 0;padding:16px;background:#fff;border:1px solid #ccd0d4;">
			<input type="hidden" name="branch_id" value="<?php echo esc_attr( $branch_id ); ?>">
			<div><label for="document_type"><b>Document</b></label><br><select id="document_type" name="document_type"><option value="medication_label">Medication labels</option><option value="dispensing_summary">Dispensing summary</option><option value="stock_receipt">Goods received note</option><option value="sale_receipt">Sale receipt</option></select></div>
			<div><label for="entity_id"><b>Prescription, receipt or sale ID</b></label><br><input id="entity_id" name="entity_id" type="number" min="1" required></div>
			<?php wp_nonce_field( 'pharmasure_create_print_job', 'pharmasure_print_nonce' ); ?>
			<?php submit_button( 'Create print job', 'primary', 'submit', false ); ?>
		</form>
		<?php endif; ?>
		<table class="widefat striped"><thead><tr><th>Job</th><th>Document</th><th>Entity</th><th>Status</th><th>Reprint</th><th>Created</th><th>Action</th></tr></thead><tbody>
		<?php if ( ! $jobs ) : ?><tr><td colspan="7">No print jobs.</td></tr><?php else : foreach ( $jobs as $job ) :
			$print_url = wp_nonce_url( admin_url( 'admin-post.php?action=pharmasure_print_document&job_id=' . $job->id ), 'pharmasure_print_document' ); ?>
		<tr><td><?php echo esc_html( $job->id ); ?></td><td><?php echo esc_html( $job->document_type ); ?></td><td><?php echo esc_html( $job->entity_id ); ?></td><td><?php echo esc_html( $job->status ); ?></td><td><?php echo $job->is_reprint ? 'Yes' : 'No'; ?></td><td><?php echo esc_html( $job->created_at ); ?></td><td><a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( $print_url ); ?>">Print / Open</a>
		<form method="post" style="display:inline"><input type="hidden" name="branch_id" value="<?php echo esc_attr( $branch_id ); ?>"><input type="hidden" name="document_type" value="<?php echo esc_attr( $job->document_type ); ?>"><input type="hidden" name="entity_id" value="<?php echo esc_attr( $job->entity_id ); ?>"><?php wp_nonce_field( 'pharmasure_create_print_job', 'pharmasure_print_nonce', false ); ?><button class="button" type="submit">Create reprint</button></form></td></tr>
		<?php endforeach; endif; ?></tbody></table></div>
		<?php
	}
}
