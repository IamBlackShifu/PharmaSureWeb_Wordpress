<?php
namespace PharmaSure\Clinical\Sig;

/** Deterministic structural validation; this is not clinical decision support. */
final class Engine {
	public const FREQUENCIES = [
		'daily_1' => ['once daily', 1], 'daily_2' => ['twice daily', 2],
		'daily_3' => ['three times daily', 3], 'daily_4' => ['four times daily', 4],
		'hours_4' => ['every 4 hours', 6], 'hours_6' => ['every 6 hours', 4],
		'hours_8' => ['every 8 hours', 3], 'hours_12' => ['every 12 hours', 2],
		'bedtime' => ['at bedtime', 1], 'weekly' => ['once weekly', null],
		'alternate' => ['every other day', null], 'single' => ['once immediately', 1],
		'prn' => ['as needed', null], 'custom' => ['according to the prescribed schedule', null],
	];
	public const ROUTES = [
		'oral' => ['Take', 'by mouth'], 'topical' => ['Apply', 'to'],
		'ophthalmic' => ['Instil', 'into'], 'otic' => ['Instil', 'into'],
		'nasal' => ['Administer', 'into'], 'inhalation' => ['Inhale', ''],
		'rectal' => ['Insert', 'rectally'], 'vaginal' => ['Insert', 'vaginally'],
		'sublingual' => ['Place', 'under the tongue'], 'buccal' => ['Place', 'inside the cheek'],
		'subcutaneous' => ['Inject', 'subcutaneously'], 'intramuscular' => ['Inject', 'intramuscularly'],
		'intravenous' => ['Administer', 'intravenously'], 'transdermal' => ['Apply', 'to'],
	];
	public const TIMING = ['before meals', 'after meals', 'with meals', 'in the morning', 'at bedtime', 'with food', 'on an empty stomach'];

	/** Medication profiles describe form compatibility only; they never supply a regimen. */
	public static function profile( string $form ): array {
		$form = strtolower( trim( $form ) );
		$profiles = [
			'tablet' => [['tablet'], ['oral']],
			'sublingual tablet' => [['tablet'], ['sublingual']], 'buccal tablet' => [['tablet'], ['buccal']],
			'capsule' => [['capsule'], ['oral']], 'suspension' => [['mL'], ['oral']],
			'oral suspension' => [['mL'], ['oral']], 'syrup' => [['mL'], ['oral']],
			'oral solution' => [['mL'], ['oral']], 'eye drops' => [['drop'], ['ophthalmic']],
			'ear drops' => [['drop'], ['otic']], 'cream' => [['application'], ['topical']],
			'ointment' => [['application'], ['topical']], 'inhaler' => [['puff'], ['inhalation']],
			'patch' => [['patch'], ['transdermal']], 'nasal spray' => [['spray'], ['nasal']],
			'suppository' => [['suppository'], ['rectal']], 'sachet' => [['sachet'], ['oral']],
			'injection' => [['mL', 'unit'], ['subcutaneous', 'intramuscular', 'intravenous']],
		];
		if ( substr( $form, -1 ) === 's' && isset( $profiles[substr( $form, 0, -1 )] ) ) { $form = substr( $form, 0, -1 ); }
		return isset( $profiles[$form] ) ? ['units' => $profiles[$form][0], 'routes' => $profiles[$form][1]] : ['units' => [], 'routes' => []];
	}

	/** Exact rational input. Limit values before arithmetic to avoid overflow. */
	public static function number( $value ): array {
		if ( ! is_scalar( $value ) ) { throw new \InvalidArgumentException( 'Enter a numeric dose.' ); }
		$v = strtr( trim( (string) $value ), ['½' => ' 1/2', '¼' => ' 1/4', '¾' => ' 3/4'] );
		$v = trim( $v );
		if ( preg_match( '/^(?:(\d{1,5})\s+)?(\d{1,5})\/(\d{1,5})$/D', $v, $m ) ) {
			$d = (int) $m[3]; if ( ! $d ) { throw new \InvalidArgumentException( 'Dose denominator must be positive.' ); }
			$n = (int) ($m[1] ?: 0) * $d + (int) $m[2];
		} elseif ( preg_match( '/^\d{1,5}(?:\.\d{1,6})?$/D', $v ) ) {
			$parts = explode( '.', $v ); $d = 10 ** strlen( $parts[1] ?? '' ); $n = (int) $parts[0] * $d + (int) ($parts[1] ?? 0);
		} else { throw new \InvalidArgumentException( 'Use a positive decimal or fraction for the dose.' ); }
		if ( $n <= 0 || $n > 10000 * $d ) { throw new \InvalidArgumentException( 'Dose is outside the supported numeric range; review manually.' ); }
		$a = $n; $b = $d; while ( $b ) { $r = $a % $b; $a = $b; $b = $r; }
		return ['numerator' => intdiv( $n, $a ), 'denominator' => intdiv( $d, $a )];
	}

