<?php
namespace PharmaSure\Tenancy\Admin;

class TenantAdmin {
	public static function register_pages() {
		if ( ! is_multisite() ) {
			return;
		}

		add_menu_page(
			'PharmaSure Tenants',
			'PharmaSure Tenants',
			'manage_network',
			'pharmasure-tenants',
			[ self::class, 'render_tenant_list' ],
			'dashicons-hospital',
			25
		);

		add_submenu_page(
			'pharmasure-tenants',
			'Add Tenant',
			'Add Tenant',
			'manage_network',
			'pharmasure-add-tenant',
			[ self::class, 'render_add_tenant' ]
		);
	}

	public static function register_network_pages() {
		add_menu_page(
			'PharmaSure Administration',
			'PharmaSure Admin',
			'manage_network',
			'pharmasure-admin',
			[ self::class, 'render_network_admin' ],
			'dashicons-admin-generic',
			26
		);
	}

	public static function enqueue_assets( $hook_suffix ) {
		if ( strpos( $hook_suffix, 'pharmasure' ) !== false ) {
			wp_enqueue_style( 'pharmasure-admin', plugins_url( 'assets/admin.css', __DIR__ . '/../' ), [], '1.0.0' );
			wp_enqueue_script( 'pharmasure-admin', plugins_url( 'assets/admin.js', __DIR__ . '/../' ), [ 'wp-api-fetch' ], '1.0.0', true );

			wp_localize_script( 'pharmasure-admin', 'pharmasureAdmin', [
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'rest'  => rest_url( 'pharmasure/v1' ),
			] );
		}
	}

	public static function render_tenant_list() {
		global $wpdb;
		$table = $wpdb->prefix . 'ps_tenants';

		$tenants = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE status != 'deleted' ORDER BY created_at DESC"
		);

		?>
		<div class="wrap">
			<h1>PharmaSure Tenants <a href="?page=pharmasure-add-tenant" class="page-title-action">Add New</a></h1>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>Name</th>
						<th>Slug</th>
						<th>Status</th>
						<th>Plan</th>
						<th>Created</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $tenants as $tenant ) {
						echo '<tr>';
						echo '<td>' . esc_html( $tenant->legal_name ) . '</td>';
						echo '<td>' . esc_html( $tenant->slug ) . '</td>';
						echo '<td><span class="badge badge-' . esc_attr( $tenant->status ) . '">' . esc_html( $tenant->status ) . '</span></td>';
						echo '<td>' . esc_html( $tenant->plan_type ) . '</td>';
						echo '<td>' . esc_html( mysql2date( 'Y-m-d', $tenant->created_at ) ) . '</td>';
						echo '<td>';
						echo '<a href="?page=pharmasure-tenant-detail&id=' . intval( $tenant->id ) . '">View</a> | ';
						echo '<a href="#" data-tenant-id="' . intval( $tenant->id ) . '" class="tenant-suspend">Suspend</a>';
						echo '</td>';
						echo '</tr>';
					}
					?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function render_add_tenant() {
		?>
		<div class="wrap">
			<h1>Add New Tenant</h1>
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
						<th><label for="plan_type">Plan</label></th>
						<td>
							<select id="plan_type" name="plan_type">
								<option value="starter">Starter</option>
								<option value="professional">Professional</option>
								<option value="enterprise">Enterprise</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="trial_days">Trial Days</label></th>
						<td><input type="number" id="trial_days" name="trial_days" value="14" class="small-text"></td>
					</tr>
				</table>
				<?php wp_nonce_field( 'pharmasure_add_tenant' ); ?>
				<?php submit_button( 'Create Tenant' ); ?>
			</form>
		</div>
		<?php
	}

	public static function render_network_admin() {
		?>
		<div class="wrap">
			<h1>PharmaSure Network Administration</h1>
			<div id="pharmasure-dashboard"></div>
		</div>
		<?php
	}
}
