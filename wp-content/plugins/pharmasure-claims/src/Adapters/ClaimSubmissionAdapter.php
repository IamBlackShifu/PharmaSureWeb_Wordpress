<?php
namespace PharmaSure\Claims\Adapters;

interface ClaimSubmissionAdapter {
	/** Return a normalized submission result without mutating the claim. */
	public function prepare( array $claim, array $items ): array;
}