	public function parse( string $expression, array $context, array $definitions ): array {
		if ( strlen( $expression ) > 500 ) { throw new \InvalidArgumentException( 'SIG expression is too long.' ); }
		$text = strtoupper( trim( preg_replace( '/\s+/u', ' ', $expression ) ) );
		$s = ['dose' => '', 'unit' => $context['unit'] ?? '', 'route' => $context['route'] ?? '', 'site' => $context['site'] ?? '', 'duration_days' => '', 'frequency' => '', 'timing' => '', 'additional' => '', 'definition_versions' => []];
		if ( preg_match( '/^((?:\d+\s+)?\d+\/\d+|\d+(?:\.\d+)?|\d*[½¼¾])\s*(ML|TABLETS?|CAPSULES?|DROPS?|PUFFS?)?(?=\s|$)/u', $text, $m ) ) {
			$s['dose'] = $m[1];
			if ( ! empty( $m[2] ) ) { $s['unit'] = $m[2] === 'ML' ? 'mL' : strtolower( rtrim( $m[2], 'S' ) ); }
			$text = trim( substr( $text, strlen( $m[0] ) ) );
		} else { throw new \InvalidArgumentException( 'SIG must begin with a numeric dose.' ); }
		if ( preg_match( '/(?:^| )X\s*(\d{1,4})\s*\/\s*7$/D', $text, $m ) ) { $s['duration_days'] = $m[1]; $text = trim( substr( $text, 0, -strlen( $m[0] ) ) ); }
		foreach ( preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
			$matches = [];
			foreach ( $definitions as $definition ) {
				if ( ($definition['status'] ?? '') !== 'active' || ($definition['approval_status'] ?? '') !== 'approved' || ($definition['language'] ?? 'en') !== 'en' ) { continue; }
				$codes = array_map( 'strtoupper', array_merge( [$definition['code']], $definition['aliases'] ?? [] ) );
				if ( ! in_array( $token, $codes, true ) ) { continue; }
				if ( ! empty( $definition['routes'] ) && ! in_array( $s['route'], $definition['routes'], true ) ) { continue; }
				if ( ! empty( $definition['forms'] ) && ! in_array( strtolower( $context['dosage_form'] ?? '' ), $definition['forms'], true ) ) { continue; }
				$matches[] = $definition;
			}
			if ( count( $matches ) !== 1 || ! empty( $matches[0]['ambiguous'] ) ) { throw new \InvalidArgumentException( "Code {$token} is unknown, unapproved or ambiguous in this context. Replace it with a structured instruction." ); }
			$def = $matches[0]; $category = $def['category'];
			if ( ! in_array( $category, ['frequency', 'route', 'timing', 'additional'], true ) ) { throw new \InvalidArgumentException( 'Unsupported instruction category.' ); }
			if ( $s[$category] !== '' && $s[$category] !== $def['value'] ) { throw new \InvalidArgumentException( "Conflicting {$category} instructions." ); }
			$s[$category] = $def['value']; $s['definition_versions'][] = ['id' => (int) $def['id'], 'version' => (int) $def['version'], 'code' => $def['code']];
		}
		return $s;
	}

