<?php
namespace PharmaSure\Tenancy\Admin;

use PharmaSure\Tenancy\Services\TenantProvisioningService;

final class TenantAdmin {
	public static function register_pages() {}

	public static function register_network_pages() {
		add_menu_page( 'PharmaSure Platform', 'PharmaSure', 'manage_network_options', 'pharmasure-admin', array( self::class, 'render_network_admin' ), 'dashicons-shield-alt', 2 );
		add_submenu_page( 'pharmasure-admin', 'Platform overview', 'Overview', 'manage_network_options', 'pharmasure-admin', array( self::class, 'render_network_admin' ) );
		add_submenu_page( 'pharmasure-admin', 'Pharmacy tenants', 'Pharmacy tenants', 'manage_network_options', 'pharmasure-tenants', array( self::class, 'render_tenant_list' ) );
		add_submenu_page( 'pharmasure-admin', 'Provision pharmacy', 'Provision pharmacy', 'manage_network_options', 'pharmasure-add-tenant', array( self::class, 'render_add_tenant' ) );
	}

	public static function enqueue_assets( $hook_suffix ) {
		if ( ! str_contains( (string) $hook_suffix, 'pharmasure' ) ) { return; }
		wp_enqueue_style( 'pharmasure-platform-admin', plugin_dir_url( dirname( __DIR__, 2 ) . '/pharmasure-tenancy.php' ) . 'assets/css/platform-admin.css', array(), filemtime( dirname( __DIR__, 2 ) . '/assets/css/platform-admin.css' ) );
		wp_enqueue_script( 'pharmasure-platform-admin', plugin_dir_url( dirname( __DIR__, 2 ) . '/pharmasure-tenancy.php' ) . 'assets/js/platform-admin.js', array(), \PharmaSure\Tenancy\VERSION, true );
	}

	public static function render_network_admin() {
		self::guard();
		$counts = array( 'tenants' => 0, 'branches' => 0, 'members' => 0, 'licences' => 0 );
		foreach ( self::platform_tenants() as $tenant ) {
			if ( 'archived' === $tenant['status'] ) { continue; }
			++$counts['tenants']; $counts['branches'] += (int) $tenant['branch_count']; $counts['members'] += (int) $tenant['member_count'];
			if ( in_array( $tenant['licence_status'], array( 'active', 'trial', 'grace' ), true ) ) { ++$counts['licences']; }
		}
		?>
		<div class="wrap ps-platform-admin">
			<?php self::masthead( 'Platform command centre', 'Tenant posture, licensing and onboarding readiness across the PharmaSure network.', 'Network control / super admin' ); ?>
			<section class="ps-platform-metrics" aria-label="Platform metrics">
				<?php foreach ( array( 'tenants' => array( 'Pharmacy tenants', 'Isolated organisations' ), 'branches' => array( 'Active branches', 'Operational locations' ), 'members' => array( 'Active identities', 'Tenant-bound accounts' ), 'licences' => array( 'Valid licences', 'Active / trial / grace' ) ) as $key => $copy ) : ?>
					<article><span><?php echo esc_html( $copy[0] ); ?></span><strong><?php echo esc_html( number_format_i18n( $counts[ $key ] ) ); ?></strong><small><?php echo esc_html( $copy[1] ); ?></small></article>
				<?php endforeach; ?>
			</section>
			<section class="ps-platform-grid">
				<article class="ps-platform-panel ps-platform-callout"><span class="ps-platform-kicker">Provisioning path</span><h2>Create a production-shaped pharmacy</h2><p>One guarded workflow creates the isolated site, schemas, default branch, owner identity, application roles, enterprise trial, quotas and tenant mapping.</p><a class="button button-primary" href="<?php echo esc_url( network_admin_url( 'admin.php?page=pharmasure-add-tenant' ) ); ?>">Provision pharmacy</a></article>
				<article class="ps-platform-panel"><span class="ps-platform-kicker">Readiness sequence</span><ol class="ps-platform-steps"><li><b>Identity</b><span>Legal entity and unique tenant URL</span></li><li><b>Operations</b><span>Default branch and isolated custom tables</span></li><li><b>Access</b><span>Exclusive owner account and trial licence</span></li><li><b>Handoff</b><span>One-time credentials and onboarding guide</span></li></ol></article>
			</section>
		</div>
		<?php
	}

