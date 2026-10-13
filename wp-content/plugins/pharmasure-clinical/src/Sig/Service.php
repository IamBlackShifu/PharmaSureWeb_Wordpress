<?php
namespace PharmaSure\Clinical\Sig;

final class Service {
	private $db;
	private string $p;
	public function __construct() { global $wpdb; $this->db = $wpdb; $this->p = $wpdb->prefix . 'ps_'; }

	public function definitions( int $tenant ): array {
		$rows = $this->db->get_results( $this->db->prepare( "SELECT d.* FROM {$this->p}sig_definitions d WHERE d.tenant_id=%d AND NOT EXISTS (SELECT 1 FROM {$this->p}sig_definitions n WHERE n.tenant_id=d.tenant_id AND n.code=d.code AND n.version>d.version) ORDER BY d.code", $tenant ), ARRAY_A ) ?: [];
		return array_map( static function( $r ) { $data = json_decode( $r['payload'], true ) ?: []; unset( $r['payload'] ); return array_merge( $data, $r ); }, $rows );
	}

	public function settings( int $tenant ): array {
		return array_merge( ['enabled' => false, 'personal_shortcuts' => false], (array) get_option( 'pharmasure_sig_settings_' . $tenant, [] ) );
	}

	public function product( int $tenant, int $drug ): array {
		$r = $this->db->get_row( $this->db->prepare( "SELECT id,dosage_form,unit_of_measure,pack_size FROM {$this->p}drugs WHERE id=%d AND tenant_id=%d AND status='active'", $drug, $tenant ), ARRAY_A );
		if ( ! $r ) { throw new \InvalidArgumentException( 'Select an active medicine in this pharmacy.' ); }
		$r['profile'] = apply_filters( 'pharmasure_sig_product_profile', Engine::profile( (string) $r['dosage_form'] ), $r, $tenant );
		return $r;
	}

	public function preview( int $tenant, int $drug, array $data ): array {
		if ( ! $this->settings( $tenant )['enabled'] ) { throw new \InvalidArgumentException( 'Smart SIG is disabled until pharmacy clinical acceptance is recorded in SIG Management.' ); }
		$product = $this->product( $tenant, $drug ); $engine = new Engine();
		$input = (array) ($data['structured'] ?? []);
		if ( ! empty( $data['parse_sig'] ) ) { $input = $engine->parse( (string) ($data['original_sig'] ?? ''), array_merge( $input, ['dosage_form' => $product['dosage_form']] ), $this->definitions( $tenant ) ); }
		foreach ( (array) ($input['definition_versions'] ?? []) as $ref ) {
			$valid = false; foreach ( $this->definitions( $tenant ) as $def ) { if ( (int) $def['id'] === (int) ($ref['id'] ?? 0) && (int) $def['version'] === (int) ($ref['version'] ?? 0) && $def['status'] === 'active' && $def['approval_status'] === 'approved' && (empty( $def['routes'] ) || in_array( $input['route'] ?? '', $def['routes'], true )) && (empty( $def['forms'] ) || in_array( strtolower( $product['dosage_form'] ), $def['forms'], true )) ) { $valid = true; break; } }
			if ( ! $valid ) { throw new \InvalidArgumentException( 'A referenced SIG definition has changed or is no longer approved. Rebuild the directions.' ); }
		}
		$result = $engine->evaluate( $input, $product['profile'] );
		$result['structured']['dosage_form'] = $product['dosage_form'];
		$result['dosage_form'] = $product['dosage_form']; $result['stock_unit'] = $product['unit_of_measure'];
		// No inferred pack conversions: dispensing quantity is in the catalogue stock unit.
		$stock = strtolower( trim( (string) $product['unit_of_measure'] ) );
		$unit = strtolower( $result['quantity_unit'] );
		if ( ! in_array( $stock, [$unit, $unit . 's'], true ) ) { $result['manual_reason'] = 'Manual quantity verification required: prescribed units differ from catalogue stock units. Enter stock quantity and document the validated conversion.'; }
		return $result;
	}

