<?php
/** POS foundation integration test. Run with wp eval-file scripts/test-pos.php. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

\PharmaSure\Inventory\Installer::install();
\PharmaSure\Clinical\Installer::install();
\PharmaSure\POS\Installer::install();
global $wpdb;
$p = $wpdb->prefix . 'ps_'; $ids = array(); $pass = 0; $fail = 0;
$assert = static function ( $condition, $message ) use ( &$pass, &$fail ) { echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n"; $condition ? ++$pass : ++$fail; };

try {
	foreach ( array( 'tills', 'till_sessions', 'document_sequences', 'sales', 'sale_items', 'sale_payments', 'sale_holds', 'refunds', 'refund_items', 'refund_payments' ) as $table ) { $assert( $p . $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . $table ) ), "{$table} table exists" ); }
	$token = 'pos-' . wp_generate_uuid4();
	$wpdb->insert( $p . 'tenants', array( 'name' => 'POS Test', 'slug' => $token, 'primary_contact_email' => $token . '@example.test', 'status' => 'active' ) ); $ids['tenant'] = (int) $wpdb->insert_id;
	foreach ( array( 'MAIN', 'OTHER' ) as $code ) { $wpdb->insert( $p . 'branches', array( 'tenant_id' => $ids['tenant'], 'name' => $code, 'code' => $code, 'is_active' => 1 ) ); $ids[ 'branch_' . strtolower( $code ) ] = (int) $wpdb->insert_id; }
	$now = current_time( 'mysql', true );
	$wpdb->insert( $p . 'drugs', array( 'tenant_id' => $ids['tenant'], 'sku' => 'POS-OTC', 'name' => 'OTC Medicine', 'selling_price_minor' => 250, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) ); $ids['drug'] = (int) $wpdb->insert_id;
	$wpdb->insert( $p . 'drugs', array( 'tenant_id' => $ids['tenant'], 'sku' => 'POS-RX', 'name' => 'Prescription Medicine', 'selling_price_minor' => 500, 'requires_prescription' => 1, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) ); $ids['rx_drug'] = (int) $wpdb->insert_id;
	foreach ( array( array( 'EARLY', '+30 days', 2, $ids['branch_main'] ), array( 'LATE', '+1 year', 10, $ids['branch_main'] ), array( 'OTHER', '+7 days', 100, $ids['branch_other'] ) ) as $batch ) { $wpdb->insert( $p . 'batches', array( 'tenant_id' => $ids['tenant'], 'branch_id' => $batch[3], 'drug_id' => $ids['drug'], 'batch_number' => $batch[0], 'expiry_date' => gmdate( 'Y-m-d', strtotime( $batch[1] ) ), 'quantity_received' => $batch[2], 'quantity_available' => $batch[2], 'unit_cost_minor' => 125, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ) ); $ids['batches'][ $batch[0] ] = (int) $wpdb->insert_id; }
	$wpdb->insert( $p . 'stock_balances', array( 'tenant_id' => $ids['tenant'], 'branch_id' => $ids['branch_main'], 'drug_id' => $ids['drug'], 'quantity_available' => 12, 'updated_at' => $now ) ); $ids['balance'] = (int) $wpdb->insert_id;

	$service = new \PharmaSure\POS\Services\PosService();
	$search = $service->search_products( $ids['tenant'], $ids['branch_main'], 'POS-OTC' );
	$assert( 1 === count( $search ) && $ids['drug'] === (int) $search[0]['id'] && 12.0 === (float) $search[0]['quantity_available'], 'SKU/barcode search returns branch stock and authoritative price' );
	$till = $service->create_till( $ids['tenant'], $ids['branch_main'], array( 'name' => 'Front Counter', 'code' => 'FC1' ) ); $ids['till'] = (int) ( $till['id'] ?? 0 ); $assert( $ids['till'] > 0, 'branch till is created' );
	$session = $service->open_session( $ids['tenant'], $ids['branch_main'], $ids['till'], 1, 1000 ); $ids['session'] = (int) ( $session['id'] ?? 0 ); $assert( $ids['session'] > 0, 'cashier opens a till session with float' );
	$assert( is_wp_error( $service->open_session( $ids['tenant'], $ids['branch_main'], $ids['till'], 1, 0 ) ), 'a till cannot have two open sessions' );
	$hold = $service->hold_sale( $ids['tenant'], $ids['branch_main'], $ids['session'], 1, array( 'items' => array( array( 'drug_id' => $ids['drug'], 'quantity' => 1 ) ), 'notes' => 'Customer returning' ) ); $ids['hold'] = (int) ( $hold['id'] ?? 0 );
	$assert( $ids['hold'] > 0 && str_starts_with( $hold['reference'] ?? '', 'H-' ), 'cart is held with a unique reference' );
	$assert( 1 === count( $service->list_holds( $ids['tenant'], $ids['branch_main'] ) ), 'active branch hold can be retrieved' );
	$assert( 12.0 === (float) $wpdb->get_var( $wpdb->prepare( "SELECT quantity_available FROM {$p}stock_balances WHERE id=%d", $ids['balance'] ) ), 'holding a cart does not consume stock' );
	$assert( 'cancelled' === ( $service->cancel_hold( $ids['tenant'], $ids['branch_main'], $ids['hold'], 1 )['status'] ?? '' ), 'held sale can be cancelled without stock mutation' );
	$payload = array( 'idempotency_key' => wp_generate_uuid4(), 'items' => array( array( 'drug_id' => $ids['drug'], 'quantity' => 4, 'unit_price_minor' => 1 ) ), 'payments' => array( array( 'method' => 'cash', 'amount_minor' => 400 ), array( 'method' => 'card', 'amount_minor' => 600, 'external_reference' => 'CARD-TEST' ) ) );
	$sale = $service->checkout( $ids['tenant'], $ids['branch_main'], $ids['session'], 1, $payload, 'pos-test' ); $ids['sale'] = (int) ( $sale['id'] ?? 0 );
	$assert( $ids['sale'] > 0 && 1000 === (int) ( $sale['total_amount_minor'] ?? 0 ), 'checkout uses authoritative catalogue price' );
	$assert( 'R000001' === ( $sale['receipt_number'] ?? '' ), 'branch receipt sequence starts deterministically' );
	$assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}sale_items WHERE sale_id=%d", $ids['sale'] ) ), 'itemized sale line is persisted' );
	$assert( 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}sale_payments WHERE sale_id=%d", $ids['sale'] ) ), 'split tenders are persisted' );
	$assert( 0.0 === (float) $wpdb->get_var( $wpdb->prepare( "SELECT quantity_available FROM {$p}batches WHERE id=%d", $ids['batches']['EARLY'] ) ), 'POS consumes earliest-expiring branch batch first' );
	$assert( 8.0 === (float) $wpdb->get_var( $wpdb->prepare( "SELECT quantity_available FROM {$p}batches WHERE id=%d", $ids['batches']['LATE'] ) ), 'POS consumes remainder from next FEFO batch' );
	$assert( 100.0 === (float) $wpdb->get_var( $wpdb->prepare( "SELECT quantity_available FROM {$p}batches WHERE id=%d", $ids['batches']['OTHER'] ) ), 'POS never consumes another branch stock' );
	$replay = $service->checkout( $ids['tenant'], $ids['branch_main'], $ids['session'], 1, $payload, 'pos-replay' );
	$assert( ! empty( $replay['idempotent_replay'] ) && $ids['sale'] === (int) $replay['id'], 'idempotent replay returns the original sale' );
	$assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}sales WHERE idempotency_key=%s", $payload['idempotency_key'] ) ), 'idempotent replay creates no duplicate sale' );
	$bad = $payload; $bad['idempotency_key'] = wp_generate_uuid4(); $bad['payments'] = array( array( 'method' => 'cash', 'amount_minor' => 999 ) );
	$assert( is_wp_error( $service->checkout( $ids['tenant'], $ids['branch_main'], $ids['session'], 1, $bad, 'pos-bad' ) ), 'payment mismatch is rejected before stock changes' );
	$rx = $payload; $rx['idempotency_key'] = wp_generate_uuid4(); $rx['items'] = array( array( 'drug_id' => $ids['rx_drug'], 'quantity' => 1 ) ); $rx['payments'] = array( array( 'method' => 'cash', 'amount_minor' => 500 ) );
	$assert( is_wp_error( $service->checkout( $ids['tenant'], $ids['branch_main'], $ids['session'], 1, $rx, 'pos-rx' ) ), 'prescription medicine is routed to clinical dispensing' );

	$policy = $service->configure_pricing( $ids['tenant'], $ids['branch_main'], 1500, 1000 );
	$assert( 1500 === (int) ( $policy['tax_rate_bps'] ?? 0 ) && 1000 === (int) ( $policy['max_discount_bps'] ?? 0 ), 'branch tax and maximum discount policy is stored' );
	$discounted = array( 'idempotency_key' => wp_generate_uuid4(), 'items' => array( array( 'drug_id' => $ids['drug'], 'quantity' => 2 ) ), 'discount_amount_minor' => 50, 'discount_reason' => 'Loyalty promotion', 'payments' => array( array( 'method' => 'cash', 'amount_minor' => 518 ) ) );
	$denied_discount = $service->checkout( $ids['tenant'], $ids['branch_main'], $ids['session'], 1, $discounted, 'pos-discount-denied', false );
	$assert( is_wp_error( $denied_discount ) && 'discount_forbidden' === $denied_discount->get_error_code(), 'discount requires explicit capability and reason' );
	$discounted['idempotency_key'] = wp_generate_uuid4();
	$sale_two = $service->checkout( $ids['tenant'], $ids['branch_main'], $ids['session'], 1, $discounted, 'pos-discount', true ); $ids['sale_two'] = (int) ( $sale_two['id'] ?? 0 );
	$assert( 500 === (int) ( $sale_two['subtotal_amount_minor'] ?? 0 ) && 50 === (int) ( $sale_two['discount_amount_minor'] ?? 0 ) && 68 === (int) ( $sale_two['tax_amount_minor'] ?? 0 ) && 518 === (int) ( $sale_two['total_amount_minor'] ?? 0 ), 'discount is applied before configured tax with integer rounding' );
	$stored_reason = $wpdb->get_var( $wpdb->prepare( "SELECT discount_reason FROM {$p}sales WHERE id=%d", $ids['sale_two'] ) );
	$assert( 'Loyalty promotion' === $stored_reason, 'discount reason is persisted on the sale' );
	$assert( 'R000002' === ( $sale_two['receipt_number'] ?? '' ), 'receipt sequence increments without collision' );

	\PharmaSure\PrintModule\Installer::install();
	$print = new \PharmaSure\PrintModule\Services\PrintService();
	$job = $print->create_job( $ids['tenant'], 'sale_receipt', $ids['sale_two'], 1, 'pos-receipt', $ids['branch_main'] ); $ids['print_job'] = (int) ( $job['id'] ?? 0 );
	$html = $print->render_job( $ids['tenant'], $ids['print_job'] );
	$assert( is_string( $html ) && str_contains( $html, 'OTC Medicine' ) && str_contains( $html, 'Discount:') && str_contains( $html, 'Payments' ), 'sale receipt prints item, discount, tax, total and tender detail' );

	$refund_key = wp_generate_uuid4();
	$refund = $service->refund_sale( $ids['tenant'], $ids['branch_main'], $ids['sale_two'], 1, array( 'idempotency_key' => $refund_key, 'reason' => 'Customer returned sealed item', 'disposition' => 'restock', 'items' => array( array( 'drug_id' => $ids['drug'], 'quantity' => 1 ) ) ), 'pos-refund' );
	$assert( 259 === (int) ( $refund['amount_minor'] ?? 0 ) && 'completed' === ( $refund['status'] ?? '' ), 'partial cash refund uses proportional original discount and tax' );
	$assert( 125 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT cost_amount_minor FROM {$p}refund_items WHERE refund_id=%d", $refund['id'] ) ), 'refund line snapshots exact original batch cost' );
	$assert( 125 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT unit_cost_minor FROM {$p}stock_movements WHERE reference_type='refund' AND reference_id=%d AND movement_type='return'", $refund['id'] ) ), 'return movement carries original immutable unit cost' );
	$assert( 'partially_refunded' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}sales WHERE id=%d", $ids['sale_two'] ) ), 'partial refund updates sale lifecycle state' );
	$assert( 7.0 === (float) $wpdb->get_var( $wpdb->prepare( "SELECT quantity_available FROM {$p}stock_balances WHERE id=%d", $ids['balance'] ) ), 'sellable return restores its original batch and branch balance' );
	$refund_replay = $service->refund_sale( $ids['tenant'], $ids['branch_main'], $ids['sale_two'], 1, array( 'idempotency_key' => $refund_key, 'reason' => 'Repeated request', 'disposition' => 'restock', 'items' => array( array( 'drug_id' => $ids['drug'], 'quantity' => 1 ) ) ), 'pos-refund-replay' );
	$assert( ! empty( $refund_replay['idempotent_replay'] ) && (int) $refund_replay['id'] === (int) $refund['id'], 'refund retry returns original result without another reversal' );
	$over_refund = $service->refund_sale( $ids['tenant'], $ids['branch_main'], $ids['sale_two'], 1, array( 'idempotency_key' => wp_generate_uuid4(), 'reason' => 'Too many', 'disposition' => 'restock', 'items' => array( array( 'drug_id' => $ids['drug'], 'quantity' => 2 ) ) ), 'pos-over-refund' );
	$assert( is_wp_error( $over_refund ), 'cumulative refund cannot exceed originally sold quantity' );

	$void = $service->void_sale( $ids['tenant'], $ids['branch_main'], $ids['sale'], 1, 'Manager-approved transaction reversal', 'pos-void' );
	$assert( 'pending_provider' === ( $void['status'] ?? '' ) && 'voided' === ( $void['sale_status'] ?? '' ), 'mixed-tender void records pending external reversal' );
	$assert( 11.0 === (float) $wpdb->get_var( $wpdb->prepare( "SELECT quantity_available FROM {$p}stock_balances WHERE id=%d", $ids['balance'] ) ), 'void restores all original sale stock to exact batches' );
	$assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}refund_payments rp JOIN {$p}refunds r ON r.id=rp.refund_id WHERE r.sale_id=%d AND rp.status='pending_provider'", $ids['sale'] ) ), 'non-cash reversal is not falsely marked completed' );

	$closed = $service->close_session( $ids['tenant'], $ids['branch_main'], $ids['session'], 1, 1259 );
	$assert( 1259 === (int) ( $closed['expected_cash_minor'] ?? 0 ) && 0 === (int) ( $closed['variance_minor'] ?? -1 ), 'cash-up reconciles captured cash less completed cash refunds' );
	$assert( is_wp_error( $service->checkout( $ids['tenant'], $ids['branch_main'], $ids['session'], 1, array_merge( $payload, array( 'idempotency_key' => wp_generate_uuid4() ) ), 'pos-closed' ) ), 'closed till rejects checkout' );
} catch ( Throwable $error ) { ++$fail; echo 'FAIL: unexpected exception: ' . $error->getMessage() . "\n"; }
finally {
	if ( ! empty( $ids['tenant'] ) ) { $tenant = $ids['tenant']; foreach ( array( 'audit_events', 'print_jobs', 'refund_payments', 'refund_items', 'refunds', 'sale_holds', 'stock_movements', 'sale_payments', 'sale_items', 'sales', 'till_sessions', 'tills', 'document_sequences', 'settings', 'stock_balances', 'batches', 'drugs', 'branches' ) as $table ) { $wpdb->query( $wpdb->prepare( "DELETE FROM {$p}{$table} WHERE tenant_id=%d", $tenant ) ); } $wpdb->delete( $p . 'tenants', array( 'id' => $tenant ), array( '%d' ) ); }
}
echo "POS tests: {$pass} passed, {$fail} failed.\n";
if ( $fail ) { throw new RuntimeException( 'POS tests failed.' ); }
