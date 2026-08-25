<?php
namespace PharmaSure\Core\Admin;

use PharmaSure\Core\TenantContext;

/** Keep WordPress as platform infrastructure while presenting a tenant product UI. */
final class WhiteLabel {
	public static function register() {
		add_action( 'admin_menu', array( self::class, 'register_account_page' ), 30 );
		add_action( 'admin_menu', array( self::class, 'reduce_menu' ), PHP_INT_MAX );
		add_action( 'admin_bar_menu', array( self::class, 'reduce_toolbar' ), PHP_INT_MAX );
		add_action( 'wp_dashboard_setup', array( self::class, 'remove_dashboard_widgets' ), PHP_INT_MAX );
		add_action( 'admin_init', array( self::class, 'redirect_core_screens' ), 1 );
		add_action( 'admin_post_pharmasure_update_account', array( self::class, 'save_account' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'admin_styles' ) );
		add_action( 'login_enqueue_scripts', array( self::class, 'login_styles' ) );
		add_filter( 'login_headerurl', array( self::class, 'login_url' ) );
		add_filter( 'login_headertext', array( self::class, 'login_title' ) );
		add_filter( 'admin_title', array( self::class, 'admin_title' ), 10, 2 );
		add_filter( 'admin_footer_text', array( self::class, 'footer_text' ) );
		add_filter( 'update_footer', array( self::class, 'hide_version' ), PHP_INT_MAX );
		add_filter( 'screen_options_show_screen', array( self::class, 'show_wordpress_controls' ) );
		add_action( 'current_screen', array( self::class, 'hide_help_tabs' ) );
	}

	public static function register_account_page() {
		add_submenu_page( 'pharmasure-core', __( 'Pharmacy Account', 'pharmasure-core' ), __( 'My Account', 'pharmasure-core' ), 'read', 'pharmasure-account', array( self::class, 'render_account' ) );
	}

	public static function is_tenant_user() {
		return is_user_logged_in()
			&& ! is_super_admin()
			&& ! is_main_site()
			&& (int) TenantContext::instance()->get_tenant_id() > 0;
	}

	public static function reduce_menu() {
		if ( ! self::is_tenant_user() ) { return; }
		foreach ( array( 'index.php', 'edit.php', 'upload.php', 'edit.php?post_type=page', 'edit-comments.php', 'themes.php', 'plugins.php', 'users.php', 'tools.php', 'options-general.php' ) as $slug ) {
			remove_menu_page( $slug );
		}
	}

	public static function reduce_toolbar( $bar ) {
		if ( ! self::is_tenant_user() ) { return; }
		foreach ( array( 'wp-logo', 'about', 'wporg', 'documentation', 'support-forums', 'feedback', 'my-sites', 'updates', 'comments', 'new-content', 'customize', 'user-info', 'edit-profile' ) as $node ) {
			$bar->remove_node( $node );
		}
		$bar->add_node(
			array(
				'id' => 'site-name',
				'title' => esc_html( get_bloginfo( 'name' ) ),
				'href' => admin_url( 'admin.php?page=pharmasure-core' ),
			)
		);
		$user = wp_get_current_user();
		$bar->add_node( array( 'id' => 'my-account', 'title' => esc_html( $user->display_name ), 'href' => admin_url( 'admin.php?page=pharmasure-account' ) ) );
		$bar->add_node( array( 'parent' => 'my-account', 'id' => 'pharmasure-account', 'title' => __( 'Account & security', 'pharmasure-core' ), 'href' => admin_url( 'admin.php?page=pharmasure-account' ) ) );
	}

	public static function remove_dashboard_widgets() {
		if ( ! self::is_tenant_user() ) { return; }
		global $wp_meta_boxes;
		$wp_meta_boxes['dashboard'] = array();
	}

	public static function redirect_core_screens() {
		if ( ! self::is_tenant_user() || wp_doing_ajax() ) { return; }
		global $pagenow;
		$allowed = array( 'admin.php', 'admin-post.php' );
		if ( 'admin.php' === $pagenow ) {
			$page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
			if ( str_starts_with( $page, 'pharmasure' ) ) { return; }
		}
		if ( in_array( $pagenow, $allowed, true ) && 'admin.php' !== $pagenow ) { return; }
		wp_safe_redirect( admin_url( 'admin.php?page=pharmasure-core' ) );
		exit;
	}

	public static function admin_styles() {
		if ( ! self::is_tenant_user() ) { return; }
		wp_enqueue_style( 'pharmasure-white-label', PHARMASURE_CORE_URL . 'assets/white-label.css', array(), PHARMASURE_CORE_VERSION );
	}

