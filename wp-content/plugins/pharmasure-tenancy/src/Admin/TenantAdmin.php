<?php
namespace PharmaSure\Tenancy\Admin;

class TenantAdmin {
	public static function register_pages() {
		// Platform administration is registered in Network Admin below.
	}

	public static function register_network_pages() {
		add_menu_page(
			'PharmaSure Administration',
			'PharmaSure Admin',
			'manage_network_options',
			'pharmasure-admin',
			[ self::class, 'render_network_admin' ],
			'dashicons-admin-generic',
			26
		);

		add_submenu_page(
			'pharmasure-admin',
			'PharmaSure Dashboard',
			'Dashboard',
			'manage_network_options',
			'pharmasure-admin',
			[ self::class, 'render_network_admin' ]
		);

		add_submenu_page(
			'pharmasure-admin',
			'PharmaSure Tenants',
			'Tenants',
			'manage_network_options',
			'pharmasure-tenants',
			[ self::class, 'render_tenant_list' ]
		);

		add_submenu_page(
			'pharmasure-admin',
			'Add Tenant',
			'Add Tenant',
			'manage_network_options',
			'pharmasure-add-tenant',
			[ self::class, 'render_add_tenant' ]
		);
	}

	public static function enqueue_assets( $hook_suffix ) {
		// The current Network Admin UI is rendered server-side and has no asset dependency.
	}

	public static function render_tenant_list() {
		global $wpdb;
		$table = $wpdb->prefix . 'ps_tenants';

		$tenants = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE status != 'archived' ORDER BY created_at DESC"
		);

		?>
		<div class="wrap">
			<h1>PharmaSure Tenants <a href="<?php echo esc_url( network_admin_url( 'admin.php?page=pharmasure-add-tenant' ) ); ?>" class="page-title-action">Add New</a></h1>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>Name</th>
						<th>Slug</th>
						<th>Status</th>
						<th>Plan</th>
						<th>Created</th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $tenants as $tenant ) {
						echo '<tr>';
						echo '<td>' . esc_html( $tenant->name ) . '</td>';
						echo '<td>' . esc_html( $tenant->slug ) . '</td>';
						echo '<td><span class="badge badge-' . esc_attr( $tenant->status ) . '">' . esc_html( $tenant->status ) . '</span></td>';
						echo '<td>-</td>';
						echo '<td>' . esc_html( mysql2date( 'Y-m-d', $tenant->created_at ) ) . '</td>';
						echo '</tr>';
					}
					?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function render_add_tenant() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage PharmaSure tenants.', 'pharmasure-tenancy' ) );
		}

		$notice = null;
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['pharmasure_add_tenant_nonce'] ) ) {
			check_admin_referer( 'pharmasure_add_tenant', 'pharmasure_add_tenant_nonce' );
			$service = new \PharmaSure\Tenancy\Services\TenantService();
			$result = $service->create_tenant(
				[
					'legal_name' => sanitize_text_field( wp_unslash( $_POST['legal_name'] ?? '' ) ),
					'trading_name' => sanitize_text_field( wp_unslash( $_POST['trading_name'] ?? '' ) ),
					'slug' => sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) ),
					'owner_email' => sanitize_email( wp_unslash( $_POST['owner_email'] ?? '' ) ),
					'country' => sanitize_text_field( wp_unslash( $_POST['country'] ?? '' ) ),
					'currency' => strtoupper( sanitize_text_field( wp_unslash( $_POST['currency'] ?? 'USD' ) ) ),
					'timezone' => sanitize_text_field( wp_unslash( $_POST['timezone'] ?? 'Africa/Harare' ) ),
					'address' => sanitize_text_field( wp_unslash( $_POST['address'] ?? '' ) ),
					'phone' => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
				]
			);
			$notice = is_wp_error( $result )
				? [ 'type' => 'error', 'message' => $result->get_error_message() ]
				: [ 'type' => 'success', 'message' => 'Tenant created successfully.' ];
		}
		?>
		<div class="wrap">
			<h1>Add New Tenant</h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>
			<form method="post" id="pharmasure-tenant-form">
				<table class="form-table">
					<tr>
						<th><label for="legal_name">Legal Name</label></th>
						<td><input type="text" id="legal_name" name="legal_name" required class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="trading_name">Trading Name</label></th>
						<td><input type="text" id="trading_name" name="trading_name" required class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="slug">Slug</label></th>
						<td><input type="text" id="slug" name="slug" required class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="owner_email">Owner Email</label></th>
						<td><input type="email" id="owner_email" name="owner_email" required class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="country">Country</label></th>
						<td><input type="text" id="country" name="country" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="currency">Currency</label></th>
						<td><input type="text" id="currency" name="currency" value="USD" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="timezone">Timezone</label></th>
						<td><input type="text" id="timezone" name="timezone" value="Africa/Harare" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="address">Address</label></th>
						<td><input type="text" id="address" name="address" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="phone">Phone</label></th>
						<td><input type="text" id="phone" name="phone" class="regular-text"></td>
					</tr>
				</table>
				<?php wp_nonce_field( 'pharmasure_add_tenant', 'pharmasure_add_tenant_nonce' ); ?>
				<?php submit_button( 'Create Tenant' ); ?>
			</form>
		</div>
		<?php
	}

	public static function render_network_admin() {
		global $wpdb;
		$counts = [
			'tenants' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ps_tenants WHERE status != 'archived'" ),
			'branches' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ps_branches WHERE is_active = 1" ),
			'members' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ps_tenant_memberships WHERE is_active = 1" ),
			'licences' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ps_licences WHERE status IN ('active', 'trial', 'grace')" ),
		];
		?>
		<div class="wrap">
			<h1>PharmaSure Network Administration</h1>
			<p>Manage pharmacy tenants and monitor the shared PharmaSure platform.</p>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;max-width:900px;margin:24px 0;">
				<?php foreach ( [ 'tenants' => 'Tenants', 'branches' => 'Active branches', 'members' => 'Active members', 'licences' => 'Active licences' ] as $key => $label ) : ?>
					<div class="card" style="margin:0;min-width:0;">
						<h2 style="margin-top:0;"><?php echo esc_html( $label ); ?></h2>
						<p style="font-size:32px;line-height:1;margin:12px 0 0;"><?php echo esc_html( number_format_i18n( $counts[ $key ] ) ); ?></p>
					</div>
				<?php endforeach; ?>
			</div>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( network_admin_url( 'admin.php?page=pharmasure-tenants' ) ); ?>">View tenants</a>
				<a class="button" href="<?php echo esc_url( network_admin_url( 'admin.php?page=pharmasure-add-tenant' ) ); ?>">Add tenant</a>
			</p>
		</div>
		<?php
	}
}
