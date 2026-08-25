<?php
/**
 * Seed two persistent demo tenants with branches and inventory.
 *
 * Run with: wp eval-file scripts/seed-demo-tenants.php --url=http://localhost:8080/
 */

use PharmaSure\Inventory\Installer;
use PharmaSure\Inventory\Services\InventoryService;
use PharmaSure\Core\DatabaseMigrations;
use PharmaSure\Tenancy\Services\BranchService;
use PharmaSure\Tenancy\Services\TenantService;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

wp_set_current_user( 1 );

$fixtures = array(
	array(
		'legal_name' => 'GreenLife Pharmacy (Private) Limited',
		'trading_name' => 'GreenLife Pharmacy',
		'slug' => 'greenlife-pharmacy',
		'owner_email' => 'owner@greenlife.demo',
		'owner_password' => getenv( 'PHARMASURE_DEMO_GREENLIFE_PASSWORD' ) ?: 'GreenLifeDemo!2026',
		'address' => '12 Samora Machel Avenue, Harare',
		'phone' => '+263 242 700 101',
		'branches' => array(
			array( 'name' => 'CBD Branch', 'code' => 'MAIN', 'address' => '12 Samora Machel Avenue, Harare' ),
			array( 'name' => 'Borrowdale Branch', 'code' => 'BOR', 'address' => 'Borrowdale Road, Harare' ),
		),
		'supplier' => 'MedSource Zimbabwe',
		'stock' => array(
			array( 'sku' => 'GL-PARA-500', 'barcode' => '600100000101', 'name' => 'Paracetamol 500mg Tablets', 'generic_name' => 'Paracetamol', 'strength' => '500mg', 'dosage_form' => 'tablet', 'pack_size' => '100', 'quantity' => 120, 'cost' => 8, 'price' => 15, 'rx' => false ),
			array( 'sku' => 'GL-AMOX-500', 'barcode' => '600100000102', 'name' => 'Amoxicillin 500mg Capsules', 'generic_name' => 'Amoxicillin', 'strength' => '500mg', 'dosage_form' => 'capsule', 'pack_size' => '21', 'quantity' => 45, 'cost' => 350, 'price' => 550, 'rx' => true, 'expiry_months' => 2 ),
			array( 'sku' => 'GL-ORS-001', 'barcode' => '600100000103', 'name' => 'Oral Rehydration Salts', 'generic_name' => 'ORS', 'strength' => '20.5g', 'dosage_form' => 'sachet', 'pack_size' => '1', 'quantity' => 70, 'cost' => 35, 'price' => 60, 'rx' => false, 'reorder_level' => 25 ),
		),
	),
	array(
		'legal_name' => 'Sunrise Community Pharmacy (Private) Limited',
		'trading_name' => 'Sunrise Community Pharmacy',
		'slug' => 'sunrise-pharmacy',
		'owner_email' => 'owner@sunrise.demo',
		'owner_password' => getenv( 'PHARMASURE_DEMO_SUNRISE_PASSWORD' ) ?: 'SunriseDemo!2026',
		'address' => '88 Robert Mugabe Way, Mutare',
		'phone' => '+263 2020 600 202',
		'branches' => array(
			array( 'name' => 'Main Branch', 'code' => 'MAIN', 'address' => '88 Robert Mugabe Way, Mutare' ),
		),
		'supplier' => 'National Pharmaceutical Supplies',
		'stock' => array(
			array( 'sku' => 'SR-IBU-200', 'barcode' => '600200000201', 'name' => 'Ibuprofen 200mg Tablets', 'generic_name' => 'Ibuprofen', 'strength' => '200mg', 'dosage_form' => 'tablet', 'pack_size' => '50', 'quantity' => 80, 'cost' => 12, 'price' => 25, 'rx' => false ),
			array( 'sku' => 'SR-CET-10', 'barcode' => '600200000202', 'name' => 'Cetirizine 10mg Tablets', 'generic_name' => 'Cetirizine', 'strength' => '10mg', 'dosage_form' => 'tablet', 'pack_size' => '30', 'quantity' => 55, 'cost' => 18, 'price' => 35, 'rx' => false ),
			array( 'sku' => 'SR-METF-500', 'barcode' => '600200000203', 'name' => 'Metformin 500mg Tablets', 'generic_name' => 'Metformin', 'strength' => '500mg', 'dosage_form' => 'tablet', 'pack_size' => '100', 'quantity' => 95, 'cost' => 20, 'price' => 40, 'rx' => true ),
		),
	),
);