	public function evaluate( array $input, array $profile ): array {
		$dose = self::number( $input['dose'] ?? '' );
		$unit = (string) ($input['unit'] ?? ''); $route = (string) ($input['route'] ?? ''); $frequency = (string) ($input['frequency'] ?? '');
		if ( ! in_array( $unit, $profile['units'], true ) || ! in_array( $route, $profile['routes'], true ) ) { throw new \InvalidArgumentException( 'Selected dose unit or administration route is incompatible with the configured medication form. Review the product profile.' ); }
		if ( ! isset( self::FREQUENCIES[$frequency] ) ) { throw new \InvalidArgumentException( 'Select a supported frequency.' ); }
		$days = (string) ($input['duration_days'] ?? '');
		if ( $days !== '' && ( ! ctype_digit( $days ) || (int) $days < 1 || (int) $days > 3660 ) ) { throw new \InvalidArgumentException( 'Duration must be 1 to 3660 whole days.' ); }
		$site = trim( (string) ($input['site'] ?? '') );
		if ( $site !== '' && ! in_array( $route, ['ophthalmic', 'otic', 'nasal', 'topical', 'transdermal'], true ) ) { throw new \InvalidArgumentException( 'Administration site is not supported for this route; include prescribed technique in additional instructions.' ); }
		if ( in_array( $route, ['ophthalmic', 'otic', 'nasal', 'topical', 'transdermal'], true ) && $site === '' ) { throw new \InvalidArgumentException( 'Enter the prescribed administration site, eye, ear or nostril.' ); }
		$timing = (string) ($input['timing'] ?? '');
		if ( $timing !== '' && ! in_array( $timing, self::TIMING, true ) ) { throw new \InvalidArgumentException( 'Select a supported timing instruction.' ); }
		if ( in_array( $timing, ['before meals', 'on an empty stomach'], true ) && ! empty( $input['with_food'] ) ) { throw new \InvalidArgumentException( 'Conflicting meal instructions.' ); }
		if ( $frequency === 'bedtime' && $timing === 'in the morning' ) { throw new \InvalidArgumentException( 'Bedtime and morning instructions conflict.' ); }
		$additional = trim( (string) ($input['additional'] ?? '') );
		if ( strlen( $additional ) > 1000 || strlen( $site ) > 200 ) { throw new \InvalidArgumentException( 'Instruction text is too long.' ); }
		if ( $frequency === 'custom' && $additional === '' ) { throw new \InvalidArgumentException( 'Enter the complete prescribed custom schedule.' ); }
		$display = $dose['denominator'] === 1 ? (string) $dose['numerator'] : $dose['numerator'] . '/' . $dose['denominator'];
		$plural = $dose['numerator'] !== $dose['denominator'] && $unit !== 'mL' ? 's' : '';
		$directions = self::ROUTES[$route][0] . ' ' . $display . ' ' . $unit . $plural;
		$phrase = trim( self::ROUTES[$route][1] . ' ' . $site ); if ( $phrase !== '' ) { $directions .= ' ' . $phrase; }
		$directions .= ' ' . self::FREQUENCIES[$frequency][0];
		$prn_max = (string) ($input['max_administrations_per_day'] ?? '');
		if ( $prn_max !== '' ) {
			if ( $frequency !== 'prn' || ! ctype_digit( $prn_max ) || (int) $prn_max < 1 || (int) $prn_max > 24 ) { throw new \InvalidArgumentException( 'PRN maximum must be an explicitly prescribed whole number from 1 to 24 and requires an as-needed frequency.' ); }
			$directions .= ', up to ' . (int) $prn_max . ((int) $prn_max === 1 ? ' time daily' : ' times daily');
		}
		if ( $timing && ! ($frequency === 'bedtime' && $timing === 'at bedtime') ) { $directions .= ' ' . $timing; }
		if ( $days !== '' && $frequency !== 'single' ) { $directions .= ' for ' . (int) $days . ((int) $days === 1 ? ' day' : ' days'); }
		$directions .= '.'; if ( $additional ) { $directions .= ' ' . $additional; }
		$quantity = null; $maximum = null; $manual = 'Manual quantity verification required.';
		$count = $frequency === 'single' ? 1 : ( $days !== '' && self::FREQUENCIES[$frequency][1] !== null ? (int) $days * self::FREQUENCIES[$frequency][1] : null );
		// Weekly/alternate schedules require explicit start and administration dates.
		if ( $count !== null && in_array( $unit, ['tablet', 'capsule', 'mL', 'sachet', 'suppository'], true ) ) {
			$n = $dose['numerator'] * $count; $d = $dose['denominator'];
			if ( ($n * 1000) % $d === 0 ) { $quantity = number_format( ($n * 1000 / $d) / 1000, 3, '.', '' ); $manual = null; }
		}
		if ( $frequency === 'prn' && $days !== '' && isset( $input['max_administrations_per_day'] ) && $input['max_administrations_per_day'] !== '' ) {
			$max = (string) $input['max_administrations_per_day'];
			if ( ! ctype_digit( $max ) || (int) $max < 1 || (int) $max > 24 ) { throw new \InvalidArgumentException( 'PRN maximum must be an explicitly prescribed whole number from 1 to 24.' ); }
			$n = $dose['numerator'] * (int) $max * (int) $days;
			if ( ($n * 1000) % $dose['denominator'] === 0 ) { $maximum = number_format( $n / $dose['denominator'], 3, '.', '' ); }
		}
		return ['schema_version' => 1, 'structured' => ['dose' => $display, 'dose_exact' => $dose, 'unit' => $unit, 'route' => $route, 'frequency' => $frequency, 'duration_days' => $days === '' ? null : (int) $days, 'site' => $site, 'timing' => $timing, 'additional' => $additional, 'max_administrations_per_day' => $input['max_administrations_per_day'] ?? null, 'definition_versions' => $input['definition_versions'] ?? []], 'directions' => $directions, 'quantity' => $quantity, 'quantity_unit' => $unit, 'maximum_theoretical_quantity' => $maximum, 'manual_reason' => $manual, 'validation_scope' => 'Structural validation only; pharmacist clinical review is required.'];
	}
}