	public static function render_tenant_list() {
		self::guard();
		$status = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ); $allowed = array( 'active', 'trial', 'suspended' );
		$tenants = array_filter( self::platform_tenants(), static fn( $tenant ) => in_array( $status, $allowed, true ) ? $tenant['status'] === $status : $tenant['status'] !== 'archived' );
		?>
		<div class="wrap ps-platform-admin">
			<?php self::masthead( 'Pharmacy tenant directory', 'A clean platform view of tenant identity, owner, operational scope and licence posture.', 'Tenant control / isolated organisations', network_admin_url( 'admin.php?page=pharmasure-add-tenant' ), 'Provision pharmacy' ); ?>
			<nav class="ps-platform-filters" aria-label="Tenant status filters"><?php foreach ( array( '' => 'All', 'active' => 'Active', 'trial' => 'Trial', 'suspended' => 'Suspended' ) as $value => $label ) : ?><a class="<?php echo $status === $value ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'pharmasure-tenants', 'status' => $value ), network_admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?></nav>
			<section class="ps-tenant-directory" aria-label="Pharmacy tenants">
				<?php if ( ! $tenants ) : ?><div class="ps-platform-empty"><h2>No tenants in this view</h2><p>Provision a pharmacy or choose another status filter.</p></div><?php endif; ?>
				<?php foreach ( $tenants as $tenant ) : $site = ! empty( $tenant['site_id'] ) ? get_site( (int) $tenant['site_id'] ) : null; if ( ! $site ) { $matches = get_sites( array( 'path' => '/' . $tenant['slug'] . '/', 'number' => 1 ) ); $site = $matches ? reset( $matches ) : null; } ?>
					<article class="ps-tenant-card">
						<header><div class="ps-tenant-monogram"><?php echo esc_html( strtoupper( substr( $tenant['trading_name'] ?: $tenant['name'], 0, 2 ) ) ); ?></div><div><h2><?php echo esc_html( $tenant['trading_name'] ?: $tenant['name'] ); ?></h2><code>/<?php echo esc_html( $tenant['slug'] ); ?></code></div><span class="ps-platform-status is-<?php echo esc_attr( $tenant['status'] ); ?>"><?php echo esc_html( $tenant['status'] ); ?></span></header>
						<dl><div><dt>Owner</dt><dd><?php echo esc_html( $tenant['owner_email'] ?: $tenant['primary_contact_email'] ); ?></dd></div><div><dt>Branches</dt><dd><?php echo esc_html( number_format_i18n( $tenant['branch_count'] ) ); ?></dd></div><div><dt>Accounts</dt><dd><?php echo esc_html( number_format_i18n( $tenant['member_count'] ) ); ?></dd></div><div><dt>Licence</dt><dd><?php echo esc_html( $tenant['licence_status'] ? ucfirst( $tenant['licence_status'] ) : 'Needs attention' ); ?></dd></div><div><dt>Created</dt><dd><?php echo esc_html( mysql2date( 'M j, Y', $tenant['created_at'] ) ); ?></dd></div></dl>
						<footer><?php if ( $site ) : ?><a class="button" href="<?php echo esc_url( get_site_url( (int) $site->blog_id, '/app/' ) ); ?>">Open application</a><a class="button" href="<?php echo esc_url( get_admin_url( (int) $site->blog_id ) ); ?>">Site administration</a><?php else : ?><span class="ps-platform-warning">Tenant site mapping requires repair</span><?php endif; ?></footer>
					</article>
				<?php endforeach; ?>
			</section>
		</div>
		<?php
	}

	public static function render_add_tenant() {
		self::guard(); $notice = null; $result = null;
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['pharmasure_add_tenant_nonce'] ) ) {
			check_admin_referer( 'pharmasure_add_tenant', 'pharmasure_add_tenant_nonce' );
			$result = ( new TenantProvisioningService() )->create( array_map( static fn( $value ) => is_string( $value ) ? wp_unslash( $value ) : $value, $_POST ) );
			$notice = is_wp_error( $result ) ? array( 'error', $result->get_error_message() ) : array( 'success', 'The pharmacy tenant is fully provisioned and ready for owner onboarding.' );
		}
		?>
		<div class="wrap ps-platform-admin">
			<?php self::masthead( 'Provision a pharmacy', 'Create the tenant, secure site, first branch, owner identity and enterprise trial in one controlled workflow.', 'Onboarding / one atomic handoff' ); ?>
			<?php if ( $notice ) : ?><div class="ps-platform-notice is-<?php echo esc_attr( $notice[0] ); ?>" role="<?php echo 'error' === $notice[0] ? 'alert' : 'status'; ?>"><strong><?php echo 'error' === $notice[0] ? 'Provisioning stopped' : 'Provisioning complete'; ?></strong><span><?php echo esc_html( $notice[1] ); ?></span></div><?php endif; ?>
			<?php if ( is_array( $result ) ) : ?>
				<section class="ps-provision-result"><span class="ps-platform-kicker">One-time handoff</span><h2><?php echo esc_html( $result['owner_email'] ); ?></h2><p>Store this temporary password securely. It will not be shown again, and the owner is prompted to change it.</p><div><label>Application URL<code><?php echo esc_html( untrailingslashit( $result['site_url'] ) . '/app/' ); ?></code></label><label>Owner email<code><?php echo esc_html( $result['owner_email'] ); ?></code></label><label>Temporary password<code><?php echo esc_html( $result['temporary_password'] ); ?></code></label></div><a class="button button-primary" href="<?php echo esc_url( untrailingslashit( $result['site_url'] ) . '/wp-login.php' ); ?>">Open owner login</a><a class="button" href="<?php echo esc_url( network_admin_url( 'admin.php?page=pharmasure-tenants' ) ); ?>">Return to tenant directory</a></section>
			<?php else : ?>
			<form method="post" class="ps-provision-form" id="pharmasure-tenant-form">
				<section><header><span>01</span><div><h2>Pharmacy identity</h2><p>The legal organisation and isolated tenant URL.</p></div></header><div class="ps-platform-fields"><?php self::input( 'legal_name', 'Legal name', true ); self::input( 'trading_name', 'Trading name', true ); self::input( 'slug', 'Tenant URL slug', true, 'text', '', 'lowercase letters, numbers and hyphens' ); self::input( 'country', 'Country (ISO-2)', true, 'text', 'ZW' ); self::input( 'currency', 'Currency (ISO-3)', true, 'text', 'USD' ); self::input( 'timezone', 'Timezone', true, 'text', 'Africa/Harare' ); ?></div></section>
				<section><header><span>02</span><div><h2>First branch</h2><p>The owner can add more branches after onboarding.</p></div></header><div class="ps-platform-fields"><?php self::input( 'branch_name', 'Branch name', true, 'text', 'Main Branch' ); self::input( 'branch_code', 'Branch code', true, 'text', 'MAIN' ); self::input( 'address', 'Street address', false ); self::input( 'city', 'City', false ); self::input( 'phone', 'Phone', false, 'tel' ); ?></div></section>
				<section><header><span>03</span><div><h2>Owner handoff</h2><p>A unique tenant-only identity with all-branch authority.</p></div></header><div class="ps-platform-fields"><?php self::input( 'owner_name', 'Owner name', true ); self::input( 'owner_email', 'Owner email', true, 'email' ); self::input( 'owner_password', 'Temporary password', false, 'password', '', 'leave empty to generate; minimum 12 characters with upper/lower-case and a number' ); ?><div class="ps-platform-plan"><span>Initial licence</span><strong>Enterprise trial / 30 days</strong><small>7-day offline grace, 10 branches, 25 seats, reporting and offline operations included.</small></div></div></section>
				<?php wp_nonce_field( 'pharmasure_add_tenant', 'pharmasure_add_tenant_nonce' ); ?><footer><p>Provisioning is compensating: a failure removes the partially created tenant and site.</p><button class="button button-primary button-hero" type="submit">Provision pharmacy tenant</button></footer>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Read each site's operational records; local IDs are unique only within their site. */
	public static function platform_tenants() {
		self::guard(); global $wpdb;
		$rows = array();
		$sites = get_sites( array( 'network_id' => get_current_network_id(), 'number' => 0, 'deleted' => 0, 'orderby' => 'id', 'order' => 'ASC' ) );
		foreach ( $sites as $site ) {
			$p = $wpdb->get_blog_prefix( (int) $site->blog_id ) . 'ps_';
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $p . 'tenants' ) ) ) ) { continue; }
			$tenants = $wpdb->get_results( "SELECT t.*,
			 (SELECT COUNT(*) FROM {$p}branches b WHERE b.tenant_id=t.id AND b.is_active=1) branch_count,
			 (SELECT COUNT(*) FROM {$p}tenant_memberships m WHERE m.tenant_id=t.id AND m.is_active=1) member_count,
			 (SELECT u.user_email FROM {$p}tenant_memberships m JOIN {$wpdb->users} u ON u.ID=m.user_id WHERE m.tenant_id=t.id AND m.role='owner' AND m.is_active=1 ORDER BY m.id LIMIT 1) owner_email,
			 (SELECT map.site_id FROM {$p}tenant_site_mapping map WHERE map.tenant_id=t.id ORDER BY map.id DESC LIMIT 1) site_id
			 FROM {$p}tenants t ORDER BY t.created_at DESC,t.id DESC", ARRAY_A );
			foreach ( $tenants as $tenant ) {
				// Root registry copies point to the pharmacy site; its own tables take precedence.
				$tenant['site_id'] = (int) $site->blog_id === (int) get_main_site_id() ? (int) $tenant['site_id'] : (int) $site->blog_id;
				$tenant['licence'] = $wpdb->get_row( $wpdb->prepare( "SELECT l.id,l.status,l.expires_at,l.grace_period_days,p.name AS plan_name FROM {$p}licences l LEFT JOIN {$p}plans p ON p.id=l.plan_id WHERE l.tenant_id=%d ORDER BY l.created_at DESC,l.id DESC LIMIT 1", $tenant['id'] ), ARRAY_A );
				$tenant['licence_status'] = self::effective_licence_status( $tenant['licence'] );
				$key = $tenant['site_id'] . ':' . $tenant['id'];
				$rows[ $key ] = $tenant;
			}
		}
		return array_values( $rows );
	}

	public static function effective_licence_status( $license ) {
		if ( ! $license ) { return ''; }
		$status = $license['status']; $expires = ! empty( $license['expires_at'] ) ? strtotime( $license['expires_at'] . ' UTC' ) : null;
		if ( in_array( $status, array( 'active', 'trial', 'grace' ), true ) && $expires && time() >= $expires ) {
			$status = time() < $expires + max( 0, (int) $license['grace_period_days'] ) * DAY_IN_SECONDS ? 'grace' : 'expired';
		}
		return $status;
	}

	public static function navigation() {
		if ( ! current_user_can( 'manage_network_options' ) ) { return; }
		$current = sanitize_key( wp_unslash( $_GET['page'] ?? 'pharmasure-admin' ) );
		echo '<nav class="ps-platform-navigation" aria-label="Platform administration">';
		foreach ( array( 'pharmasure-admin' => 'Platform overview', 'pharmasure-tenants' => 'Pharmacy tenants', 'pharmasure-licenses' => 'Licensing', 'pharmasure-add-tenant' => 'Provision pharmacy' ) as $page => $label ) {
			echo '<a href="' . esc_url( network_admin_url( 'admin.php?page=' . $page ) ) . '"' . ( $current === $page ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}
	private static function masthead( $title, $description, $eyebrow, $url = '', $label = '' ) { ?><header class="ps-platform-masthead"><div><span class="ps-platform-kicker"><?php echo esc_html( $eyebrow ); ?></span><h1><?php echo esc_html( $title ); ?></h1><p><?php echo esc_html( $description ); ?></p></div><?php if ( $url ) : ?><a class="button button-primary" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a><?php endif; ?></header><?php self::navigation(); }
	private static function input( $name, $label, $required = false, $type = 'text', $value = '', $help = '' ) { ?><label class="ps-platform-field"><span><?php echo esc_html( $label ); ?><?php if ( $required ) : ?><i>required</i><?php endif; ?></span><input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo 'password' === $type ? '' : esc_attr( wp_unslash( $_POST[ $name ] ?? $value ) ); ?>" <?php echo $required ? 'required' : ''; ?>><?php if ( $help ) : ?><small><?php echo esc_html( $help ); ?></small><?php endif; ?></label><?php }
	private static function guard() { if ( ! current_user_can( 'manage_network_options' ) ) { wp_die( esc_html__( 'Network super-administrator access is required.', 'pharmasure-tenancy' ), 403 ); } }
}
