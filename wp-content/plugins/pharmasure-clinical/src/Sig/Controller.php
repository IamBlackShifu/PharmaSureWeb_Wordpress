<?php
namespace PharmaSure\Clinical\Sig;

use PharmaSure\Clinical\Rest\ClinicalAccess;

final class Controller {
	public static function register_routes(): void {
		$self = new self();
		foreach ( ['library' => ['GET', 'read'], 'profile' => ['GET', 'build'], 'preview' => ['POST', 'build'], 'definitions' => ['POST', 'manage'], 'settings' => ['POST', 'manage'], 'seed' => ['POST', 'manage'], 'templates' => ['POST', 'build'], 'template_status' => ['POST', 'build'], 'preferences' => ['POST', 'build'], 'history' => ['GET', 'manage']] as $path => $config ) {
			register_rest_route( 'pharmasure/v1', '/sig/' . $path, ['methods' => $config[0], 'callback' => [$self, $path], 'permission_callback' => [$self, $config[1]]] );
		}
	}
	public function read() { return ClinicalAccess::authorize( 'pharmasure_view_prescriptions' ); }
	public function build() { return ClinicalAccess::authorize( 'pharmasure_manage_prescriptions', true ); }
	public function manage() { return ClinicalAccess::authorize( 'pharmasure_manage_sig', true ); }
	private function run( $request, callable $callback ) {
		$scope = ClinicalAccess::scope( $request ); if ( is_wp_error( $scope ) ) { return $scope; }
		try { return rest_ensure_response( $callback( $scope['tenant_id'], (array) $request->get_json_params(), new Service() ) ); }
		catch ( \InvalidArgumentException $e ) { return new \WP_Error( 'sig_validation', $e->getMessage(), ['status' => 422] ); }
		catch ( \Throwable $e ) { return new \WP_Error( 'sig_save_failed', 'SIG data could not be saved. Reload and retry.', ['status' => 409] ); }
	}
	public function library( $r ) { return $this->run( $r, function( $tenant, $data, $service ) {
		global $wpdb; $p = $wpdb->prefix . 'ps_'; $actor = get_current_user_id(); $admin = current_user_can( 'pharmasure_manage_sig' );
		$definitions = $service->definitions( $tenant );
		if ( ! $admin ) { $definitions = array_values( array_filter( $definitions, static function( $d ) { return $d['status'] === 'active' && $d['approval_status'] === 'approved'; } ) ); }
		$templates = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}sig_templates WHERE tenant_id=%d AND (owner_id=0 OR owner_id=%d) AND status='active' ORDER BY name", $tenant, $actor ), ARRAY_A ) ?: [];
		foreach ( $templates as &$t ) { $t['structured'] = json_decode( $t['structured'], true ); } unset( $t );
		if ( ! $service->settings( $tenant )['personal_shortcuts'] && ! $admin ) { $templates = array_values( array_filter( $templates, static function( $t ) { return (int) $t['owner_id'] === 0; } ) ); }
		return ['settings' => $service->settings( $tenant ), 'definitions' => $definitions, 'templates' => $templates, 'frequencies' => Engine::FREQUENCIES, 'routes' => Engine::ROUTES, 'timing' => Engine::TIMING, 'preferences' => get_user_meta( $actor, 'pharmasure_sig_' . $tenant, true ) ?: ['favourites' => [], 'recent' => [], 'counts' => []], 'permissions' => ['manage' => $admin, 'approve' => current_user_can( 'pharmasure_review_prescriptions' )]];
	} ); }
	public function preview( $r ) { return $this->run( $r, static function( $tenant, $d, $s ) { return $s->preview( $tenant, absint( $d['drug_id'] ?? 0 ), $d ); } ); }
	public function profile( $r ) { return $this->run( $r, static function( $tenant, $d, $s ) use ( $r ) { return $s->product( $tenant, absint( $r->get_param( 'drug_id' ) ) ); } ); }
	public function definitions( $r ) { return $this->run( $r, static function( $tenant, $d, $s ) { return $s->save_definition( $tenant, $d, get_current_user_id() ); } ); }
	public function seed( $r ) { return $this->run( $r, static function( $tenant, $d, $s ) { $s->seed( $tenant, get_current_user_id() ); return ['success' => true]; } ); }
	public function settings( $r ) { return $this->run( $r, static function( $tenant, $d, $s ) {
		$enabled = ! empty( $d['enabled'] ); $note = sanitize_textarea_field( $d['acceptance_note'] ?? '' );
		if ( $enabled && (! current_user_can( 'pharmasure_review_prescriptions' ) || empty( $d['clinical_acceptance'] ) || ! $note) ) { throw new \InvalidArgumentException( 'Enabling SIG requires pharmacist review permission, clinical acceptance confirmation and a recorded acceptance reference.' ); }
		$settings = ['enabled' => $enabled, 'personal_shortcuts' => ! empty( $d['personal_shortcuts'] ), 'acceptance_note' => $note, 'updated_by' => get_current_user_id(), 'updated_at' => current_time( 'mysql', true )];
		update_option( 'pharmasure_sig_settings_' . $tenant, $settings, false );
		do_action( 'pharmasure_audit_log', ['tenant_id' => $tenant, 'actor_id' => get_current_user_id(), 'action' => 'clinical.sig_settings_changed', 'object_type' => 'sig_settings', 'object_id' => $tenant, 'details' => $settings] ); return $settings;
	} ); }
	public function templates( $r ) { return $this->run( $r, static function( $tenant, $d, $s ) {
		global $wpdb; $actor = get_current_user_id(); $shared = ! empty( $d['shared'] );
		if ( $shared && (! current_user_can( 'pharmasure_manage_sig' ) || ! current_user_can( 'pharmasure_review_prescriptions' ) || empty( $d['clinical_approval'] )) ) { throw new \InvalidArgumentException( 'Shared templates require configuration permission and pharmacist approval.' ); }
		if ( ! $shared && ! $s->settings( $tenant )['personal_shortcuts'] ) { throw new \InvalidArgumentException( 'Personal shortcuts are disabled by this pharmacy.' ); }
		$result = $s->preview( $tenant, absint( $d['drug_id'] ?? 0 ), $d ); $name = sanitize_text_field( $d['name'] ?? '' );
		if ( ! $name || strlen( $name ) > 100 ) { throw new \InvalidArgumentException( 'Enter a template name under 100 characters.' ); }
		$structured = $result['structured']; $structured['dosage_form'] = $result['dosage_form'];
		if ( false === $wpdb->insert( $wpdb->prefix . 'ps_sig_templates', ['tenant_id' => $tenant, 'owner_id' => $shared ? 0 : $actor, 'name' => $name, 'structured' => wp_json_encode( $structured ), 'status' => 'active', 'created_by' => $actor, 'created_at' => current_time( 'mysql', true )] ) ) { throw new \RuntimeException(); }
		$id = (int) $wpdb->insert_id; do_action( 'pharmasure_audit_log', ['tenant_id' => $tenant, 'actor_id' => $actor, 'action' => 'clinical.sig_template_created', 'object_type' => 'sig_template', 'object_id' => $id, 'details' => ['shared' => $shared, 'name' => $name]] ); return ['id' => $id];
	} ); }
	public function preferences( $r ) { return $this->run( $r, static function( $tenant, $d, $s ) {
		$actor = get_current_user_id(); $key = 'pharmasure_sig_' . $tenant; $prefs = get_user_meta( $actor, $key, true ) ?: ['favourites' => [], 'recent' => [], 'counts' => []];
		$id = absint( $d['definition_id'] ?? 0 ); $valid = false; foreach ( $s->definitions( $tenant ) as $def ) { if ( (int) $def['id'] === $id && $def['status'] === 'active' && $def['approval_status'] === 'approved' ) { $valid = true; } }
		if ( ! $valid ) { throw new \InvalidArgumentException( 'Select an active approved definition.' ); }
		if ( array_key_exists( 'favourite', $d ) ) { $prefs['favourites'] = array_values( array_diff( $prefs['favourites'], [$id] ) ); if ( $d['favourite'] ) { $prefs['favourites'][] = $id; } }
		else { $prefs['recent'] = array_slice( array_values( array_unique( array_merge( [$id], $prefs['recent'] ) ) ), 0, 20 ); $prefs['counts'][$id] = min( 1000000, (int) ($prefs['counts'][$id] ?? 0) + 1 ); }
		$prefs['favourites'] = array_slice( $prefs['favourites'], 0, 100 ); update_user_meta( $actor, $key, $prefs ); return $prefs;
	} ); }
	public function template_status( $r ) { return $this->run( $r, static function( $tenant, $d, $s ) {
		global $wpdb; $table = $wpdb->prefix . 'ps_sig_templates'; $id = absint( $d['id'] ?? 0 ); $actor = get_current_user_id();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT owner_id FROM {$table} WHERE id=%d AND tenant_id=%d", $id, $tenant ), ARRAY_A );
		if ( ! $row || ((int) $row['owner_id'] !== $actor && ! current_user_can( 'pharmasure_manage_sig' )) ) { throw new \InvalidArgumentException( 'Template is unavailable or you lack permission to disable it.' ); }
		if ( false === $wpdb->update( $table, ['status' => 'disabled'], ['id' => $id, 'tenant_id' => $tenant] ) ) { throw new \RuntimeException(); }
		do_action( 'pharmasure_audit_log', ['tenant_id' => $tenant, 'actor_id' => $actor, 'action' => 'clinical.sig_template_disabled', 'object_type' => 'sig_template', 'object_id' => $id] ); return ['success' => true];
	} ); }
	public function history( $r ) { return $this->run( $r, static function( $tenant ) {
		global $wpdb; $p = $wpdb->prefix . 'ps_';
		return ['definitions' => $wpdb->get_results( $wpdb->prepare( "SELECT id,code,version,status,approval_status,created_by,approved_by,created_at,approved_at,payload FROM {$p}sig_definitions WHERE tenant_id=%d ORDER BY id DESC LIMIT 200", $tenant ), ARRAY_A ), 'usage' => $wpdb->get_results( $wpdb->prepare( "SELECT COUNT(*) total,SUM(verified_at IS NOT NULL) verified,SUM(quantity_override_reason IS NOT NULL) manual_quantities FROM {$p}sig_instructions WHERE tenant_id=%d", $tenant ), ARRAY_A )];
	} ); }
}
