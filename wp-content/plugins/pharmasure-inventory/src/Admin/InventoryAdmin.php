<?php
namespace PharmaSure\Inventory\Admin;

final class InventoryAdmin {
	public static function register_pages() {
		add_menu_page( __( 'PharmaSure', 'pharmasure-inventory' ), __( 'PharmaSure', 'pharmasure-inventory' ), 'pharmasure_view_inventory', 'pharmasure-core', array( self::class, 'render_dashboard' ), 'dashicons-store', 26 );
		add_submenu_page( 'pharmasure-core', __( 'Inventory', 'pharmasure-inventory' ), __( 'Inventory', 'pharmasure-inventory' ), 'pharmasure_view_inventory', 'pharmasure-inventory', array( self::class, 'render_workspace' ) );
	}

	public static function enqueue_assets( $hook ) {
		if ( ! str_contains( $hook, 'pharmasure' ) ) { return; }
		wp_enqueue_style( 'pharmasure-inventory-admin', plugins_url( '../../assets/admin.css', __FILE__ ), array(), \PharmaSure\Inventory\VERSION );
	}

	public static function render_dashboard() {
		if ( ! current_user_can( 'pharmasure_view_inventory' ) ) { wp_die( esc_html__( 'You cannot access inventory.', 'pharmasure-inventory' ) ); }
		global $wpdb;
		$context = \PharmaSure\Core\TenantContext::instance(); $tenant_id = (int) $context->get_tenant_id(); $branch_id = (int) $context->get_branch_id();
		$tenant_name = ''; $branch_name = ''; $metrics = array( 'Active medicines' => 0, 'Units available' => 0, 'Low stock' => 0, 'Expiring ≤90 days' => 0 ); $movements = array();
		if ( $tenant_id ) {
			$tenant_name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT trading_name FROM {$wpdb->prefix}ps_tenants WHERE id=%d", $tenant_id ) );
			if ( $branch_id ) { $branch_name = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}ps_branches WHERE id=%d AND tenant_id=%d AND is_active=1", $branch_id, $tenant_id ) ); }
			$branch_filter = $branch_id ? ' AND branch_id=%d' : ''; $scope_args = $branch_id ? array( $tenant_id, $branch_id ) : array( $tenant_id );
			$metrics['Active medicines'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ps_drugs WHERE tenant_id=%d AND status='active'", $tenant_id ) );
			$metrics['Units available'] = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity_available),0) FROM {$wpdb->prefix}ps_stock_balances WHERE tenant_id=%d{$branch_filter}", ...$scope_args ) );
			$join_branch = $branch_id ? ' AND s.branch_id=%d' : ''; $low_args = $branch_id ? array( $branch_id, $tenant_id ) : array( $tenant_id );
			$metrics['Low stock'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM (SELECT d.id FROM {$wpdb->prefix}ps_drugs d LEFT JOIN {$wpdb->prefix}ps_stock_balances s ON s.tenant_id=d.tenant_id AND s.drug_id=d.id{$join_branch} WHERE d.tenant_id=%d AND d.status='active' GROUP BY d.id,d.reorder_level HAVING COALESCE(SUM(s.quantity_available),0)<=d.reorder_level) low_stock", ...$low_args ) );
			$metrics['Expiring ≤90 days'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ps_batches WHERE tenant_id=%d{$branch_filter} AND status='active' AND quantity_available>0 AND expiry_date<=DATE_ADD(UTC_DATE(),INTERVAL 90 DAY)", ...$scope_args ) );
			$move_filter = $branch_id ? ' AND m.branch_id=%d' : ''; $move_args = $branch_id ? array( $tenant_id, $branch_id, 8 ) : array( $tenant_id, 8 );
			$movements = $wpdb->get_results( $wpdb->prepare( "SELECT m.created_at,m.movement_type,m.quantity_delta,m.reference_type,m.reference_id,d.sku,d.name FROM {$wpdb->prefix}ps_stock_movements m JOIN {$wpdb->prefix}ps_drugs d ON d.id=m.drug_id AND d.tenant_id=m.tenant_id WHERE m.tenant_id=%d{$move_filter} ORDER BY m.created_at DESC,m.id DESC LIMIT %d", ...$move_args ), ARRAY_A );
		}
		?>
		<div class="wrap pharmasure-inventory"><header class="ps-ops-header"><div><span class="ps-kicker"><?php esc_html_e( 'Operations overview', 'pharmasure-inventory' ); ?></span><h1><?php echo esc_html( $tenant_name ?: __( 'PharmaSure workspace', 'pharmasure-inventory' ) ); ?></h1><p><?php esc_html_e( 'Live inventory posture and recent medicine movement.', 'pharmasure-inventory' ); ?></p></div><div class="ps-scope"><span><?php esc_html_e( 'Working scope', 'pharmasure-inventory' ); ?></span><strong><?php echo esc_html( $branch_name ?: __( 'All authorized branches', 'pharmasure-inventory' ) ); ?></strong></div></header>
		<div class="ps-stat-grid"><?php foreach ( $metrics as $label => $count ) : ?><section class="ps-stat"><span><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( number_format_i18n( $count, is_float( $count ) ? 3 : 0 ) ); ?></strong><small><?php echo esc_html( 'Low stock' === $label && $count ? __( 'Review required', 'pharmasure-inventory' ) : __( 'Current scope', 'pharmasure-inventory' ) ); ?></small></section><?php endforeach; ?></div>
		<div class="ps-ops-layout"><main class="ps-activity" aria-labelledby="ps-activity-title"><div class="ps-section-heading"><div><span class="ps-kicker"><?php esc_html_e( 'Stock ledger', 'pharmasure-inventory' ); ?></span><h2 id="ps-activity-title"><?php esc_html_e( 'Recent movement', 'pharmasure-inventory' ); ?></h2></div><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=pharmasure-reports' ) ); ?>"><?php esc_html_e( 'Open reports', 'pharmasure-inventory' ); ?></a></div><?php if ( $movements ) : ?><table class="widefat striped ps-responsive-table"><thead><tr><th><?php esc_html_e( 'Time', 'pharmasure-inventory' ); ?></th><th><?php esc_html_e( 'Medicine', 'pharmasure-inventory' ); ?></th><th><?php esc_html_e( 'Movement', 'pharmasure-inventory' ); ?></th><th><?php esc_html_e( 'Quantity', 'pharmasure-inventory' ); ?></th><th><?php esc_html_e( 'Reference', 'pharmasure-inventory' ); ?></th></tr></thead><tbody><?php foreach ( $movements as $movement ) : ?><tr><td data-label="Time"><time datetime="<?php echo esc_attr( mysql2date( 'c', $movement['created_at'], false ) ); ?>"><?php echo esc_html( mysql2date( 'M j, H:i', $movement['created_at'], false ) ); ?></time></td><td data-label="Medicine"><strong><?php echo esc_html( $movement['name'] ); ?></strong><small class="ps-code"><?php echo esc_html( $movement['sku'] ); ?></small></td><td data-label="Movement"><span class="ps-movement ps-movement--<?php echo esc_attr( sanitize_html_class( $movement['movement_type'] ) ); ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', $movement['movement_type'] ) ) ); ?></span></td><td data-label="Quantity" class="ps-quantity"><?php echo esc_html( ( $movement['quantity_delta'] > 0 ? '+' : '' ) . number_format_i18n( $movement['quantity_delta'], 3 ) ); ?></td><td data-label="Reference" class="ps-code"><?php echo esc_html( $movement['reference_type'] . ' #' . $movement['reference_id'] ); ?></td></tr><?php endforeach; ?></tbody></table><?php else : ?><div class="ps-empty-state"><?php esc_html_e( 'No stock movement has been recorded in this scope.', 'pharmasure-inventory' ); ?></div><?php endif; ?></main>
		<aside class="ps-inspector" aria-labelledby="ps-inspector-title"><span class="ps-kicker"><?php esc_html_e( 'Attention queue', 'pharmasure-inventory' ); ?></span><h2 id="ps-inspector-title"><?php esc_html_e( 'Operational signals', 'pharmasure-inventory' ); ?></h2><dl><div><dt><?php esc_html_e( 'Low-stock medicines', 'pharmasure-inventory' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $metrics['Low stock'] ) ); ?></dd></div><div><dt><?php esc_html_e( 'Batches expiring soon', 'pharmasure-inventory' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $metrics['Expiring ≤90 days'] ) ); ?></dd></div></dl><nav aria-label="<?php esc_attr_e( 'Quick actions', 'pharmasure-inventory' ); ?>"><a href="<?php echo esc_url( admin_url( 'admin.php?page=pharmasure-inventory' ) ); ?>"><?php esc_html_e( 'Review inventory', 'pharmasure-inventory' ); ?><span aria-hidden="true">→</span></a><a href="<?php echo esc_url( admin_url( 'admin.php?page=pharmasure-pos' ) ); ?>"><?php esc_html_e( 'Open point of sale', 'pharmasure-inventory' ); ?><span aria-hidden="true">→</span></a><a href="<?php echo esc_url( admin_url( 'admin.php?page=pharmasure-reports' ) ); ?>"><?php esc_html_e( 'Review movements', 'pharmasure-inventory' ); ?><span aria-hidden="true">→</span></a></nav></aside></div></div>
		<?php
	}

	/** Render the branch-scoped inventory operations workspace. */
	public static function render_workspace() {
		if ( ! current_user_can( 'pharmasure_view_inventory' ) ) {
			wp_die( esc_html__( 'You cannot access inventory.', 'pharmasure-inventory' ) );
		}

		global $wpdb;
		$context   = \PharmaSure\Core\TenantContext::instance();
		$tenant_id = (int) $context->get_tenant_id();
		$branch_id = (int) $context->get_branch_id();
		if ( ! $tenant_id ) {
			wp_die( esc_html__( 'No authorized pharmacy context is available.', 'pharmasure-inventory' ) );
		}

		$views = array(
			'catalogue' => __( 'Catalogue', 'pharmasure-inventory' ),
			'batches'   => __( 'Batches', 'pharmasure-inventory' ),
			'receipts'  => __( 'Receipts', 'pharmasure-inventory' ),
			'movements' => __( 'Movements', 'pharmasure-inventory' ),
			'low-stock' => __( 'Low stock', 'pharmasure-inventory' ),
			'expiry'    => __( 'Expiry', 'pharmasure-inventory' ),
			'suppliers' => __( 'Suppliers', 'pharmasure-inventory' ),
		);
		$requested_view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'catalogue';
		$view           = isset( $views[ $requested_view ] ) ? $requested_view : 'catalogue';
		$search         = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

		$scope = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT t.trading_name,t.currency,b.name branch_name FROM {$wpdb->prefix}ps_tenants t LEFT JOIN {$wpdb->prefix}ps_branches b ON b.id=%d AND b.tenant_id=t.id AND b.is_active=1 WHERE t.id=%d",
				$branch_id,
				$tenant_id
			),
			ARRAY_A
		) ?: array();
		$currency = strtoupper( (string) ( $scope['currency'] ?? 'USD' ) );
		$summary  = self::workspace_summary( $tenant_id, $branch_id );
		$payload  = self::workspace_payload( $view, $tenant_id, $branch_id, $search );
		?>
		<div class="wrap pharmasure-inventory ps-inventory-workspace">
			<a class="screen-reader-shortcut" href="#ps-inventory-canvas"><?php esc_html_e( 'Skip to inventory results', 'pharmasure-inventory' ); ?></a>
			<header class="ps-workspace-header">
				<div>
					<span class="ps-kicker"><?php esc_html_e( 'Inventory control', 'pharmasure-inventory' ); ?></span>
					<h1><?php esc_html_e( 'Inventory workspace', 'pharmasure-inventory' ); ?></h1>
					<p><?php esc_html_e( 'One operational surface for medicine availability, traceability and replenishment.', 'pharmasure-inventory' ); ?></p>
				</div>
				<div class="ps-workspace-scope" aria-label="<?php esc_attr_e( 'Current inventory scope', 'pharmasure-inventory' ); ?>">
					<span><?php echo esc_html( $scope['trading_name'] ?? __( 'Current pharmacy', 'pharmasure-inventory' ) ); ?></span>
					<strong><?php echo esc_html( $scope['branch_name'] ?? __( 'All authorized branches', 'pharmasure-inventory' ) ); ?></strong>
					<small><?php echo esc_html( $currency ); ?> &middot; <?php esc_html_e( 'Live ledger', 'pharmasure-inventory' ); ?></small>
				</div>
			</header>

			<section class="ps-inventory-pulse" aria-label="<?php esc_attr_e( 'Inventory summary', 'pharmasure-inventory' ); ?>">
				<?php self::render_pulse( __( 'Active medicines', 'pharmasure-inventory' ), $summary['products'], 'catalogue' ); ?>
				<?php self::render_pulse( __( 'Units available', 'pharmasure-inventory' ), $summary['units'], 'batches', true ); ?>
				<?php self::render_pulse( __( 'Low-stock queue', 'pharmasure-inventory' ), $summary['low_stock'], 'low-stock', false, $summary['low_stock'] > 0 ); ?>
				<?php self::render_pulse( __( 'Expiry attention', 'pharmasure-inventory' ), $summary['expiry'], 'expiry', false, $summary['expiry'] > 0 ); ?>
			</section>

			<nav class="ps-inventory-nav" aria-label="<?php esc_attr_e( 'Inventory workspace sections', 'pharmasure-inventory' ); ?>">
				<?php foreach ( $views as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'pharmasure-inventory', 'view' => $slug ), admin_url( 'admin.php' ) ) ); ?>" <?php echo $view === $slug ? 'aria-current="page"' : ''; ?>>
						<span aria-hidden="true"><?php echo esc_html( self::view_index( $slug ) ); ?></span><?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="ps-inventory-layout">
				<main id="ps-inventory-canvas" class="ps-work-canvas" tabindex="-1">
					<div class="ps-canvas-heading">
						<div><span class="ps-kicker"><?php echo esc_html( $payload['eyebrow'] ); ?></span><h2><?php echo esc_html( $payload['title'] ); ?></h2><p><?php echo esc_html( $payload['description'] ); ?></p></div>
						<div class="ps-canvas-actions">
							<?php if ( 'catalogue' === $view ) : ?>
								<form method="get" class="ps-inventory-search" role="search"><input type="hidden" name="page" value="pharmasure-inventory"><input type="hidden" name="view" value="catalogue"><label class="screen-reader-text" for="ps-inventory-query"><?php esc_html_e( 'Search medicine catalogue', 'pharmasure-inventory' ); ?></label><input id="ps-inventory-query" name="q" type="search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search SKU, barcode or medicine', 'pharmasure-inventory' ); ?>"><button type="submit" class="button"><?php esc_html_e( 'Search', 'pharmasure-inventory' ); ?></button></form>
							<?php endif; ?>
							<span class="ps-result-count"><?php echo esc_html( number_format_i18n( count( $payload['rows'] ) ) ); ?> <?php esc_html_e( 'records', 'pharmasure-inventory' ); ?></span>
						</div>
					</div>
					<?php self::render_workspace_table( $view, $payload['rows'], $currency ); ?>
				</main>

				<aside class="ps-detail-inspector" aria-labelledby="ps-context-title">
					<span class="ps-kicker"><?php esc_html_e( 'Context inspector', 'pharmasure-inventory' ); ?></span>
					<h2 id="ps-context-title"><?php echo esc_html( $payload['inspector_title'] ); ?></h2>
					<p><?php echo esc_html( $payload['inspector_copy'] ); ?></p>
					<dl><?php foreach ( $payload['facts'] as $label => $value ) : ?><div><dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $value ); ?></dd></div><?php endforeach; ?></dl>
					<div class="ps-inspector-note"><strong><?php esc_html_e( 'Scope protection', 'pharmasure-inventory' ); ?></strong><span><?php esc_html_e( 'Tenant and branch are resolved from your authenticated session. They cannot be changed from this screen.', 'pharmasure-inventory' ); ?></span></div>
				</aside>
			</div>
		</div>
		<?php
	}

	private static function workspace_summary( $tenant_id, $branch_id ) {
		global $wpdb;
		$branch_sql  = $branch_id ? ' AND branch_id=%d' : '';
		$scope_args  = $branch_id ? array( $tenant_id, $branch_id ) : array( $tenant_id );
		$stock_join  = $branch_id ? ' AND s.branch_id=%d' : '';
		$stock_args  = $branch_id ? array( $branch_id, $tenant_id ) : array( $tenant_id );
		$products    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ps_drugs WHERE tenant_id=%d AND status='active'", $tenant_id ) );
		$units       = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity_available),0) FROM {$wpdb->prefix}ps_stock_balances WHERE tenant_id=%d{$branch_sql}", ...$scope_args ) );
		$low_stock   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM (SELECT d.id FROM {$wpdb->prefix}ps_drugs d LEFT JOIN {$wpdb->prefix}ps_stock_balances s ON s.tenant_id=d.tenant_id AND s.drug_id=d.id{$stock_join} WHERE d.tenant_id=%d AND d.status='active' GROUP BY d.id,d.reorder_level HAVING COALESCE(SUM(s.quantity_available),0)<=d.reorder_level) scoped_low", ...$stock_args ) );
		$expiry      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ps_batches WHERE tenant_id=%d{$branch_sql} AND status='active' AND quantity_available>0 AND expiry_date<=DATE_ADD(UTC_DATE(),INTERVAL 180 DAY)", ...$scope_args ) );
		return array( 'products' => $products, 'units' => $units, 'low_stock' => $low_stock, 'expiry' => $expiry );
	}

	private static function workspace_payload( $view, $tenant_id, $branch_id, $search ) {
		global $wpdb;
		$p           = $wpdb->prefix . 'ps_';
		$branch_sql  = $branch_id ? ' AND %s.branch_id=%d' : '';
		$branch_name = $branch_id ? __( 'Selected branch', 'pharmasure-inventory' ) : __( 'All branches', 'pharmasure-inventory' );
		$payload     = array( 'rows' => array(), 'facts' => array(), 'eyebrow' => '', 'title' => '', 'description' => '', 'inspector_title' => '', 'inspector_copy' => '' );

		switch ( $view ) {
			case 'batches':
				$where = $branch_id ? ' AND b.branch_id=%d' : '';
				$args  = $branch_id ? array( $tenant_id, $branch_id, 150 ) : array( $tenant_id, 150 );
				$payload['rows'] = $wpdb->get_results( $wpdb->prepare( "SELECT b.batch_number,b.expiry_date,b.quantity_received,b.quantity_available,b.unit_cost_minor,b.selling_price_minor,b.status,d.sku,d.name,d.strength,s.name supplier_name FROM {$p}batches b JOIN {$p}drugs d ON d.id=b.drug_id AND d.tenant_id=b.tenant_id LEFT JOIN {$p}suppliers s ON s.id=b.supplier_id AND s.tenant_id=b.tenant_id WHERE b.tenant_id=%d{$where} ORDER BY b.expiry_date ASC,b.id DESC LIMIT %d", ...$args ), ARRAY_A );
				$available = array_sum( array_map( static fn( $row ) => (float) $row['quantity_available'], $payload['rows'] ) );
				$payload = array_merge( $payload, array( 'eyebrow' => __( 'FEFO control', 'pharmasure-inventory' ), 'title' => __( 'Batch register', 'pharmasure-inventory' ), 'description' => __( 'Trace quantities, acquisition cost, sell price and expiry by medicine lot.', 'pharmasure-inventory' ), 'inspector_title' => __( 'Batch posture', 'pharmasure-inventory' ), 'inspector_copy' => __( 'The register is ordered by earliest expiry so the next eligible stock is visible first.', 'pharmasure-inventory' ), 'facts' => array( __( 'Active batch rows', 'pharmasure-inventory' ) => number_format_i18n( count( $payload['rows'] ) ), __( 'Available units', 'pharmasure-inventory' ) => number_format_i18n( $available, 3 ), __( 'Coverage', 'pharmasure-inventory' ) => $branch_name ) ) );
				break;

			case 'receipts':
				$where = $branch_id ? ' AND r.branch_id=%d' : '';
				$args  = $branch_id ? array( $tenant_id, $branch_id, 100 ) : array( $tenant_id, 100 );
				$payload['rows'] = $wpdb->get_results( $wpdb->prepare( "SELECT r.id,r.purchase_reference,r.received_date,r.status,r.created_at,s.name supplier_name,b.name branch_name,COUNT(l.id) line_count,COALESCE(SUM(l.quantity),0) units_received,COALESCE(SUM(ROUND(l.quantity*l.unit_cost_minor)),0) receipt_value_minor FROM {$p}stock_receipts r JOIN {$p}suppliers s ON s.id=r.supplier_id AND s.tenant_id=r.tenant_id JOIN {$p}branches b ON b.id=r.branch_id AND b.tenant_id=r.tenant_id LEFT JOIN {$p}stock_receipt_lines l ON l.receipt_id=r.id WHERE r.tenant_id=%d{$where} GROUP BY r.id,r.purchase_reference,r.received_date,r.status,r.created_at,s.name,b.name ORDER BY r.received_date DESC,r.id DESC LIMIT %d", ...$args ), ARRAY_A );
				$units = array_sum( array_map( static fn( $row ) => (float) $row['units_received'], $payload['rows'] ) );
				$payload = array_merge( $payload, array( 'eyebrow' => __( 'Inbound control', 'pharmasure-inventory' ), 'title' => __( 'Goods receipts', 'pharmasure-inventory' ), 'description' => __( 'Reconcile supplier deliveries with recorded lines, quantities and landed value.', 'pharmasure-inventory' ), 'inspector_title' => __( 'Receiving context', 'pharmasure-inventory' ), 'inspector_copy' => __( 'Completed receipts create traceable batches and immutable stock-ledger entries.', 'pharmasure-inventory' ), 'facts' => array( __( 'Receipts shown', 'pharmasure-inventory' ) => number_format_i18n( count( $payload['rows'] ) ), __( 'Units received', 'pharmasure-inventory' ) => number_format_i18n( $units, 3 ), __( 'Coverage', 'pharmasure-inventory' ) => $branch_name ) ) );
				break;

			case 'movements':
				$where = $branch_id ? ' AND m.branch_id=%d' : '';
				$args  = $branch_id ? array( $tenant_id, $branch_id, 150 ) : array( $tenant_id, 150 );
				$payload['rows'] = $wpdb->get_results( $wpdb->prepare( "SELECT m.created_at,m.movement_type,m.quantity_delta,m.unit_cost_minor,m.reference_type,m.reference_id,m.reason,m.correlation_id,d.sku,d.name FROM {$p}stock_movements m JOIN {$p}drugs d ON d.id=m.drug_id AND d.tenant_id=m.tenant_id WHERE m.tenant_id=%d{$where} ORDER BY m.created_at DESC,m.id DESC LIMIT %d", ...$args ), ARRAY_A );
				$inbound = count( array_filter( $payload['rows'], static fn( $row ) => (float) $row['quantity_delta'] > 0 ) );
				$outbound = count( $payload['rows'] ) - $inbound;
				$payload = array_merge( $payload, array( 'eyebrow' => __( 'Immutable ledger', 'pharmasure-inventory' ), 'title' => __( 'Stock movement history', 'pharmasure-inventory' ), 'description' => __( 'Follow every receipt, sale, dispensing event and adjustment back to its source.', 'pharmasure-inventory' ), 'inspector_title' => __( 'Ledger posture', 'pharmasure-inventory' ), 'inspector_copy' => __( 'Correlation and source references preserve an investigation path without exposing another pharmacy.', 'pharmasure-inventory' ), 'facts' => array( __( 'Entries shown', 'pharmasure-inventory' ) => number_format_i18n( count( $payload['rows'] ) ), __( 'Inbound entries', 'pharmasure-inventory' ) => number_format_i18n( $inbound ), __( 'Outbound entries', 'pharmasure-inventory' ) => number_format_i18n( $outbound ) ) ) );
				break;

			case 'low-stock':
				$join  = $branch_id ? ' AND sb.branch_id=%d' : '';
				$args  = $branch_id ? array( $branch_id, $tenant_id, 150 ) : array( $tenant_id, 150 );
				$payload['rows'] = $wpdb->get_results( $wpdb->prepare( "SELECT d.sku,d.name,d.generic_name,d.reorder_level,d.unit_of_measure,COALESCE(SUM(sb.quantity_available),0) quantity_available,GREATEST(d.reorder_level-COALESCE(SUM(sb.quantity_available),0),0) shortfall FROM {$p}drugs d LEFT JOIN {$p}stock_balances sb ON sb.tenant_id=d.tenant_id AND sb.drug_id=d.id{$join} WHERE d.tenant_id=%d AND d.status='active' GROUP BY d.id,d.sku,d.name,d.generic_name,d.reorder_level,d.unit_of_measure HAVING quantity_available<=d.reorder_level ORDER BY shortfall DESC,d.name LIMIT %d", ...$args ), ARRAY_A );
				$zero = count( array_filter( $payload['rows'], static fn( $row ) => (float) $row['quantity_available'] <= 0 ) );
				$payload = array_merge( $payload, array( 'eyebrow' => __( 'Replenishment queue', 'pharmasure-inventory' ), 'title' => __( 'Low-stock queue', 'pharmasure-inventory' ), 'description' => __( 'Prioritise medicine replenishment by current availability and reorder shortfall.', 'pharmasure-inventory' ), 'inspector_title' => __( 'Queue health', 'pharmasure-inventory' ), 'inspector_copy' => __( 'Items with no available units are prioritised ahead of products approaching threshold.', 'pharmasure-inventory' ), 'facts' => array( __( 'Items requiring action', 'pharmasure-inventory' ) => number_format_i18n( count( $payload['rows'] ) ), __( 'Out of stock', 'pharmasure-inventory' ) => number_format_i18n( $zero ), __( 'Coverage', 'pharmasure-inventory' ) => $branch_name ) ) );
				break;

			case 'expiry':
				$where = $branch_id ? ' AND bt.branch_id=%d' : '';
				$args  = $branch_id ? array( $tenant_id, $branch_id, 150 ) : array( $tenant_id, 150 );
				$payload['rows'] = $wpdb->get_results( $wpdb->prepare( "SELECT bt.batch_number,bt.expiry_date,bt.quantity_available,bt.unit_cost_minor,d.sku,d.name,s.name supplier_name,DATEDIFF(bt.expiry_date,UTC_DATE()) days_remaining FROM {$p}batches bt JOIN {$p}drugs d ON d.id=bt.drug_id AND d.tenant_id=bt.tenant_id LEFT JOIN {$p}suppliers s ON s.id=bt.supplier_id AND s.tenant_id=bt.tenant_id WHERE bt.tenant_id=%d{$where} AND bt.status='active' AND bt.quantity_available>0 ORDER BY bt.expiry_date ASC,bt.id ASC LIMIT %d", ...$args ), ARRAY_A );
				$attention = count( array_filter( $payload['rows'], static fn( $row ) => (int) $row['days_remaining'] <= 180 ) );
				$value = array_sum( array_map( static fn( $row ) => (float) $row['quantity_available'] * (int) $row['unit_cost_minor'], $payload['rows'] ) );
				$payload = array_merge( $payload, array( 'eyebrow' => __( 'Shelf-life assurance', 'pharmasure-inventory' ), 'title' => __( 'Expiry inspection', 'pharmasure-inventory' ), 'description' => __( 'Inspect every live batch in FEFO order with clear urgency bands and value at risk.', 'pharmasure-inventory' ), 'inspector_title' => __( 'Shelf-life posture', 'pharmasure-inventory' ), 'inspector_copy' => __( 'Critical and near-term batches surface first; long-dated batches remain visible for full assurance.', 'pharmasure-inventory' ), 'facts' => array( __( 'Batches inspected', 'pharmasure-inventory' ) => number_format_i18n( count( $payload['rows'] ) ), __( 'Within 180 days', 'pharmasure-inventory' ) => number_format_i18n( $attention ), __( 'Stock value reviewed', 'pharmasure-inventory' ) => self::money( $value, '' ) ) ) );
				break;

			case 'suppliers':
				$receipt_join = $branch_id ? ' AND r.branch_id=%d' : '';
				$args         = $branch_id ? array( $branch_id, $tenant_id, 100 ) : array( $tenant_id, 100 );
				$payload['rows'] = $wpdb->get_results( $wpdb->prepare( "SELECT s.name,s.contact_name,s.phone,s.email,s.payment_terms,s.status,COUNT(r.id) receipt_count,MAX(r.received_date) last_receipt FROM {$p}suppliers s LEFT JOIN {$p}stock_receipts r ON r.supplier_id=s.id AND r.tenant_id=s.tenant_id{$receipt_join} WHERE s.tenant_id=%d GROUP BY s.id,s.name,s.contact_name,s.phone,s.email,s.payment_terms,s.status ORDER BY s.status DESC,s.name LIMIT %d", ...$args ), ARRAY_A );
				$active = count( array_filter( $payload['rows'], static fn( $row ) => 'active' === $row['status'] ) );
				$payload = array_merge( $payload, array( 'eyebrow' => __( 'Supply network', 'pharmasure-inventory' ), 'title' => __( 'Supplier context', 'pharmasure-inventory' ), 'description' => __( 'Keep delivery history, commercial terms and contact context beside inventory operations.', 'pharmasure-inventory' ), 'inspector_title' => __( 'Supplier network', 'pharmasure-inventory' ), 'inspector_copy' => __( 'Receipt activity is calculated only from the current authorized branch scope.', 'pharmasure-inventory' ), 'facts' => array( __( 'Suppliers shown', 'pharmasure-inventory' ) => number_format_i18n( count( $payload['rows'] ) ), __( 'Active suppliers', 'pharmasure-inventory' ) => number_format_i18n( $active ), __( 'Coverage', 'pharmasure-inventory' ) => $branch_name ) ) );
				break;

			case 'catalogue':
			default:
				$join   = $branch_id ? ' AND sb.branch_id=%d' : '';
				$args   = $branch_id ? array( $branch_id, $tenant_id ) : array( $tenant_id );
				$filter = '';
				if ( '' !== $search ) {
					$like    = '%' . $wpdb->esc_like( $search ) . '%';
					$filter  = ' AND (d.sku LIKE %s OR d.barcode LIKE %s OR d.name LIKE %s OR d.generic_name LIKE %s)';
					$args    = array_merge( $args, array( $like, $like, $like, $like ) );
				}
				$args[] = 150;
				$payload['rows'] = $wpdb->get_results( $wpdb->prepare( "SELECT d.sku,d.barcode,d.name,d.generic_name,d.strength,d.dosage_form,d.category,d.unit_of_measure,d.requires_prescription,d.is_controlled,d.selling_price_minor,d.reorder_level,d.status,COALESCE(SUM(sb.quantity_available),0) quantity_available FROM {$p}drugs d LEFT JOIN {$p}stock_balances sb ON sb.tenant_id=d.tenant_id AND sb.drug_id=d.id{$join} WHERE d.tenant_id=%d{$filter} GROUP BY d.id,d.sku,d.barcode,d.name,d.generic_name,d.strength,d.dosage_form,d.category,d.unit_of_measure,d.requires_prescription,d.is_controlled,d.selling_price_minor,d.reorder_level,d.status ORDER BY d.status DESC,d.name LIMIT %d", ...$args ), ARRAY_A );
				$rx = count( array_filter( $payload['rows'], static fn( $row ) => (int) $row['requires_prescription'] === 1 ) );
				$categories = count( array_unique( array_filter( array_column( $payload['rows'], 'category' ) ) ) );
				$payload = array_merge( $payload, array( 'eyebrow' => __( 'Medicine master', 'pharmasure-inventory' ), 'title' => __( 'Catalogue', 'pharmasure-inventory' ), 'description' => __( 'A compact clinical catalogue with branch availability and prescribing controls in context.', 'pharmasure-inventory' ), 'inspector_title' => __( 'Catalogue profile', 'pharmasure-inventory' ), 'inspector_copy' => __( 'Medicine identity remains tenant-owned while availability reflects the authenticated branch.', 'pharmasure-inventory' ), 'facts' => array( __( 'Medicines shown', 'pharmasure-inventory' ) => number_format_i18n( count( $payload['rows'] ) ), __( 'Prescription items', 'pharmasure-inventory' ) => number_format_i18n( $rx ), __( 'Categories', 'pharmasure-inventory' ) => number_format_i18n( $categories ) ) ) );
				break;
		}

		return $payload;
	}

	private static function render_pulse( $label, $value, $view, $decimal = false, $attention = false ) {
		?><a class="ps-pulse<?php echo $attention ? ' ps-pulse--attention' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'pharmasure-inventory', 'view' => $view ), admin_url( 'admin.php' ) ) ); ?>"><span><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( number_format_i18n( $value, $decimal ? 3 : 0 ) ); ?></strong><small><?php esc_html_e( 'Open register', 'pharmasure-inventory' ); ?> <span aria-hidden="true">&rarr;</span></small></a><?php
	}

	private static function render_workspace_table( $view, $rows, $currency ) {
		if ( ! $rows ) {
			echo '<div class="ps-empty-state"><strong>' . esc_html__( 'No records in this scope', 'pharmasure-inventory' ) . '</strong><span>' . esc_html__( 'Try another section, adjust your search, or select a branch with inventory activity.', 'pharmasure-inventory' ) . '</span></div>';
			return;
		}

		$headers = array(
			'catalogue' => array( 'Medicine', 'Clinical profile', 'Availability', 'Reorder', 'Price', 'Control' ),
			'batches'   => array( 'Batch / medicine', 'Supplier', 'Expiry', 'Available', 'Unit cost', 'Sell price' ),
			'receipts'  => array( 'Receipt', 'Supplier / branch', 'Received', 'Lines', 'Units', 'Value' ),
			'movements' => array( 'Time', 'Medicine', 'Movement', 'Quantity', 'Source', 'Correlation' ),
			'low-stock' => array( 'Medicine', 'Available', 'Reorder level', 'Shortfall', 'Unit', 'Priority' ),
			'expiry'    => array( 'Batch / medicine', 'Supplier', 'Expiry date', 'Days remaining', 'Quantity', 'Exposure' ),
			'suppliers' => array( 'Supplier', 'Contact', 'Terms', 'Receipts', 'Last delivery', 'Status' ),
		);
		?>
		<div class="ps-table-scroll" role="region" aria-label="<?php echo esc_attr( $headers[ $view ][0] . ' ' . __( 'register', 'pharmasure-inventory' ) ); ?>" tabindex="0"><table class="ps-data-table"><thead><tr><?php foreach ( $headers[ $view ] as $header ) : ?><th scope="col"><?php echo esc_html( $header ); ?></th><?php endforeach; ?></tr></thead><tbody>
		<?php foreach ( $rows as $row ) : self::render_workspace_row( $view, $row, $currency ); endforeach; ?>
		</tbody></table></div>
		<?php
	}

	private static function render_workspace_row( $view, $row, $currency ) {
		switch ( $view ) {
			case 'batches':
				?><tr><td><strong><?php echo esc_html( $row['batch_number'] ); ?></strong><span><?php echo esc_html( $row['name'] . ( $row['strength'] ? ' ' . $row['strength'] : '' ) ); ?></span><code><?php echo esc_html( $row['sku'] ); ?></code></td><td><?php echo esc_html( $row['supplier_name'] ?: __( 'Not recorded', 'pharmasure-inventory' ) ); ?></td><td><time datetime="<?php echo esc_attr( $row['expiry_date'] ); ?>"><?php echo esc_html( mysql2date( 'M j, Y', $row['expiry_date'], false ) ); ?></time></td><td class="ps-number"><?php echo esc_html( number_format_i18n( $row['quantity_available'], 3 ) ); ?><span><?php echo esc_html( sprintf( __( 'of %s received', 'pharmasure-inventory' ), number_format_i18n( $row['quantity_received'], 3 ) ) ); ?></span></td><td class="ps-number"><?php echo esc_html( self::money( $row['unit_cost_minor'], $currency ) ); ?></td><td class="ps-number"><?php echo esc_html( self::money( $row['selling_price_minor'], $currency ) ); ?></td></tr><?php
				break;
			case 'receipts':
				?><tr><td><strong><?php echo esc_html( $row['purchase_reference'] ?: sprintf( __( 'Receipt #%d', 'pharmasure-inventory' ), $row['id'] ) ); ?></strong><?php self::status( $row['status'] ); ?></td><td><strong><?php echo esc_html( $row['supplier_name'] ); ?></strong><span><?php echo esc_html( $row['branch_name'] ); ?></span></td><td><time datetime="<?php echo esc_attr( $row['received_date'] ); ?>"><?php echo esc_html( mysql2date( 'M j, Y', $row['received_date'], false ) ); ?></time></td><td class="ps-number"><?php echo esc_html( number_format_i18n( $row['line_count'] ) ); ?></td><td class="ps-number"><?php echo esc_html( number_format_i18n( $row['units_received'], 3 ) ); ?></td><td class="ps-number"><?php echo esc_html( self::money( $row['receipt_value_minor'], $currency ) ); ?></td></tr><?php
				break;
			case 'movements':
				$positive = (float) $row['quantity_delta'] > 0;
				?><tr><td><time datetime="<?php echo esc_attr( mysql2date( 'c', $row['created_at'], false ) ); ?>"><?php echo esc_html( mysql2date( 'M j, H:i', $row['created_at'], false ) ); ?></time></td><td><strong><?php echo esc_html( $row['name'] ); ?></strong><code><?php echo esc_html( $row['sku'] ); ?></code></td><td><?php self::status( $row['movement_type'] ); ?></td><td class="ps-number <?php echo $positive ? 'ps-positive' : 'ps-negative'; ?>"><?php echo esc_html( ( $positive ? '+' : '' ) . number_format_i18n( $row['quantity_delta'], 3 ) ); ?></td><td><strong><?php echo esc_html( ucwords( str_replace( '_', ' ', $row['reference_type'] ) ) ); ?></strong><span>#<?php echo esc_html( $row['reference_id'] ); ?></span></td><td><code title="<?php echo esc_attr( $row['correlation_id'] ?: '' ); ?>"><?php echo esc_html( self::compact_id( $row['correlation_id'] ) ); ?></code></td></tr><?php
				break;
			case 'low-stock':
				$empty = (float) $row['quantity_available'] <= 0;
				?><tr><td><strong><?php echo esc_html( $row['name'] ); ?></strong><span><?php echo esc_html( $row['generic_name'] ?: __( 'Generic name not recorded', 'pharmasure-inventory' ) ); ?></span><code><?php echo esc_html( $row['sku'] ); ?></code></td><td class="ps-number"><?php echo esc_html( number_format_i18n( $row['quantity_available'], 3 ) ); ?></td><td class="ps-number"><?php echo esc_html( number_format_i18n( $row['reorder_level'], 3 ) ); ?></td><td class="ps-number ps-negative"><?php echo esc_html( number_format_i18n( $row['shortfall'], 3 ) ); ?></td><td><?php echo esc_html( $row['unit_of_measure'] ); ?></td><td><?php self::status( $empty ? 'out_of_stock' : 'reorder' ); ?></td></tr><?php
				break;
			case 'expiry':
				$days = (int) $row['days_remaining'];
				$band = $days < 0 ? 'expired' : ( $days <= 30 ? 'critical' : ( $days <= 90 ? 'soon' : ( $days <= 180 ? 'watch' : 'long_dated' ) ) );
				?><tr><td><strong><?php echo esc_html( $row['batch_number'] ); ?></strong><span><?php echo esc_html( $row['name'] ); ?></span><code><?php echo esc_html( $row['sku'] ); ?></code></td><td><?php echo esc_html( $row['supplier_name'] ?: __( 'Not recorded', 'pharmasure-inventory' ) ); ?></td><td><time datetime="<?php echo esc_attr( $row['expiry_date'] ); ?>"><?php echo esc_html( mysql2date( 'M j, Y', $row['expiry_date'], false ) ); ?></time></td><td><?php self::status( $band, $days < 0 ? sprintf( __( '%d days overdue', 'pharmasure-inventory' ), abs( $days ) ) : sprintf( _n( '%d day', '%d days', $days, 'pharmasure-inventory' ), $days ) ); ?></td><td class="ps-number"><?php echo esc_html( number_format_i18n( $row['quantity_available'], 3 ) ); ?></td><td class="ps-number"><?php echo esc_html( self::money( (float) $row['quantity_available'] * (int) $row['unit_cost_minor'], $currency ) ); ?></td></tr><?php
				break;
			case 'suppliers':
				?><tr><td><strong><?php echo esc_html( $row['name'] ); ?></strong><span><?php echo esc_html( $row['contact_name'] ?: __( 'No contact assigned', 'pharmasure-inventory' ) ); ?></span></td><td><?php if ( $row['email'] ) : ?><a href="mailto:<?php echo esc_attr( $row['email'] ); ?>"><?php echo esc_html( $row['email'] ); ?></a><?php endif; ?><span><?php echo esc_html( $row['phone'] ?: __( 'Phone not recorded', 'pharmasure-inventory' ) ); ?></span></td><td><?php echo esc_html( $row['payment_terms'] ?: __( 'Not recorded', 'pharmasure-inventory' ) ); ?></td><td class="ps-number"><?php echo esc_html( number_format_i18n( $row['receipt_count'] ) ); ?></td><td><?php echo $row['last_receipt'] ? esc_html( mysql2date( 'M j, Y', $row['last_receipt'], false ) ) : '&mdash;'; ?></td><td><?php self::status( $row['status'] ); ?></td></tr><?php
				break;
			case 'catalogue':
			default:
				?><tr><td><strong><?php echo esc_html( $row['name'] ); ?></strong><span><?php echo esc_html( $row['generic_name'] ?: __( 'Generic name not recorded', 'pharmasure-inventory' ) ); ?></span><code><?php echo esc_html( $row['sku'] ); ?></code></td><td><strong><?php echo esc_html( trim( $row['strength'] . ' ' . $row['dosage_form'] ) ?: __( 'Profile incomplete', 'pharmasure-inventory' ) ); ?></strong><span><?php echo esc_html( $row['category'] ?: __( 'Uncategorised', 'pharmasure-inventory' ) ); ?></span></td><td class="ps-number"><strong><?php echo esc_html( number_format_i18n( $row['quantity_available'], 3 ) ); ?></strong><span><?php echo esc_html( $row['unit_of_measure'] ); ?></span></td><td class="ps-number"><?php echo esc_html( number_format_i18n( $row['reorder_level'], 3 ) ); ?></td><td class="ps-number"><?php echo esc_html( self::money( $row['selling_price_minor'], $currency ) ); ?></td><td><?php self::status( (int) $row['is_controlled'] ? 'controlled' : ( (int) $row['requires_prescription'] ? 'prescription' : 'otc' ) ); ?></td></tr><?php
				break;
		}
	}

	private static function status( $status, $label = '' ) {
		$class = sanitize_html_class( str_replace( '_', '-', $status ) );
		$text  = $label ?: ucwords( str_replace( '_', ' ', $status ) );
		echo '<span class="ps-status ps-status--' . esc_attr( $class ) . '">' . esc_html( $text ) . '</span>';
	}

	private static function money( $minor, $currency ) {
		$amount = number_format_i18n( (float) $minor / 100, 2 );
		return trim( $currency . ' ' . $amount );
	}

	private static function compact_id( $value ) {
		$value = (string) $value;
		return '' === $value ? '—' : ( strlen( $value ) > 15 ? substr( $value, 0, 12 ) . '...' : $value );
	}

	private static function view_index( $view ) {
		$indexes = array( 'catalogue' => '01', 'batches' => '02', 'receipts' => '03', 'movements' => '04', 'low-stock' => '05', 'expiry' => '06', 'suppliers' => '07' );
		return $indexes[ $view ] ?? '00';
	}
}