	public static function render_account() {
		if ( ! self::is_tenant_user() ) { wp_die( esc_html__( 'Tenant account access is required.', 'pharmasure-core' ), 403 ); }
		$user = wp_get_current_user();
		$status = sanitize_key( wp_unslash( $_GET['account_status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only.
		echo '<div class="wrap ps-account"><h1>' . esc_html__( 'Account & security', 'pharmasure-core' ) . '</h1>';
		if ( 'updated' === $status ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Your account was updated.', 'pharmasure-core' ) . '</p></div>'; }
		if ( 'password_error' === $status ) { echo '<div class="notice notice-error"><p>' . esc_html__( 'The current password is incorrect or the new passwords do not meet the requirements.', 'pharmasure-core' ) . '</p></div>'; }
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pharmasure_update_account">';
		wp_nonce_field( 'pharmasure_update_account' );
		echo '<section class="ps-account__card"><h2>' . esc_html__( 'Profile', 'pharmasure-core' ) . '</h2><label for="display_name">' . esc_html__( 'Display name', 'pharmasure-core' ) . '</label><input id="display_name" name="display_name" type="text" value="' . esc_attr( $user->display_name ) . '" required><label>' . esc_html__( 'Email address', 'pharmasure-core' ) . '</label><input type="email" value="' . esc_attr( $user->user_email ) . '" readonly></section>';
		echo '<section class="ps-account__card"><h2>' . esc_html__( 'Change password', 'pharmasure-core' ) . '</h2><p>' . esc_html__( 'Leave these fields empty to keep your current password.', 'pharmasure-core' ) . '</p><label for="current_password">' . esc_html__( 'Current password', 'pharmasure-core' ) . '</label><input id="current_password" name="current_password" type="password" autocomplete="current-password"><label for="new_password">' . esc_html__( 'New password (12 characters minimum)', 'pharmasure-core' ) . '</label><input id="new_password" name="new_password" type="password" minlength="12" autocomplete="new-password"><label for="confirm_password">' . esc_html__( 'Confirm new password', 'pharmasure-core' ) . '</label><input id="confirm_password" name="confirm_password" type="password" minlength="12" autocomplete="new-password"></section>';
		submit_button( __( 'Save account', 'pharmasure-core' ) );
		echo '</form></div>';
	}

	public static function save_account() {
		if ( ! self::is_tenant_user() ) { wp_die( esc_html__( 'Tenant account access is required.', 'pharmasure-core' ), 403 ); }
		check_admin_referer( 'pharmasure_update_account' );
		$user = wp_get_current_user();
		$display_name = sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) );
		$current = (string) wp_unslash( $_POST['current_password'] ?? '' );
		$new = (string) wp_unslash( $_POST['new_password'] ?? '' );
		$confirm = (string) wp_unslash( $_POST['confirm_password'] ?? '' );
		if ( '' !== $current || '' !== $new || '' !== $confirm ) {
			if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) || strlen( $new ) < 12 || $new !== $confirm ) {
				wp_safe_redirect( admin_url( 'admin.php?page=pharmasure-account&account_status=password_error' ) ); exit;
			}
		}
		if ( '' !== $display_name ) { wp_update_user( array( 'ID' => $user->ID, 'display_name' => $display_name ) ); }
		if ( '' !== $current || '' !== $new || '' !== $confirm ) {
			wp_set_password( $new, $user->ID );
			wp_set_auth_cookie( $user->ID, true, is_ssl() );
		}
		do_action( 'pharmasure_audit_log', array( 'tenant_id' => TenantContext::instance()->get_tenant_id(), 'actor_id' => $user->ID, 'action' => 'account.updated', 'object_type' => 'user', 'object_id' => $user->ID, 'status' => 'success' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=pharmasure-account&account_status=updated' ) ); exit;
	}

	public static function login_styles() {
		if ( is_main_site() ) { return; }
		echo '<style>
		body.login{background:linear-gradient(145deg,#f7fbfb,#edf7f6)}
		.login h1 a{width:72px;height:72px;border-radius:20px;background:none!important;background-color:#087f8c!important;box-shadow:0 14px 35px rgba(8,127,140,.22)}
		.login h1 a:before{display:grid;height:72px;place-items:center;color:#fff;content:"P";font:800 36px/1 system-ui}
		.login form{border:1px solid #dce9e8;border-radius:14px;box-shadow:0 20px 55px rgba(11,41,55,.1)}
		.wp-core-ui .button-primary{border-color:#087f8c;background:#087f8c}.login #backtoblog a,.login #nav a{color:#0b2937}
		</style>';
	}

	public static function login_url() { return is_main_site() ? network_home_url() : home_url( '/' ); }
	public static function login_title() { return is_main_site() ? 'PharmaSure Platform Administration' : get_bloginfo( 'name' ) . ' — PharmaSure'; }
	public static function admin_title( $admin_title, $title ) { return self::is_tenant_user() ? $title . ' — ' . get_bloginfo( 'name' ) . ' | PharmaSure' : $admin_title; }
	public static function footer_text( $text ) { return self::is_tenant_user() ? esc_html__( 'PharmaSure pharmacy operations', 'pharmasure-core' ) : $text; }
	public static function hide_version( $text ) { return self::is_tenant_user() ? '' : $text; }
	public static function show_wordpress_controls( $show ) { return self::is_tenant_user() ? false : $show; }
	public static function hide_help_tabs( $screen ) {
		if ( self::is_tenant_user() && $screen instanceof \WP_Screen ) {
			$screen->remove_help_tabs();
		}
	}
}
