<?php
namespace PharmaSure\Core\Admin;

use PharmaSure\Core\TenantContext;

final class ApplicationShell {
	public static function register() {
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'in_admin_header', array( self::class, 'render' ) );
		add_action( 'admin_post_pharmasure_select_branch', array( self::class, 'select_branch' ) );
		add_filter( 'admin_body_class', array( self::class, 'body_class' ) );
	}

	public static function is_pharmasure_screen() {
		// Network administration is the platform control plane, not a tenant workspace.
		if ( is_network_admin() ) { return false; }
		$page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen detection only.
		return str_starts_with( $page, 'pharmasure' );
	}

	public static function assets() {
		if ( ! self::is_pharmasure_screen() ) { return; }
		wp_enqueue_style( 'pharmasure-application-shell', PHARMASURE_CORE_URL . 'assets/application-shell.css', array(), PHARMASURE_CORE_VERSION );
		wp_enqueue_script( 'pharmasure-application-shell', PHARMASURE_CORE_URL . 'assets/application-shell.js', array(), PHARMASURE_CORE_VERSION, true );
	}

	public static function body_class( $classes ) { return self::is_pharmasure_screen() ? $classes . ' pharmasure-application' : $classes; }

	public static function render() {
		if ( ! self::is_pharmasure_screen() || ! is_user_logged_in() ) { return; }
		$context = TenantContext::instance();
		$tenant = (int) $context->get_tenant_id();
		$branches = self::branches( $tenant );
		$active = (int) $context->get_branch_id();
		$notice = sanitize_key( wp_unslash( $_GET['pharmasure_scope'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only.
		echo '<a class="ps-skip-link" href="#wpbody-content">' . esc_html__( 'Skip to workspace content', 'pharmasure-core' ) . '</a>';
		echo '<section class="ps-app-shell" aria-label="' . esc_attr__( 'PharmaSure workspace', 'pharmasure-core' ) . '"><div class="ps-app-shell__brand"><span aria-hidden="true">P</span><div><strong>PharmaSure</strong><small>' . esc_html__( 'Pharmacy operations', 'pharmasure-core' ) . '</small></div></div>';
		echo '<nav class="ps-app-shell__nav" aria-label="' . esc_attr__( 'Pharmacy modules', 'pharmasure-core' ) . '">';
		$current_page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation state only.
		foreach ( self::links() as $link ) { if ( current_user_can( $link['cap'] ) ) { echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . $link['page'] ) ) . '"' . ( $current_page === $link['page'] ? ' aria-current="page"' : '' ) . '>' . esc_html( $link['label'] ) . '</a>'; } }
		echo '</nav>';
		if ( $tenant ) {
			echo '<form class="ps-app-shell__scope" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pharmasure_select_branch"><input type="hidden" name="return_url" value="' . esc_attr( self::current_url() ) . '">';
			wp_nonce_field( 'pharmasure_select_branch' );
			echo '<label for="ps-active-branch">' . esc_html__( 'Working branch', 'pharmasure-core' ) . '</label><select id="ps-active-branch" name="branch_id" onchange="this.form.submit()"><option value="">' . esc_html__( 'Choose branch', 'pharmasure-core' ) . '</option>';
			foreach ( $branches as $branch ) { echo '<option value="' . (int) $branch['id'] . '" ' . selected( $active, $branch['id'], false ) . '>' . esc_html( $branch['name'] ) . '</option>'; }
			echo '</select><noscript>'; submit_button( __( 'Use branch', 'pharmasure-core' ), 'secondary', '', false ); echo '</noscript></form>';
		}
		echo '</section><div id="ps-announcer" class="screen-reader-text" role="status" aria-live="polite" aria-atomic="true">' . ( 'updated' === $notice ? esc_html__( 'Working branch updated.', 'pharmasure-core' ) : '' ) . '</div>';
	}

	public static function select_branch() {
		if ( ! is_user_logged_in() ) { wp_die( esc_html__( 'Authentication is required.', 'pharmasure-core' ), 401 ); }
		check_admin_referer( 'pharmasure_select_branch' );
		$branch = absint( $_POST['branch_id'] ?? 0 );
		$context = TenantContext::instance();
		if ( ! $branch || ! $context->set_branch( $branch ) ) { wp_die( esc_html__( 'The selected branch is not authorized.', 'pharmasure-core' ), 403 ); }
		do_action( 'pharmasure_audit_log', array( 'tenant_id' => $context->get_tenant_id(), 'actor_id' => get_current_user_id(), 'action' => 'workspace.branch_selected', 'object_type' => 'branch', 'object_id' => $branch ) );
		$return = esc_url_raw( wp_unslash( $_POST['return_url'] ?? admin_url( 'admin.php?page=pharmasure-core' ) ) );
		$return = wp_validate_redirect( $return, admin_url( 'admin.php?page=pharmasure-core' ) );
		wp_safe_redirect( add_query_arg( 'pharmasure_scope', 'updated', $return ) ); exit;
	}

	private static function branches( $tenant ) {
		if ( ! $tenant ) { return array(); } global $wpdb; $context = TenantContext::instance();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,name FROM {$wpdb->prefix}ps_branches WHERE tenant_id=%d AND is_active=1 ORDER BY name", $tenant ), ARRAY_A );
		return array_values( array_filter( $rows ?: array(), static fn( $row ) => $context->can_access_branch( (int) $row['id'] ) ) );
	}

	private static function links() { return array(
		array( 'page'=>'pharmasure-core', 'label'=>__( 'Overview', 'pharmasure-core' ), 'cap'=>'pharmasure_view_inventory' ),
		array( 'page'=>'pharmasure-pos', 'label'=>__( 'Point of Sale', 'pharmasure-core' ), 'cap'=>'pharmasure_access_pos' ),
		array( 'page'=>'pharmasure-inventory', 'label'=>__( 'Inventory', 'pharmasure-core' ), 'cap'=>'pharmasure_view_inventory' ),
		array( 'page'=>'pharmasure-clinical', 'label'=>__( 'Clinical', 'pharmasure-core' ), 'cap'=>'pharmasure_view_prescriptions' ),
		array( 'page'=>'pharmasure-claims', 'label'=>__( 'Claims', 'pharmasure-core' ), 'cap'=>'pharmasure_manage_claims' ),
		array( 'page'=>'pharmasure-reports', 'label'=>__( 'Reports', 'pharmasure-core' ), 'cap'=>'pharmasure_view_reports' ),
		array( 'page'=>'pharmasure-offline-operations', 'label'=>__( 'Offline', 'pharmasure-core' ), 'cap'=>'pharmasure_view_offline_operations' ),
		array( 'page'=>'pharmasure-account', 'label'=>__( 'Account', 'pharmasure-core' ), 'cap'=>'read' ),
	); }

	private static function current_url() { $scheme = is_ssl() ? 'https://' : 'http://'; return $scheme . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/wp-admin/' ) ); }
}
