<?php
/**
 * Standalone PharmaSure application launcher.
 *
 * @package PharmaSure_Core
 */

namespace PharmaSure\Core;

final class AppLauncher {
	private const REWRITE_VERSION = '2';

	/** Move a root /app request into the authenticated user's tenant site. */
	public static function bootstrap_context() {
		if ( self::platform_entry_url() ) { return; }
		if ( ! self::is_app_request() || ! is_multisite() || ! is_user_logged_in() || is_super_admin() ) {
			return;
		}

		$site_id = (int) get_user_meta( get_current_user_id(), 'primary_blog', true );
		if ( ! $site_id || $site_id === get_current_blog_id() || ! get_site( $site_id ) ) {
			return;
		}

		$sites = get_blogs_of_user( get_current_user_id(), true );
		if ( isset( $sites[ $site_id ] ) ) {
			switch_to_blog( $site_id );
		}
	}

	public static function register() {
		add_action( 'init', array( self::class, 'register_rewrite' ), 1 );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_filter( 'redirect_canonical', array( self::class, 'disable_canonical_redirect' ), 10, 2 );
		add_action( 'template_redirect', array( self::class, 'render' ), 0 );
		add_action( 'admin_init', array( self::class, 'redirect_staff_admin' ), 2 );
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );
	}

	public static function register_rewrite() {
		// Reserve /app/print/* for the standalone document renderer.
		add_rewrite_rule( '^app(?:/(?!print(?:/|$)).*)?/?$', 'index.php?pharmasure_app=1', 'top' );
		if ( self::REWRITE_VERSION !== get_site_option( 'pharmasure_app_rewrite_version' ) ) {
			flush_rewrite_rules( false );
			update_site_option( 'pharmasure_app_rewrite_version', self::REWRITE_VERSION );
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = 'pharmasure_app';
		return $vars;
	}

	public static function disable_canonical_redirect( $redirect_url, $requested_url ) {
		return self::is_app_request() ? false : $redirect_url;
	}

	/** Render a standalone document with no theme or WordPress presentation assets. */
	public static function render() {
		if ( ! self::is_app_request() ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		$platform_url = self::platform_entry_url();
		if ( $platform_url ) { wp_safe_redirect( $platform_url ); exit; }
		if ( ! current_user_can( 'pharmasure_view_inventory' ) ) {
			wp_die( esc_html__( 'Pharmacy workspace access is required.', 'pharmasure-core' ), 403 );
		}

		$context = TenantContext::instance();
		if ( ! (int) $context->get_tenant_id() ) {
			wp_die( esc_html__( 'No authorized pharmacy context is available.', 'pharmasure-core' ), 403 );
		}

		$csp_nonce = base64_encode( random_bytes( 18 ) );
		$config    = self::config( $context );
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		header( "Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$csp_nonce}'; style-src 'self'; img-src 'self' data:; connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'" );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'X-Frame-Options: DENY' );
		header( 'X-Content-Type-Options: nosniff' );

		$template = PHARMASURE_CORE_PATH . 'templates/app-shell.php';
		if ( ! is_readable( $template ) ) {
			wp_die( esc_html__( 'The application shell is unavailable.', 'pharmasure-core' ), 500 );
		}
		include $template;
		exit;
	}

	/** Keep pharmacy staff in the product surface while retaining wp-admin for platform administration. */
	public static function platform_entry_url() {
		if ( ! is_multisite() || ! is_user_logged_in() || ! is_super_admin() || ! current_user_can( 'manage_network_options' ) ) { return null; }
		$path = untrailingslashit( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) );
		if ( ! in_array( $path, array( '/app', '/app/platform' ), true ) ) { return null; }
		return network_admin_url( 'admin.php?page=pharmasure-admin' );
	}

	public static function redirect_staff_admin() {
		if ( wp_doing_ajax() || ! is_user_logged_in() || is_super_admin() ) {
			return;
		}
		global $pagenow;
		if ( in_array( $pagenow, array( 'admin-post.php', 'admin-ajax.php' ), true ) ) {
			return;
		}

		$primary_site = (int) get_user_meta( get_current_user_id(), 'primary_blog', true );
		if ( is_multisite() && $primary_site && $primary_site !== get_main_site_id() ) {
			wp_safe_redirect( network_home_url( '/app/' ) );
			exit;
		}
		if ( current_user_can( 'pharmasure_view_inventory' ) && (int) TenantContext::instance()->get_tenant_id() ) {
			wp_safe_redirect( network_home_url( '/app/' ) );
			exit;
		}
	}

	public static function register_rest_routes() {
		register_rest_route('pharmasure/v1','/app/preferences/text-size',array('methods'=>\WP_REST_Server::CREATABLE,'callback'=>array(self::class,'save_text_size'),'permission_callback'=>array(self::class,'can_view')));
		register_rest_route('pharmasure/v1','/app/provider',array('methods'=>\WP_REST_Server::CREATABLE,'callback'=>array(self::class,'save_provider'),'permission_callback'=>static fn()=>current_user_can('pharmasure_manage_tenant')));
		register_rest_route('pharmasure/v1','/app/pricing/markup',array('methods'=>\WP_REST_Server::CREATABLE,'callback'=>array(self::class,'save_markup'),'permission_callback'=>static fn()=>current_user_can('pharmasure_manage_tenant')));
		register_rest_route('pharmasure/v1','/app/notifications',array('methods'=>\WP_REST_Server::READABLE,'callback'=>array(self::class,'notifications'),'permission_callback'=>array(self::class,'can_view')));
		register_rest_route(
			'pharmasure/v1',
			'/app/overview',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'overview' ),
				'permission_callback' => array( self::class, 'can_view' ),
			)
		);
		register_rest_route(
			'pharmasure/v1',
			'/app/branch',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'select_branch' ),
				'permission_callback' => array( self::class, 'can_view' ),
				'args'                => array(
					'branch_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				),
			)
		);
		register_rest_route(
			'pharmasure/v1',
			'/app/preferences/theme',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'save_theme' ),
				'permission_callback' => array( self::class, 'can_view' ),
				'args'                => array(
					'theme' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static fn( $value ) => in_array( $value, array( 'dark', 'light' ), true ),
					),
				),
			)
		);
	}


	/** Server-derived visibility; never accept roles or capabilities from the client. */
	public static function inventory_views() {
		if (current_user_can('pharmasure_manage_stock') || current_user_can('pharmasure_view_audit_log')) { return array('catalogue','batches','receipts','movements','low-stock','expiry','suppliers'); }
		return array('catalogue');
	}

	public static function workspace_permissions() {
		$modules = array('overview'=>'pharmasure_manage_stock','pos'=>'pharmasure_access_pos','inventory'=>'pharmasure_view_inventory','clinical'=>'pharmasure_view_prescriptions','claims'=>'pharmasure_prepare_claims','reports'=>'pharmasure_view_reports','offline'=>'pharmasure_view_offline_operations','account'=>'pharmasure_manage_users');
		$result = array();
		foreach ($modules as $module=>$cap) { $result[$module] = current_user_can($cap); }
		$result['manage_stock'] = current_user_can('pharmasure_manage_stock');
		$result['manage_inventory'] = current_user_can('pharmasure_manage_inventory');
		$result['manage_tenant'] = current_user_can('pharmasure_manage_tenant');
		return $result;
	}

	public static function save_text_size( \WP_REST_Request $request ) {
		$size = sanitize_key((string) $request->get_param('text_size'));
		if (!in_array($size,array('standard','large','extra-large'),true)) { return new \WP_Error('invalid_text_size','Choose standard, large or extra-large text.',array('status'=>422)); }
		$user = get_current_user_id();
		if (!$user) { return new \WP_Error('forbidden','Sign in to save preferences.',array('status'=>403)); }
		if (get_user_meta($user,'pharmasure_text_size',true) !== $size && false === update_user_meta($user,'pharmasure_text_size',$size)) { return new \WP_Error('preference_not_saved','Text size could not be saved.',array('status'=>500)); }
		return rest_ensure_response(array('text_size'=>$size));
	}

	public static function save_provider( \WP_REST_Request $request ) {
		$tenant=(int)TenantContext::instance()->get_tenant_id();
		if (!$tenant || !current_user_can('pharmasure_manage_tenant')) { return new \WP_Error('forbidden','Pharmacy settings permission is required.',array('status'=>403)); }
		$value=trim(sanitize_text_field($request->get_param('ahfoz_provider_number') ?? ''));
		if (strlen($value)>100) { return new \WP_Error('invalid_provider_number','Provider number must be at most 100 characters.',array('status'=>422)); }
		$key='pharmasure_ahfoz_provider_'.$tenant;
		if (get_option($key,'')!==$value && !update_option($key,$value,false)) { return new \WP_Error('provider_not_saved','Provider number could not be saved.',array('status'=>500)); }
		do_action('pharmasure_audit_log',array('tenant_id'=>$tenant,'actor_id'=>get_current_user_id(),'action'=>'claims.provider_updated','object_type'=>'tenant','object_id'=>$tenant,'status'=>'success'));
		return rest_ensure_response(array('ahfoz_provider_number'=>$value));
	}

	public static function save_markup( \WP_REST_Request $request ) {
		$context = TenantContext::instance(); $tenant = (int)$context->get_tenant_id();
		$value = $request->get_param('markup_percent');
		if (!$tenant || !current_user_can('pharmasure_manage_tenant')) { return new \WP_Error('forbidden','Pharmacy settings permission is required.',array('status'=>403)); }
		if (!is_numeric($value) || !is_finite((float)$value) || (float)$value<0 || (float)$value>1000) { return new \WP_Error('invalid_markup','Markup must be between 0 and 1000 percent.',array('status'=>422)); }
		$key='pharmasure_receiving_markup_'.$tenant; $value=round((float)$value,2);
		if (get_option($key,null) !== $value && !update_option($key,$value,false)) { return new \WP_Error('markup_not_saved','Markup could not be saved.',array('status'=>500)); }
		do_action('pharmasure_audit_log',array('tenant_id'=>$tenant,'actor_id'=>get_current_user_id(),'action'=>'inventory.markup_updated','object_type'=>'tenant','object_id'=>$tenant,'status'=>'success','details'=>array('markup_percent'=>$value)));
		return rest_ensure_response(array('markup_percent'=>$value));
	}

	/** Live, actionable branch queue, filtered before any data leaves the server. */
	public static function notifications() {
		$context=TenantContext::instance(); $tenant=(int)$context->get_tenant_id(); $branch=(int)$context->get_branch_id();
		if (!$tenant || !$branch) { return new \WP_Error('branch_context_required','Select an authorized working branch.',array('status'=>403)); }
		global $wpdb; $p=$wpdb->prefix.'ps_'; $items=array();
		$exists=static fn($table)=>$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$p.$table)) === $p.$table;
		if (current_user_can('pharmasure_review_prescriptions') && $exists('prescriptions')) {
			$count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}prescriptions WHERE tenant_id=%d AND branch_id=%d AND status='pending_review'",$tenant,$branch));
			if ($count) { $items[]=array('id'=>'review','route'=>'clinical','filter'=>'pending_review','label'=>"{$count} prescriptions need pharmacist review",'count'=>$count); }
		}
		if (current_user_can('pharmasure_dispense_medications') && $exists('prescriptions')) {
			$count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}prescriptions WHERE tenant_id=%d AND branch_id=%d AND status='approved'",$tenant,$branch));
			if ($count) { $items[]=array('id'=>'dispense','route'=>'clinical','filter'=>'approved','label'=>"{$count} approved prescriptions ready to dispense",'count'=>$count); }
		}
		if (current_user_can('pharmasure_access_pos') && class_exists('\\PharmaSure\\POS\\Services\\PosService')) {
			$queue=(new \PharmaSure\POS\Services\PosService())->prescription_payments($tenant,$branch);
			if ($queue) { $items[]=array('id'=>'rx-payments','route'=>'pos','label'=>count($queue).' dispensed prescriptions ready for payment','count'=>count($queue)); }
		}
		if (current_user_can('pharmasure_manage_stock') && $exists('batches')) {
			$count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}batches WHERE tenant_id=%d AND branch_id=%d AND quantity_available>0 AND status='active' AND expiry_date<=DATE_ADD(UTC_DATE(),INTERVAL 90 DAY)",$tenant,$branch));
			if ($count) { $items[]=array('id'=>'expiry','route'=>'inventory','view'=>'expiry','label'=>"{$count} expired or expiring batches need action",'count'=>$count); }
			$count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}batches WHERE tenant_id=%d AND branch_id=%d AND quantity_available>0 AND status IN ('expired','withdrawn')",$tenant,$branch));
			if ($count) { $items[]=array('id'=>'disposal','route'=>'inventory','view'=>'batches','label'=>"{$count} removed batches await disposal",'count'=>$count); }
			if ($exists('stock_transfers')) {
				$count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}stock_transfers WHERE tenant_id=%d AND to_branch_id=%d AND created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)",$tenant,$branch));
				if ($count) { $items[]=array('id'=>'transfers','route'=>'inventory','view'=>'movements','label'=>"{$count} stock transfers received in the last day",'count'=>$count); }
			}
		}
		if(current_user_can('pharmasure_prepare_claims') && $exists('claims')) {
			$count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}claims WHERE tenant_id=%d AND branch_id=%d AND status IN ('draft','queried','rejected')",$tenant,$branch));
			if($count){$items[]=array('id'=>'claims','route'=>'claims','label'=>"{$count} claims need attention",'count'=>$count);}
		}
		return rest_ensure_response(array('items'=>$items,'branch_id'=>$branch));
	}

	public static function can_view() {
		return current_user_can( 'pharmasure_view_inventory' )
			? true
			: new \WP_Error( 'forbidden', 'Pharmacy workspace access is required.', array( 'status' => 403 ) );
	}

	public static function overview() {
		if (!current_user_can('pharmasure_manage_stock')) { return new \WP_Error('forbidden','Stock management permission is required for this overview.',array('status'=>403)); }
		$context   = TenantContext::instance();
		$tenant_id = (int) $context->get_tenant_id();
		$branch_id = (int) $context->get_branch_id();
		if ( ! $tenant_id || ! $branch_id ) {
			return new \WP_Error( 'branch_context_required', 'Select an authorized working branch.', array( 'status' => 400 ) );
		}
		$licence = ( new LicenseManager( $tenant_id ) )->enforce_entitlement( 'inventory' );
		if ( is_wp_error( $licence ) ) { return $licence; }

		global $wpdb;
		$p = $wpdb->prefix . PHARMASURE_TABLE_PREFIX;
		$scope = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT t.trading_name,t.currency,b.name branch_name FROM {$p}tenants t JOIN {$p}branches b ON b.id=%d AND b.tenant_id=t.id AND b.is_active=1 WHERE t.id=%d",
				$branch_id,
				$tenant_id
			),
			ARRAY_A
		);
		if ( ! $scope ) {
			return new \WP_Error( 'invalid_scope', 'The active pharmacy scope is unavailable.', array( 'status' => 403 ) );
		}

		$active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}drugs WHERE tenant_id=%d AND status='active'", $tenant_id ) );
		$units  = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity_available),0) FROM {$p}stock_balances WHERE tenant_id=%d AND branch_id=%d", $tenant_id, $branch_id ) );
		$low    = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM (SELECT d.id FROM {$p}drugs d LEFT JOIN {$p}stock_balances s ON s.tenant_id=d.tenant_id AND s.drug_id=d.id AND s.branch_id=%d WHERE d.tenant_id=%d AND d.status='active' GROUP BY d.id,d.reorder_level HAVING COALESCE(SUM(s.quantity_available),0)<=d.reorder_level) scoped_low",
				$branch_id,
				$tenant_id
			)
		);
		$expiring = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}batches WHERE tenant_id=%d AND branch_id=%d AND status='active' AND quantity_available>0 AND expiry_date<=DATE_ADD(UTC_DATE(),INTERVAL 90 DAY)", $tenant_id, $branch_id ) );
		$movements = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.created_at,m.movement_type,m.quantity_delta,m.reference_type,m.reference_id,d.sku,d.name FROM {$p}stock_movements m JOIN {$p}drugs d ON d.id=m.drug_id AND d.tenant_id=m.tenant_id WHERE m.tenant_id=%d AND m.branch_id=%d ORDER BY m.created_at DESC,m.id DESC LIMIT %d",
				$tenant_id,
				$branch_id,
				12
			),
			ARRAY_A
		);

		return rest_ensure_response(
			array(
				'scope'     => array( 'pharmacy' => $scope['trading_name'], 'branch' => $scope['branch_name'], 'currency' => $scope['currency'] ),
				'metrics'   => array( 'active_medicines' => $active, 'units_available' => $units, 'low_stock' => $low, 'expiring_90_days' => $expiring ),
				'movements' => $movements ?: array(),
				'signals'   => array( 'low_stock' => $low, 'expiring_90_days' => $expiring ),
			)
		);
	}

	public static function select_branch( \WP_REST_Request $request ) {
		$branch_id = absint( $request->get_param( 'branch_id' ) );
		$context   = TenantContext::instance();
		if ( ! $branch_id || ! $context->set_branch( $branch_id ) ) {
			return new \WP_Error( 'invalid_branch', 'The selected branch is not authorized.', array( 'status' => 403 ) );
		}
		do_action(
			'pharmasure_audit_log',
			array(
				'tenant_id'  => $context->get_tenant_id(),
				'actor_id'   => get_current_user_id(),
				'action'     => 'workspace.branch_selected',
				'object_type'=> 'branch',
				'object_id'  => $branch_id,
				'status'     => 'success',
			)
		);
		return rest_ensure_response( array( 'success' => true, 'branch_id' => $branch_id ) );
	}

	public static function save_theme( \WP_REST_Request $request ) {
		$theme = sanitize_key( (string) $request->get_param( 'theme' ) );
		if ( ! in_array( $theme, array( 'dark', 'light' ), true ) ) {
			return new \WP_Error( 'invalid_theme', 'Theme must be dark or light.', array( 'status' => 422 ) );
		}
		$user_id = get_current_user_id();
		$current = sanitize_key( (string) get_user_meta( $user_id, 'pharmasure_app_theme', true ) );
		if ( ! $user_id || ( $current !== $theme && false === update_user_meta( $user_id, 'pharmasure_app_theme', $theme ) ) ) {
			return new \WP_Error( 'theme_not_saved', 'The theme preference could not be saved.', array( 'status' => 500 ) );
		}
		do_action(
			'pharmasure_audit_log',
			array(
				'tenant_id'   => TenantContext::instance()->get_tenant_id(),
				'actor_id'    => $user_id,
				'action'      => 'workspace.theme_updated',
				'object_type' => 'user_preference',
				'object_id'   => $user_id,
				'status'      => 'success',
				'details'     => array( 'theme' => $theme ),
			)
		);
		return rest_ensure_response( array( 'success' => true, 'theme' => $theme ) );
	}

	private static function config( TenantContext $context ) {
		$user       = wp_get_current_user();
		$tenant_id  = (int) $context->get_tenant_id();
		$branch_id  = (int) $context->get_branch_id();
		$branches   = self::branches( $context, $tenant_id );
		$route_path = self::app_path();
		global $wpdb;
		$tenant_role = $tenant_id ? (string) $wpdb->get_var( $wpdb->prepare( "SELECT role FROM {$wpdb->prefix}ps_tenant_memberships WHERE tenant_id=%d AND user_id=%d AND is_active=1 LIMIT 1", $tenant_id, $user->ID ) ) : '';
		$theme = sanitize_key( (string) get_user_meta( $user->ID, 'pharmasure_app_theme', true ) );
		if ( ! in_array( $theme, array( 'dark', 'light' ), true ) ) { $theme = 'dark'; }
		return array(
			'apiUrl'      => trailingslashit( rest_url( 'pharmasure/v1' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'appUrl'      => untrailingslashit( network_home_url( '/app' ) ),
			'logoutUrl'   => html_entity_decode( wp_logout_url( network_home_url( '/wp-login.php?logged_out=true' ) ), ENT_QUOTES, 'UTF-8' ),
			'brandMarkUrl' => PHARMASURE_CORE_URL . 'assets/brand/pharmasure-mark.svg',
			'route'       => $route_path,
			'theme'       => $theme,
			'textSize' => in_array(get_user_meta($user->ID,'pharmasure_text_size',true),array('standard','large','extra-large'),true) ? get_user_meta($user->ID,'pharmasure_text_size',true) : 'standard',
			'permissions' => self::workspace_permissions(),
			'platformAdminUrl' => is_multisite() && is_super_admin() && current_user_can( 'manage_network_options' ) ? network_admin_url( 'admin.php?page=pharmasure-admin' ) : null,
			'licence' => ( new LicenseManager( $tenant_id ) )->summary(),
			'inventoryViews' => self::inventory_views(),
			'markupPercent' => get_option('pharmasure_receiving_markup_'.$tenant_id,null),
			'ahfozProviderNumber' => get_option('pharmasure_ahfoz_provider_'.$tenant_id,''),
			'activeBranch'=> $branch_id,
			'branches'    => $branches,
			'currentUser' => array(
				'id'          => (int) $user->ID,
				'displayName' => $user->display_name,
				'role'        => $tenant_role ?: 'staff',
			),
		);
	}

	private static function branches( TenantContext $context, $tenant_id ) {
		if ( ! $tenant_id ) {
			return array();
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,name,code FROM {$wpdb->prefix}ps_branches WHERE tenant_id=%d AND is_active=1 ORDER BY name", $tenant_id ), ARRAY_A );
		return array_values(
			array_filter(
				$rows ?: array(),
				static fn( $row ) => $context->can_access_branch( (int) $row['id'] )
			)
		);
	}

	private static function is_app_request() {
		global $wp_query;
		if ( $wp_query instanceof \WP_Query && '1' === (string) get_query_var( 'pharmasure_app', '' ) ) {
			return true;
		}
		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		return 1 === preg_match( '#^/app(?:/.*)?/?$#', $path );
	}

	private static function app_path() {
		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/app' ), PHP_URL_PATH );
		$path = preg_replace( '#^/app/?#', '', $path );
		return sanitize_key( strtok( (string) $path, '/' ) ?: 'overview' );
	}
}
