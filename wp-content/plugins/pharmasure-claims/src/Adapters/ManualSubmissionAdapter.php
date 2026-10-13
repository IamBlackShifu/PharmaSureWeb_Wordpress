<?php
namespace PharmaSure\Claims\Adapters;

final class ManualSubmissionAdapter implements ClaimSubmissionAdapter {
	public function prepare( array $claim, array $items ): array {
		return array( 'status' => 'ready_for_submission', 'mode' => 'manual', 'claim_number' => $claim['claim_number'], 'line_count' => count( $items ), 'ahfoz_provider_number'=>$claim['ahfoz_provider_number'] ?? '' );
	}
}