	public function save_instruction( int $tenant, int $branch, int $item, array $data, array $result, int $actor ): void {
		$directions = sanitize_textarea_field( $data['verified_directions'] ?? $result['directions'] );
		$reason = sanitize_textarea_field( $data['reconciliation_reason'] ?? '' );
		if ( ! $directions || strlen( $directions ) > 2000 ) { throw new \InvalidArgumentException( 'Patient directions are required and must be under 2000 characters.' ); }
		if ( $directions !== $result['directions'] && ! $reason ) { throw new \InvalidArgumentException( 'Edited directions require a documented reconciliation with the structured dosage.' ); }
		$quantity_reason = sanitize_textarea_field( $data['quantity_override_reason'] ?? '' );
		if ( ! empty( $result['manual_reason'] ) && ! $quantity_reason ) { throw new \InvalidArgumentException( 'Manual quantity verification requires a reason or validated stock conversion.' ); }
		$original = sanitize_textarea_field( $data['original_prescription'] ?? '' ); if ( ! $original ) { throw new \InvalidArgumentException( 'Enter the original prescription directions for comparison.' ); }
		if ( false === $this->db->insert( $this->p . 'sig_instructions', ['tenant_id' => $tenant, 'branch_id' => $branch, 'prescription_item_id' => $item, 'original_sig' => sanitize_text_field( $data['original_sig'] ?? '' ), 'original_prescription' => $original, 'structured' => wp_json_encode( $result['structured'] ), 'generated_directions' => $result['directions'], 'verified_directions' => $directions, 'calculation' => wp_json_encode( $result ), 'reconciliation_reason' => $reason ?: null, 'quantity_override_reason' => $quantity_reason ?: null, 'created_by' => $actor, 'created_at' => current_time( 'mysql', true )] ) ) { throw new \RuntimeException( 'Unable to save structured directions.' ); }
	}

