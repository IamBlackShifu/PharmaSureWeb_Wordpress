<?php
namespace PharmaSure\Tenancy\Services;

use PharmaSure\Core\DatabaseMigrations;

final class TenantProvisioningService {
	private $db;
	private $base;

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
		$this->base = $wpdb->base_prefix . 'ps_';
	}

	public function create( array $input ) {
		if ( ! is_multisite() || ! current_user_can( 'manage_network_options' ) ) {
			return new \WP_Error( 'platform_admin_required', 'Network super-administrator access is required to provision a pharmacy.', array( 'status' => 403 ) );
		}
		$data = $this->validate( $input );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$correlation = wp_generate_uuid4();
		$created = array( 'tenant_id' => 0, 'site_id' => 0, 'user_id' => 0, 'user_created' => false );
		try {
			if ( $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->base}tenants WHERE slug=%s AND status!='archived'", $data['slug'] ) ) ) {
				return new \WP_Error( 'slug_exists', 'That tenant URL slug is already in use.', array( 'status' => 409 ) );
			}
			$network = get_network();
			$path = '/' . $data['slug'] . '/';
			if ( domain_exists( $network->domain, $path, (int) $network->id ) ) {
				return new \WP_Error( 'site_exists', 'A multisite tenant already uses that URL path.', array( 'status' => 409 ) );
			}
			$existing_user = get_user_by( 'email', $data['owner_email'] );
			if ( $existing_user ) {
				return new \WP_Error( 'owner_identity_exists', 'The owner email is already attached to a platform identity. Use a unique owner email for strict tenant isolation.', array( 'status' => 409 ) );
			}

			$now = current_time( 'mysql', true );
			$inserted = $this->db->insert( $this->base . 'tenants', array( 'name' => $data['legal_name'], 'trading_name' => $data['trading_name'], 'slug' => $data['slug'], 'primary_contact_name' => $data['owner_name'], 'primary_contact_email' => $data['owner_email'], 'primary_contact_phone' => $data['phone'], 'address_line_1' => $data['address'], 'city' => $data['city'], 'country' => $data['country'], 'timezone' => $data['timezone'], 'currency' => $data['currency'], 'status' => 'trial', 'onboarded_at' => $now, 'created_at' => $now, 'created_by' => get_current_user_id() ) );
			if ( ! $inserted ) {
				throw new \RuntimeException( 'The tenant directory record could not be created.' );
			}
			$created['tenant_id'] = (int) $this->db->insert_id;

			$this->db->insert( $this->base . 'branches', array( 'tenant_id' => $created['tenant_id'], 'name' => $data['branch_name'], 'code' => $data['branch_code'], 'address_line_1' => $data['address'], 'city' => $data['city'], 'country' => $data['country'], 'phone' => $data['phone'], 'email' => $data['owner_email'], 'timezone' => $data['timezone'], 'is_active' => 1, 'is_default' => 1, 'created_at' => $now, 'created_by' => get_current_user_id() ) );
			if ( ! $this->db->insert_id ) {
				throw new \RuntimeException( 'The default branch could not be created.' );
			}
			$branch_id = (int) $this->db->insert_id;

			$username = $this->unique_username( $data['owner_email'], $created['tenant_id'] );
			$user_id = wp_create_user( $username, $data['owner_password'], $data['owner_email'] );
			if ( is_wp_error( $user_id ) ) {
				throw new \RuntimeException( $user_id->get_error_message() );
			}
			$created['user_id'] = (int) $user_id;
			$created['user_created'] = true;
			wp_update_user( array( 'ID' => $created['user_id'], 'display_name' => $data['owner_name'], 'first_name' => $data['owner_name'] ) );
			update_user_meta( $created['user_id'], 'default_password_nag', true );

			$this->db->insert( $this->base . 'tenant_memberships', array( 'tenant_id' => $created['tenant_id'], 'user_id' => $created['user_id'], 'role' => 'owner', 'is_admin' => 1, 'is_active' => 1, 'invited_at' => $now, 'activated_at' => $now, 'created_at' => $now ) );
			if ( ! $this->db->insert_id ) {
				throw new \RuntimeException( 'The owner membership could not be created.' );
			}
			$membership_id = (int) $this->db->insert_id;
			$this->ensure_license( $this->base, $created['tenant_id'], $now );

			$site_id = wpmu_create_blog( $network->domain, $path, $data['trading_name'], get_current_user_id(), array( 'public' => 0 ), (int) $network->id );
			if ( is_wp_error( $site_id ) ) {
				throw new \RuntimeException( $site_id->get_error_message() );
			}
			$created['site_id'] = (int) $site_id;
			$this->provision_site( $created, $branch_id, $membership_id, $data, $now );

			$this->db->replace( $this->base . 'tenant_site_mapping', array( 'tenant_id' => $created['tenant_id'], 'site_id' => $created['site_id'], 'created_at' => $now ), array( '%d', '%d', '%s' ) );
			add_user_to_blog( $created['site_id'], $created['user_id'], 'pharmasure_owner' );
			update_user_meta( $created['user_id'], 'primary_blog', $created['site_id'] );
			update_user_meta( $created['user_id'], 'source_domain', $network->domain );
			remove_user_from_blog( $created['user_id'], get_main_site_id() );

			do_action( 'pharmasure_audit_log', array( 'tenant_id' => $created['tenant_id'], 'actor_id' => get_current_user_id(), 'correlation_id' => $correlation, 'action' => 'tenant.provisioned', 'object_type' => 'tenant', 'object_id' => $created['tenant_id'], 'status' => 'success', 'details' => array( 'site_id' => $created['site_id'], 'branch_id' => $branch_id, 'owner_user_id' => $created['user_id'], 'plan' => 'enterprise_trial' ) ) );
			return array( 'id' => $created['tenant_id'], 'site_id' => $created['site_id'], 'site_url' => get_site_url( $created['site_id'] ), 'branch_id' => $branch_id, 'owner_id' => $created['user_id'], 'owner_email' => $data['owner_email'], 'temporary_password' => $data['owner_password'], 'status' => 'trial', 'plan' => 'Enterprise trial', 'correlation_id' => $correlation );
		} catch ( \Throwable $error ) {
			$this->rollback( $created );
			do_action( 'pharmasure_audit_log', array( 'tenant_id' => $created['tenant_id'] ?: null, 'actor_id' => get_current_user_id(), 'correlation_id' => $correlation, 'action' => 'tenant.provision_failed', 'object_type' => 'tenant', 'object_id' => $created['tenant_id'] ?: null, 'status' => 'failure', 'details' => array( 'error' => sanitize_text_field( $error->getMessage() ) ) ) );
			return new \WP_Error( 'tenant_provision_failed', 'Tenant provisioning was rolled back: ' . $error->getMessage(), array( 'status' => 500 ) );
		}
	}

	private function validate( array $input ) {
		$data = array(
			'legal_name' => sanitize_text_field( $input['legal_name'] ?? '' ), 'trading_name' => sanitize_text_field( $input['trading_name'] ?? '' ),
			'slug' => sanitize_title( $input['slug'] ?? '' ), 'owner_name' => sanitize_text_field( $input['owner_name'] ?? '' ), 'owner_email' => sanitize_email( $input['owner_email'] ?? '' ),
			'owner_password' => (string) ( $input['owner_password'] ?? '' ), 'branch_name' => sanitize_text_field( $input['branch_name'] ?? 'Main Branch' ), 'branch_code' => strtoupper( sanitize_key( $input['branch_code'] ?? 'MAIN' ) ),
			'country' => strtoupper( sanitize_text_field( $input['country'] ?? '' ) ), 'currency' => strtoupper( sanitize_text_field( $input['currency'] ?? 'USD' ) ), 'timezone' => sanitize_text_field( $input['timezone'] ?? 'Africa/Harare' ),
			'address' => sanitize_text_field( $input['address'] ?? '' ), 'city' => sanitize_text_field( $input['city'] ?? '' ), 'phone' => sanitize_text_field( $input['phone'] ?? '' ),
		);
		if ( ! $data['owner_password'] ) { $data['owner_password'] = wp_generate_password( 16, true, true ) . 'Aa1'; }
		$required = array( 'legal_name', 'trading_name', 'slug', 'owner_name', 'owner_email', 'branch_name', 'branch_code', 'country', 'currency', 'timezone' );
		$missing = array_values( array_filter( $required, static fn( $field ) => '' === $data[ $field ] ) );
		if ( $missing ) { return new \WP_Error( 'missing_fields', 'Complete all required fields: ' . implode( ', ', $missing ) . '.', array( 'status' => 422 ) ); }
		if ( ! is_email( $data['owner_email'] ) ) { return new \WP_Error( 'invalid_owner_email', 'Enter a valid, unique owner email address.', array( 'status' => 422 ) ); }
		if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $data['slug'] ) || strlen( $data['slug'] ) > 80 ) { return new \WP_Error( 'invalid_slug', 'Use a lowercase URL slug containing letters, numbers and single hyphens.', array( 'status' => 422 ) ); }
		if ( ! preg_match( '/^[A-Z]{2}$/', $data['country'] ) || ! preg_match( '/^[A-Z]{3}$/', $data['currency'] ) ) { return new \WP_Error( 'invalid_locale', 'Country must be ISO-2 and currency must be ISO-3.', array( 'status' => 422 ) ); }
		if ( ! in_array( $data['timezone'], timezone_identifiers_list(), true ) ) { return new \WP_Error( 'invalid_timezone', 'Select a valid IANA timezone.', array( 'status' => 422 ) ); }
		if ( strlen( $data['owner_password'] ) < 12 || ! preg_match( '/[A-Z]/', $data['owner_password'] ) || ! preg_match( '/[a-z]/', $data['owner_password'] ) || ! preg_match( '/\d/', $data['owner_password'] ) ) { return new \WP_Error( 'weak_owner_password', 'The temporary password must contain at least 12 characters with upper-case, lower-case and numeric characters.', array( 'status' => 422 ) ); }
		return $data;
	}

	private function provision_site( array $created, $branch_id, $membership_id, array $data, $now ) {
		switch_to_blog( $created['site_id'] );
		try {
			( new DatabaseMigrations() )->migrate();
			\PharmaSure\Core\Plugin::register_capabilities();
			foreach ( array( '\\PharmaSure\\Inventory\\Installer', '\\PharmaSure\\POS\\Installer', '\\PharmaSure\\Clinical\\Installer', '\\PharmaSure\\Claims\\Installer', '\\PharmaSure\\Reporting\\Installer', '\\PharmaSure\\Offline\\Installer' ) as $installer ) { if ( class_exists( $installer ) && is_callable( array( $installer, 'install' ) ) ) { $installer::install(); } }
			global $wpdb; $prefix = $wpdb->prefix . 'ps_';
			$wpdb->insert( $prefix . 'tenants', array( 'id' => $created['tenant_id'], 'name' => $data['legal_name'], 'trading_name' => $data['trading_name'], 'slug' => $data['slug'], 'primary_contact_name' => $data['owner_name'], 'primary_contact_email' => $data['owner_email'], 'primary_contact_phone' => $data['phone'], 'address_line_1' => $data['address'], 'city' => $data['city'], 'country' => $data['country'], 'timezone' => $data['timezone'], 'currency' => $data['currency'], 'status' => 'trial', 'onboarded_at' => $now, 'created_at' => $now, 'created_by' => get_current_user_id() ) );
			$wpdb->insert( $prefix . 'branches', array( 'id' => $branch_id, 'tenant_id' => $created['tenant_id'], 'name' => $data['branch_name'], 'code' => $data['branch_code'], 'address_line_1' => $data['address'], 'city' => $data['city'], 'country' => $data['country'], 'phone' => $data['phone'], 'email' => $data['owner_email'], 'timezone' => $data['timezone'], 'is_active' => 1, 'is_default' => 1, 'created_at' => $now, 'created_by' => get_current_user_id() ) );
			$wpdb->insert( $prefix . 'tenant_memberships', array( 'id' => $membership_id, 'tenant_id' => $created['tenant_id'], 'user_id' => $created['user_id'], 'role' => 'owner', 'is_admin' => 1, 'is_active' => 1, 'invited_at' => $now, 'activated_at' => $now, 'created_at' => $now ) );
			$wpdb->replace( $prefix . 'tenant_site_mapping', array( 'tenant_id' => $created['tenant_id'], 'site_id' => $created['site_id'], 'created_at' => $now ), array( '%d', '%d', '%s' ) );
			$this->ensure_license( $prefix, $created['tenant_id'], $now );
			update_option( 'blogname', $data['trading_name'] ); update_option( 'blogdescription', 'Secure pharmacy operations' ); update_option( 'blog_public', 0 );
			if ( wp_get_theme( 'pharmasure-portal' )->exists() ) { switch_theme( 'pharmasure-portal' ); }
		} finally { restore_current_blog(); }
	}

	private function ensure_license( $prefix, $tenant_id, $now ) {
		$product = (int) $this->db->get_var( $this->db->prepare( "SELECT id FROM {$prefix}products WHERE sku=%s", 'PHARMASURE-ENTERPRISE' ) );
		if ( ! $product ) { $this->db->insert( $prefix . 'products', array( 'sku' => 'PHARMASURE-ENTERPRISE', 'name' => 'PharmaSure Enterprise', 'description' => 'Enterprise pharmacy operations suite', 'is_active' => 1 ) ); $product = (int) $this->db->insert_id; }
		$plan = (int) $this->db->get_var( $this->db->prepare( "SELECT id FROM {$prefix}plans WHERE product_id=%d AND plan_tier=%s", $product, 'enterprise' ) );
		if ( ! $plan ) { $this->db->insert( $prefix . 'plans', array( 'product_id' => $product, 'name' => 'Enterprise trial', 'plan_tier' => 'enterprise', 'description' => '30-day production onboarding trial', 'is_active' => 1 ) ); $plan = (int) $this->db->insert_id; }
		$this->db->insert( $prefix . 'licences', array( 'tenant_id' => $tenant_id, 'plan_id' => $plan, 'status' => 'trial', 'licence_key' => 'TRIAL-' . strtoupper( wp_generate_password( 20, false, false ) ), 'activated_at' => $now, 'expires_at' => gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) ), 'grace_period_days' => 7 ) );
		$license = (int) $this->db->insert_id; if ( ! $license ) { throw new \RuntimeException( 'The trial licence could not be issued.' ); }
		foreach ( array( 'inventory', 'multi_branch', 'pos', 'clinical', 'claims', 'reporting', 'accounts', 'offline' ) as $key ) { $this->db->insert( $prefix . 'licence_entitlements', array( 'licence_id' => $license, 'entitlement_key' => $key, 'is_active' => 1 ) ); }
		foreach ( array( 'max_branches' => 10, 'inventory_writes_monthly' => 10000, 'pos_writes_monthly' => 20000, 'clinical_writes_monthly' => 10000, 'claims_writes_monthly' => 10000, 'report_schedules' => 25, 'tenant_user_seats' => 25, 'offline_devices' => 25, 'offline_mutations_monthly' => 50000 ) as $key => $limit ) { $this->db->insert( $prefix . 'licence_quotas', array( 'licence_id' => $license, 'quota_key' => $key, 'limit_value' => $limit ) ); }
	}

	private function unique_username( $email, $tenant_id ) { $base = sanitize_user( strtok( $email, '@' ), true ) ?: 'tenant-owner'; $username = $base; $suffix = 0; while ( username_exists( $username ) ) { $username = $base . '-' . $tenant_id . ( $suffix ? '-' . $suffix : '' ); ++$suffix; } return $username; }

	private function rollback( array $created ) {
		if ( $created['site_id'] && get_site( $created['site_id'] ) ) { require_once ABSPATH . 'wp-admin/includes/ms.php'; wpmu_delete_blog( $created['site_id'], true ); }
		if ( $created['tenant_id'] ) { foreach ( array( 'licence_quotas', 'licence_entitlements' ) as $table ) { if ( 'licence_quotas' === $table ) { $this->db->query( $this->db->prepare( "DELETE q FROM {$this->base}{$table} q JOIN {$this->base}licences l ON l.id=q.licence_id WHERE l.tenant_id=%d", $created['tenant_id'] ) ); } else { $this->db->query( $this->db->prepare( "DELETE e FROM {$this->base}{$table} e JOIN {$this->base}licences l ON l.id=e.licence_id WHERE l.tenant_id=%d", $created['tenant_id'] ) ); } } foreach ( array( 'licences', 'tenant_site_mapping', 'tenant_memberships', 'branches', 'tenants' ) as $table ) { $this->db->delete( $this->base . $table, array( 'tenant_id' => $created['tenant_id'] ) ); } }
		if ( $created['user_created'] && $created['user_id'] ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $created['user_id'] ); }
	}
}
