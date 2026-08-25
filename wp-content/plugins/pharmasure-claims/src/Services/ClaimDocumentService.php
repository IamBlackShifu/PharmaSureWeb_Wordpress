<?php
namespace PharmaSure\Claims\Services;

final class ClaimDocumentService {
	private $db; private $p;
	public function __construct( $db = null ) { global $wpdb; $this->db = $db ?: $wpdb; $this->p = $this->db->prefix . 'ps_'; }
	public function claim_form( $tenant_id, $branch_id, $claim_id ) {
		$claim = $this->db->get_row( $this->db->prepare( "SELECT c.*,i.name insurer_name,s.name scheme_name,p.patient_number,p.first_name,p.last_name,cv.member_number FROM {$this->p}claims c JOIN {$this->p}insurers i ON i.id=c.insurer_id AND i.tenant_id=c.tenant_id JOIN {$this->p}insurer_schemes s ON s.id=c.scheme_id AND s.tenant_id=c.tenant_id JOIN {$this->p}patients p ON p.id=c.patient_id AND p.tenant_id=c.tenant_id JOIN {$this->p}patient_covers cv ON cv.id=c.patient_cover_id AND cv.tenant_id=c.tenant_id WHERE c.id=%d AND c.tenant_id=%d AND c.branch_id=%d", $claim_id, $tenant_id, $branch_id ), ARRAY_A );
		if ( ! $claim ) { return new \WP_Error( 'claim_not_found', 'Claim not found.', array( 'status' => 404 ) ); }
		$items = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->p}claim_items WHERE tenant_id=%d AND claim_id=%d ORDER BY id", $tenant_id, $claim_id ), ARRAY_A );
		$rows = ''; foreach ( $items as $item ) { $rows .= '<tr><td>' . esc_html( $item['description'] ) . '</td><td>' . esc_html( $item['quantity'] ) . '</td><td>' . esc_html( $this->money( $item['claimed_amount_minor'] ) ) . '</td></tr>'; }
		return $this->page( 'Claim ' . $claim['claim_number'], '<p><strong>Insurer:</strong> ' . esc_html( $claim['insurer_name'] ) . ' / ' . esc_html( $claim['scheme_name'] ) . '</p><p><strong>Patient:</strong> ' . esc_html( $claim['first_name'] . ' ' . $claim['last_name'] ) . ' (' . esc_html( $claim['patient_number'] ) . ')</p><p><strong>Member:</strong> ' . esc_html( $claim['member_number'] ) . ' &nbsp; <strong>Service date:</strong> ' . esc_html( $claim['service_date'] ) . '</p><table><thead><tr><th>Medicine</th><th>Quantity</th><th>Claimed</th></tr></thead><tbody>' . $rows . '</tbody></table><p class="total">Total claimed: ' . esc_html( $this->money( $claim['claimed_amount_minor'] ) ) . '</p><p>Status: ' . esc_html( $claim['status'] ) . '</p>' );
	}
	public function remittance( $tenant_id, $remittance_id ) {
		$remit = $this->db->get_row( $this->db->prepare( "SELECT r.*,i.name insurer_name FROM {$this->p}remittances r JOIN {$this->p}insurers i ON i.id=r.insurer_id AND i.tenant_id=r.tenant_id WHERE r.id=%d AND r.tenant_id=%d", $remittance_id, $tenant_id ), ARRAY_A );
		if ( ! $remit ) { return new \WP_Error( 'remittance_not_found', 'Remittance not found.', array( 'status' => 404 ) ); }
		$items = $this->db->get_results( $this->db->prepare( "SELECT ri.*,c.claim_number FROM {$this->p}remittance_items ri JOIN {$this->p}claims c ON c.id=ri.claim_id AND c.tenant_id=ri.tenant_id WHERE ri.tenant_id=%d AND ri.remittance_id=%d ORDER BY ri.id", $tenant_id, $remittance_id ), ARRAY_A );
		$rows = ''; foreach ( $items as $item ) { $rows .= '<tr><td>' . esc_html( $item['claim_number'] ) . '</td><td>' . esc_html( $item['payer_claim_reference'] ) . '</td><td>' . esc_html( $this->money( $item['paid_amount_minor'] ) ) . '</td><td>' . esc_html( $this->money( $item['adjustment_amount_minor'] ) ) . '</td></tr>'; }
		return $this->page( 'Remittance ' . $remit['reference'], '<p><strong>Insurer:</strong> ' . esc_html( $remit['insurer_name'] ) . '</p><p><strong>Received:</strong> ' . esc_html( $remit['received_date'] ) . '</p><table><thead><tr><th>Claim</th><th>Payer reference</th><th>Paid</th><th>Adjustment</th></tr></thead><tbody>' . $rows . '</tbody></table><p class="total">Total paid: ' . esc_html( $this->money( $remit['total_paid_minor'] ) ) . '</p>' );
	}
	private function money( $minor ) { return number_format( (int) $minor / 100, 2, '.', ',' ); }
	private function page( $title, $body ) { return '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html( $title ) . '</title><style>body{font:14px Arial,sans-serif;margin:32px;color:#18212f}table{width:100%;border-collapse:collapse;margin:20px 0}th,td{padding:8px;border-bottom:1px solid #ccd4dd;text-align:left}.total{font-size:16px;font-weight:bold}@media print{body{margin:10mm}}</style></head><body><h1>' . esc_html( $title ) . '</h1>' . $body . '</body></html>'; }
}
