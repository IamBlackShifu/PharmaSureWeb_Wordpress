<?php
namespace PharmaSure\Inventory\Admin;

final class InventoryAdmin {
	public static function register_pages() {
		add_menu_page( __( 'PharmaSure', 'pharmasure-inventory' ), __( 'PharmaSure', 'pharmasure-inventory' ), 'pharmasure_view_inventory', 'pharmasure-core', array( self::class, 'render_dashboard' ), 'dashicons-store', 26 );
		add_submenu_page( 'pharmasure-core', __( 'Inventory', 'pharmasure-inventory' ), __( 'Inventory', 'pharmasure-inventory' ), 'pharmasure_view_inventory', 'pharmasure-inventory', array( self::class, 'render_dashboard' ) );
	}

	public static function enqueue_assets( $hook ) {
		if ( ! str_contains( $hook, 'pharmasure' ) ) { return; }
		wp_enqueue_style( 'pharmasure-inventory-admin', plugins_url( '../../assets/admin.css', __FILE__ ), array(), \PharmaSure\Inventory\VERSION );
	}

	public static function render_dashboard() {
		if ( ! current_user_can( 'pharmasure_view_inventory' ) ) { wp_die( esc_html__( 'You cannot access inventory.', 'pharmasure-inventory' ) ); }
		global $wpdb;
		$context = \PharmaSure\Core\TenantContext::instance(); $tenant_id = (int) $context->get_tenant_id();
		$counts = array( 'Drugs' => 0, 'Suppliers' => 0, 'Batches' => 0, 'Low stock' => 0 );
		if ( $tenant_id ) {
			$counts['Drugs'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ps_drugs WHERE tenant_id=%d AND status='active'", $tenant_id ) );
			$counts['Suppliers'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ps_suppliers WHERE tenant_id=%d AND status='active'", $tenant_id ) );
			$counts['Batches'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ps_batches WHERE tenant_id=%d AND quantity_available>0", $tenant_id ) );
			$counts['Low stock'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM (SELECT d.id FROM {$wpdb->prefix}ps_drugs d LEFT JOIN {$wpdb->prefix}ps_stock_balances s ON s.tenant_id=d.tenant_id AND s.drug_id=d.id WHERE d.tenant_id=%d AND d.status='active' GROUP BY d.id,d.reorder_level HAVING COALESCE(SUM(s.quantity_available),0)<=d.reorder_level) low_stock", $tenant_id ) );
		}
		?>
		<div class="wrap pharmasure-inventory"><div class="ps-admin-hero"><div><span><?php esc_html_e( 'PharmaSure Operations', 'pharmasure-inventory' ); ?></span><h1><?php esc_html_e( 'Inventory control', 'pharmasure-inventory' ); ?></h1><p><?php esc_html_e( 'Tenant-scoped catalogue, batches and stock movement.', 'pharmasure-inventory' ); ?></p></div><div class="ps-scope"><?php echo $tenant_id ? esc_html( sprintf( 'Tenant #%d', $tenant_id ) ) : esc_html__( 'Tenant context required', 'pharmasure-inventory' ); ?></div></div><div class="ps-stat-grid">
		<?php foreach ( $counts as $label => $count ) : ?><div class="ps-stat"><span><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong></div><?php endforeach; ?>
		</div><div class="ps-admin-panel"><h2><?php esc_html_e( 'Operational API ready', 'pharmasure-inventory' ); ?></h2><p><?php esc_html_e( 'Drug catalogue, suppliers, stock summaries and atomic receipts are available under /wp-json/pharmasure/v1/inventory/.', 'pharmasure-inventory' ); ?></p><p class="description"><?php esc_html_e( 'The interactive inventory workspace is the next UI increment. All writes already enforce capabilities, tenant ownership and branch context.', 'pharmasure-inventory' ); ?></p></div></div>
		<?php
	}
}