	public function for_item( int $tenant, int $branch, int $item ) {
		$r = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->p}sig_instructions WHERE tenant_id=%d AND branch_id=%d AND prescription_item_id=%d", $tenant, $branch, $item ), ARRAY_A );
		if ( ! empty( $this->db->last_error ) ) { throw new \RuntimeException( 'SIG instruction storage is unavailable.' ); }
		if ( $r ) { $r['structured'] = json_decode( $r['structured'], true ); $r['calculation'] = json_decode( $r['calculation'], true ); }
		return $r;
	}

	public function verify( int $tenant, int $branch, int $rx, int $actor ): void {
		if ( ! current_user_can( 'pharmasure_review_prescriptions' ) || $actor !== get_current_user_id() ) { throw new \InvalidArgumentException( 'An authenticated authorized pharmacist must verify SIG directions.' ); }
		$rows = $this->db->get_results( $this->db->prepare( "SELECT s.*,i.drug_id FROM {$this->p}sig_instructions s JOIN {$this->p}prescription_items i ON i.id=s.prescription_item_id AND i.tenant_id=s.tenant_id AND i.branch_id=s.branch_id WHERE s.tenant_id=%d AND s.branch_id=%d AND i.prescription_id=%d", $tenant, $branch, $rx ), ARRAY_A ) ?: [];
		foreach ( $rows as $r ) {
			$this->preview( $tenant, (int) $r['drug_id'], ['structured' => json_decode( $r['structured'], true ) ?: []] );
			if ( ! $r['verified_directions'] || ($r['generated_directions'] !== $r['verified_directions'] && ! $r['reconciliation_reason']) ) { throw new \InvalidArgumentException( 'SIG directions have an unresolved discrepancy.' ); }
			if ( false === $this->db->update( $this->p . 'sig_instructions', ['verified_by' => $actor, 'verified_at' => current_time( 'mysql', true )], ['id' => $r['id'], 'tenant_id' => $tenant, 'branch_id' => $branch] ) ) { throw new \RuntimeException( 'SIG verification could not be recorded.' ); }
		}
	}

	public function save_definition( int $tenant, array $data, int $actor ): array {
		$code = strtoupper( sanitize_text_field( $data['code'] ?? '' ) ); $category = sanitize_key( $data['category'] ?? '' ); $value = sanitize_text_field( $data['value'] ?? '' );
		if ( ! preg_match( '/^[A-Z][A-Z0-9]{0,29}$/D', $code ) || ! in_array( $category, ['frequency', 'route', 'timing', 'additional'], true ) || ! $value ) { throw new \InvalidArgumentException( 'Enter a code, category and canonical value.' ); }
		if ( ($category === 'frequency' && ! isset( Engine::FREQUENCIES[$value] )) || ($category === 'route' && ! isset( Engine::ROUTES[$value] )) || ($category === 'timing' && ! in_array( $value, Engine::TIMING, true )) ) { throw new \InvalidArgumentException( 'Canonical value is not supported by the engine.' ); }
		$aliases = array_values( array_unique( array_map( static function( $v ) { return strtoupper( sanitize_text_field( $v ) ); }, (array) ($data['aliases'] ?? []) ) ) );
		foreach ( $aliases as $alias ) { if ( ! preg_match( '/^[A-Z][A-Z0-9]{0,29}$/D', $alias ) ) { throw new \InvalidArgumentException( 'Aliases must be single alphanumeric codes.' ); } }
		$status = sanitize_key( $data['status'] ?? 'draft' ); if ( ! in_array( $status, ['draft', 'pending_approval', 'active', 'deprecated', 'disabled'], true ) ) { throw new \InvalidArgumentException( 'Invalid code lifecycle state.' ); }
		$routes = array_values( array_map( 'sanitize_key', (array) ($data['routes'] ?? []) ) ); foreach ( $routes as $route ) { if ( ! isset( Engine::ROUTES[$route] ) ) { throw new \InvalidArgumentException( 'Unknown route restriction.' ); } }
		$forms = array_values( array_map( static function( $v ) { return strtolower( sanitize_text_field( $v ) ); }, (array) ($data['forms'] ?? []) ) );
		$ambiguous = ! empty( $data['ambiguous'] ) || in_array( $code, ['OD', 'QD', 'QOD', 'U', 'IU'], true );
		if ( $status === 'active' ) {
			if ( ! current_user_can( 'pharmasure_review_prescriptions' ) || empty( $data['clinical_approval'] ) || ! trim( (string) ($data['approval_note'] ?? '') ) ) { throw new \InvalidArgumentException( 'Activation requires pharmacist review permission, explicit clinical approval and a rationale.' ); }
			foreach ( $this->definitions( $tenant ) as $existing ) { if ( $existing['code'] === $code || $existing['status'] !== 'active' ) { continue; } if ( array_intersect( array_merge( [$code], $aliases ), array_merge( [$existing['code']], $existing['aliases'] ?? [] ) ) ) { throw new \InvalidArgumentException( 'A code or alias overlaps an active definition.' ); } }
		}
		$version = 1 + (int) $this->db->get_var( $this->db->prepare( "SELECT MAX(version) FROM {$this->p}sig_definitions WHERE tenant_id=%d AND code=%s", $tenant, $code ) );
		$payload = ['value' => $value, 'description' => sanitize_text_field( $data['description'] ?? $value ), 'aliases' => $aliases, 'routes' => $routes, 'forms' => $forms, 'ambiguous' => $ambiguous, 'priority' => max( 0, min( 100, (int) ($data['priority'] ?? 0) ) ), 'approval_note' => sanitize_textarea_field( $data['approval_note'] ?? '' )];
		if ( false === $this->db->insert( $this->p . 'sig_definitions', ['tenant_id' => $tenant, 'code' => $code, 'category' => $category, 'payload' => wp_json_encode( $payload ), 'language' => 'en', 'status' => $status, 'approval_status' => $status === 'active' ? 'approved' : 'pending', 'version' => $version, 'created_by' => $actor, 'created_at' => current_time( 'mysql', true ), 'approved_by' => $status === 'active' ? $actor : null, 'approved_at' => $status === 'active' ? current_time( 'mysql', true ) : null] ) ) { throw new \RuntimeException( 'Definition changed concurrently; reload and retry.' ); }
		$id = (int) $this->db->insert_id;
		do_action( 'pharmasure_audit_log', ['tenant_id' => $tenant, 'actor_id' => $actor, 'action' => 'clinical.sig_definition_changed', 'object_type' => 'sig_definition', 'object_id' => $id, 'details' => ['code' => $code, 'version' => $version, 'status' => $status]] );
		return ['id' => $id, 'version' => $version];
	}

	public function seed( int $tenant, int $actor ): void {
		$existing = array_column( $this->definitions( $tenant ), 'code' );
		foreach ( ['OD' => ['daily_1', []], 'BD' => ['daily_2', ['BID']], 'TDS' => ['daily_3', ['TID']], 'QID' => ['daily_4', []], 'Q6H' => ['hours_6', []], 'Q8H' => ['hours_8', []], 'HS' => ['bedtime', []], 'STAT' => ['single', []], 'PRN' => ['prn', []]] as $code => $entry ) { if ( ! in_array( $code, $existing, true ) ) { $this->save_definition( $tenant, ['code' => $code, 'category' => 'frequency', 'value' => $entry[0], 'description' => Engine::FREQUENCIES[$entry[0]][0], 'aliases' => $entry[1], 'status' => 'draft'], $actor ); } }
		foreach ( ['PO' => 'oral', 'IM' => 'intramuscular', 'IV' => 'intravenous', 'SC' => 'subcutaneous', 'SL' => 'sublingual'] as $code => $value ) { if ( ! in_array( $code, $existing, true ) ) { $this->save_definition( $tenant, ['code' => $code, 'category' => 'route', 'value' => $value], $actor ); } }
		foreach ( ['AC' => 'before meals', 'PC' => 'after meals'] as $code => $value ) { if ( ! in_array( $code, $existing, true ) ) { $this->save_definition( $tenant, ['code' => $code, 'category' => 'timing', 'value' => $value], $actor ); } }
	}
}