global $wpdb;
$main_blog_id = get_main_site_id();
$network = get_network();
if ( ! in_array( $network->domain, array( 'localhost', 'localhost:8080' ), true ) && ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	throw new RuntimeException( 'Demo credentials may only be seeded on localhost or a development environment.' );
}
$tenant_service = new TenantService();

// Repair the root title if an older seed run resolved a missing tenant path to
// the network root. This condition deliberately avoids overwriting custom names.
$root_name = get_bloginfo( 'name' );
if ( in_array( $root_name, array( 'GreenLife Pharmacy', 'Sunrise Community Pharmacy' ), true ) ) {
	update_option( 'blogname', 'PharmaSure' );
}

foreach ( $fixtures as $fixture ) {
	$tenant = $tenant_service->get_tenant_by_slug( $fixture['slug'] );
	if ( ! $tenant ) {
		$result = $tenant_service->create_tenant(
			array_merge(
				$fixture,
				array( 'country' => 'ZW', 'currency' => 'USD', 'timezone' => 'Africa/Harare' )
			)
		);
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() );
		}
		$tenant = $tenant_service->get_tenant( (int) $result['id'] );
	}
	$tenant_id = (int) $tenant['id'];

	$path = '/' . $fixture['slug'] . '/';
	$sites = get_sites(
		array(
			'network_id' => $network->id,
			'domain' => $network->domain,
			'path' => $path,
			'number' => 1,
		)
	);
	$site = $sites ? reset( $sites ) : null;
	if ( ! $site ) {
		$site_id = wpmu_create_blog( $network->domain, $path, $fixture['trading_name'], 1, array( 'public' => 1 ), $network->id );
		if ( is_wp_error( $site_id ) ) {
			throw new RuntimeException( $site_id->get_error_message() );
		}
		$site = get_site( $site_id );
	}

	switch_to_blog( (int) $site->blog_id );
	( new DatabaseMigrations() )->migrate();
	Installer::install();
	$prefix = $wpdb->prefix . 'ps_';
	$now = current_time( 'mysql', true );

	// Tenant context resolves mappings from the current site's table prefix.
	$wpdb->replace( $prefix . 'tenant_site_mapping', array( 'tenant_id' => $tenant_id, 'site_id' => (int) $site->blog_id, 'created_at' => $now ), array( '%d', '%d', '%s' ) );
	if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$prefix}tenants WHERE id=%d", $tenant_id ) ) ) {
		$wpdb->insert( $prefix . 'tenants', array( 'id' => $tenant_id, 'name' => $fixture['legal_name'], 'trading_name' => $fixture['trading_name'], 'slug' => $fixture['slug'], 'primary_contact_email' => $fixture['owner_email'], 'country' => 'ZW', 'currency' => 'USD', 'timezone' => 'Africa/Harare', 'status' => 'active', 'onboarded_at' => $now, 'created_at' => $now, 'created_by' => 1 ) );
	}

	// Demo tenants receive an explicit enterprise licence. Feature entry points
	// stay fail-closed; local fixtures do not rely on a development bypass.
	$product_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$prefix}products WHERE sku=%s", 'PHARMASURE-ENTERPRISE' ) );
	if ( ! $product_id ) {
		$wpdb->insert( $prefix . 'products', array( 'sku' => 'PHARMASURE-ENTERPRISE', 'name' => 'PharmaSure Enterprise', 'description' => 'Enterprise pharmacy operations suite', 'is_active' => 1 ) );
		$product_id = (int) $wpdb->insert_id;
	}
	$plan_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$prefix}plans WHERE product_id=%d AND plan_tier=%s", $product_id, 'enterprise' ) );
	if ( ! $plan_id ) {
		$wpdb->insert( $prefix . 'plans', array( 'product_id' => $product_id, 'name' => 'Enterprise Demo', 'plan_tier' => 'enterprise', 'description' => 'Local enterprise verification plan', 'is_active' => 1 ) );
		$plan_id = (int) $wpdb->insert_id;
	}
	$licence_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$prefix}licences WHERE tenant_id=%d AND status IN ('active','trial','grace') ORDER BY id DESC LIMIT 1", $tenant_id ) );
	if ( ! $licence_id ) {
		$wpdb->insert( $prefix . 'licences', array( 'tenant_id' => $tenant_id, 'plan_id' => $plan_id, 'status' => 'active', 'licence_key' => 'DEMO-' . strtoupper( $fixture['slug'] ) . '-' . wp_generate_password( 12, false, false ), 'activated_at' => $now, 'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( '+5 years' ) ), 'grace_period_days' => 7 ) );
		$licence_id = (int) $wpdb->insert_id;
	}
	foreach ( array( 'inventory' ) as $entitlement_key ) {
		$entitlement_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$prefix}licence_entitlements WHERE licence_id=%d AND entitlement_key=%s", $licence_id, $entitlement_key ) );
		if ( ! $entitlement_id ) {
			$wpdb->insert( $prefix . 'licence_entitlements', array( 'licence_id' => $licence_id, 'entitlement_key' => $entitlement_key, 'is_active' => 1 ) );
		} else {
			$wpdb->update( $prefix . 'licence_entitlements', array( 'is_active' => 1 ), array( 'id' => $entitlement_id, 'licence_id' => $licence_id ) );
		}
	}
	wp_cache_delete( 'pharmasure_license_' . $tenant_id );

	$owner = get_user_by( 'email', $fixture['owner_email'] );
	if ( ! $owner ) {
		$username = sanitize_user( str_replace( '-pharmacy', '-owner', $fixture['slug'] ), true );
		$user_id = wp_create_user( $username, $fixture['owner_password'], $fixture['owner_email'] );
		if ( is_wp_error( $user_id ) ) { throw new RuntimeException( $user_id->get_error_message() ); }
		$owner = get_user_by( 'id', $user_id );
	} else {
		wp_set_password( $fixture['owner_password'], $owner->ID );
	}
	// A tenant owner must have exactly one non-platform site assignment.
	foreach ( get_blogs_of_user( $owner->ID, true ) as $assigned_site ) {
		if ( (int) $assigned_site->userblog_id !== (int) $site->blog_id ) {
			remove_user_from_blog( (int) $owner->ID, (int) $assigned_site->userblog_id );
		}
	}
	add_user_to_blog( (int) $site->blog_id, (int) $owner->ID, 'administrator' );
	update_user_meta( $owner->ID, 'primary_blog', (int) $site->blog_id );
	update_user_meta( $owner->ID, 'source_domain', $site->domain );
	$membership = array( 'user_id' => (int) $owner->ID, 'tenant_id' => $tenant_id, 'role' => 'owner', 'is_admin' => 1, 'is_active' => 1, 'activated_at' => $now, 'created_at' => $now );
	$wpdb->replace( $prefix . 'tenant_memberships', $membership, array( '%d', '%d', '%s', '%d', '%d', '%s', '%s' ) );
	$wpdb->replace( $wpdb->base_prefix . 'ps_tenant_memberships', $membership, array( '%d', '%d', '%s', '%d', '%d', '%s', '%s' ) );

	$branch_ids = array();
	foreach ( $fixture['branches'] as $index => $branch ) {
		$branch_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$prefix}branches WHERE tenant_id=%d AND code=%s", $tenant_id, $branch['code'] ) );
		if ( ! $branch_id ) {
			$wpdb->insert( $prefix . 'branches', array( 'tenant_id' => $tenant_id, 'name' => $branch['name'], 'code' => $branch['code'], 'address_line_1' => $branch['address'], 'phone' => $fixture['phone'], 'email' => $fixture['owner_email'], 'timezone' => 'Africa/Harare', 'is_active' => 1, 'is_default' => 0 === $index ? 1 : 0, 'created_at' => $now, 'created_by' => 1 ) );
			$branch_id = (int) $wpdb->insert_id;
		}
		$branch_ids[] = $branch_id;
	}

	$inventory = new InventoryService();
	$suppliers = $inventory->list_suppliers( $tenant_id );
	$supplier_id = 0;
	foreach ( $suppliers as $supplier ) {
		if ( $fixture['supplier'] === $supplier['name'] ) { $supplier_id = (int) $supplier['id']; break; }
	}
	if ( ! $supplier_id ) {
		$supplier = $inventory->create_supplier( $tenant_id, array( 'name' => $fixture['supplier'], 'payment_terms' => '30 days' ) );
		$supplier_id = (int) $supplier['id'];
	}

	$items = array();
	foreach ( $fixture['stock'] as $item ) {
		$reorder_level = (float) ( $item['reorder_level'] ?? 10 );
		$expiry_months = max( 1, (int) ( $item['expiry_months'] ?? 18 ) );
		$expiry_date = gmdate( 'Y-m-d', strtotime( '+' . $expiry_months . ' months' ) );
		$drug_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$prefix}drugs WHERE tenant_id=%d AND sku=%s", $tenant_id, $item['sku'] ) );
		if ( ! $drug_id ) {
			$drug = $inventory->create_drug( $tenant_id, array( 'sku' => $item['sku'], 'barcode' => $item['barcode'], 'name' => $item['name'], 'generic_name' => $item['generic_name'], 'strength' => $item['strength'], 'dosage_form' => $item['dosage_form'], 'pack_size' => $item['pack_size'], 'unit_of_measure' => 'unit', 'category' => 'General medicines', 'requires_prescription' => $item['rx'], 'cost_price_minor' => $item['cost'], 'selling_price_minor' => $item['price'], 'reorder_level' => $reorder_level ) );
			$drug_id = (int) $drug['id'];
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$prefix}drugs SET reorder_level=%f,updated_at=%s WHERE id=%d AND tenant_id=%d", $reorder_level, $now, $drug_id, $tenant_id ) );
		$items[] = array( 'drug_id' => $drug_id, 'batch_number' => 'DEMO-' . substr( $item['sku'], -6 ), 'quantity' => $item['quantity'], 'unit_cost_minor' => $item['cost'], 'selling_price_minor' => $item['price'], 'manufacture_date' => gmdate( 'Y-m-d', strtotime( '-3 months' ) ), 'expiry_date' => $expiry_date );
	}

	foreach ( $branch_ids as $branch_index => $branch_id ) {
		$reference = 'DEMO-OPENING-' . strtoupper( substr( $fixture['slug'], 0, 8 ) ) . ( $branch_index ? '-B' . ( $branch_index + 1 ) : '' );
		$receipt_exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$prefix}stock_receipts WHERE tenant_id=%d AND purchase_reference=%s", $tenant_id, $reference ) );
		if ( ! $receipt_exists ) {
			$branch_items = $items;
			if ( $branch_index ) {
				foreach ( $branch_items as &$branch_item ) {
					$branch_item['quantity'] = max( 10, floor( $branch_item['quantity'] * 0.35 ) );
					$branch_item['batch_number'] .= '-B' . ( $branch_index + 1 );
				}
				unset( $branch_item );
			}
			$receipt = $inventory->receive_stock( $tenant_id, $branch_id, array( 'supplier_id' => $supplier_id, 'received_date' => gmdate( 'Y-m-d' ), 'purchase_reference' => $reference, 'notes' => 'Opening demo stock', 'items' => $branch_items ), 'demo-seed-' . $fixture['slug'] . '-' . $branch_id );
			if ( is_wp_error( $receipt ) ) { throw new RuntimeException( $receipt->get_error_message() ); }
		}
		// Keep local demo shelf-life scenarios deterministic on repeat seed runs.
		foreach ( $items as $seed_item ) {
			$batch_number = $seed_item['batch_number'] . ( $branch_index ? '-B' . ( $branch_index + 1 ) : '' );
			$wpdb->query( $wpdb->prepare( "UPDATE {$prefix}batches SET expiry_date=%s,updated_at=%s WHERE tenant_id=%d AND branch_id=%d AND drug_id=%d AND batch_number=%s", $seed_item['expiry_date'], $now, $tenant_id, $branch_id, $seed_item['drug_id'], $batch_number ) );
		}
	}

	update_option( 'blogname', $fixture['trading_name'] );
	switch_theme( 'pharmasure-portal' );
	restore_current_blog();

	$branch_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}branches WHERE tenant_id=%d AND is_active=1", $tenant_id ) );
	$product_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}drugs WHERE tenant_id=%d AND status='active'", $tenant_id ) );
	$units = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity_available),0) FROM {$prefix}stock_balances WHERE tenant_id=%d", $tenant_id ) );
	echo sprintf( "SEEDED: %s | tenant=%d | site=%s | owner=%s | branches=%d | products=%d | units=%.3f\n", $fixture['trading_name'], $tenant_id, get_site_url( (int) $site->blog_id ), $fixture['owner_email'], $branch_count, $product_count, $units );
}

switch_to_blog( $main_blog_id );
restore_current_blog();
echo "Demo tenant seed complete.\n";
