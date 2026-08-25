<?php
namespace PharmaSure\PrintModule\Services;

final class PrintService {
	private $wpdb;
	private $p;
	private const TYPES = array( 'medication_label', 'dispensing_summary', 'stock_receipt', 'sale_receipt' );

	public function __construct() {
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->p = $wpdb->prefix . 'ps_';
	}

	public function create_job( $tenant_id, $document_type, $entity_id, $user_id, $correlation_id = '', $branch_id = 0 ) {
		$document_type = sanitize_key( $document_type );
		if ( ! $tenant_id || ! $entity_id || ! in_array( $document_type, self::TYPES, true ) ) {
			return new \WP_Error( 'invalid_print_job', 'A supported document type and entity are required' );
		}
		$data = $this->load_document( $tenant_id, $document_type, $entity_id );
		if ( is_wp_error( $data ) ) { return $data; }
		if ( $branch_id && (int) ( $data['branch_id'] ?? 0 ) !== (int) $branch_id ) {
			return new \WP_Error( 'forbidden_branch', 'Document does not belong to the authorized branch.', array( 'status' => 403 ) );
		}
		$prior = (int) $this->wpdb->get_var( $this->wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->p}print_jobs WHERE tenant_id=%d AND document_type=%s AND entity_id=%d AND status='completed'",
			$tenant_id, $document_type, $entity_id
		) );
		$inserted = $this->wpdb->insert( $this->p . 'print_jobs', array(
			'tenant_id' => $tenant_id, 'branch_id' => $data['branch_id'] ?? null, 'document_type' => $document_type,
			'entity_id' => $entity_id, 'adapter' => 'browser', 'status' => 'queued', 'requested_by' => $user_id,
			'correlation_id' => sanitize_text_field( $correlation_id ), 'is_reprint' => $prior > 0 ? 1 : 0,
			'created_at' => current_time( 'mysql', true ),
		) );
		if ( ! $inserted ) { return new \WP_Error( 'db_error', 'Unable to create print job' ); }
		return array( 'id' => (int) $this->wpdb->insert_id, 'is_reprint' => $prior > 0 );
	}

	public function get_job( $tenant_id, $job_id, $branch_id = 0 ) {
		if ( $branch_id ) {
			return $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->p}print_jobs WHERE id=%d AND tenant_id=%d AND branch_id=%d", $job_id, $tenant_id, $branch_id ), ARRAY_A );
		}
		return $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->p}print_jobs WHERE id=%d AND tenant_id=%d", $job_id, $tenant_id ), ARRAY_A );
	}

	public function list_jobs( $tenant_id, $limit = 50, $branch_id = 0 ) {
		if ( $branch_id ) {
			return $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->p}print_jobs WHERE tenant_id=%d AND branch_id=%d ORDER BY id DESC LIMIT %d", $tenant_id, $branch_id, min( 100, max( 1, $limit ) ) ), ARRAY_A );
		}
		return $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->p}print_jobs WHERE tenant_id=%d ORDER BY id DESC LIMIT %d", $tenant_id, min( 100, max( 1, $limit ) ) ), ARRAY_A );
	}

	public function render_job( $tenant_id, $job_id ) {
		$job = $this->get_job( $tenant_id, $job_id );
		if ( ! $job ) { return new \WP_Error( 'not_found', 'Print job not found for this tenant' ); }
		$data = $this->load_document( $tenant_id, $job['document_type'], (int) $job['entity_id'] );
		if ( is_wp_error( $data ) ) { $this->fail_job( $job_id, $data->get_error_message() ); return $data; }
		$html = $this->document_html( $job, $data );
		$this->wpdb->update( $this->p . 'print_jobs', array( 'status' => 'completed', 'attempts' => (int) $job['attempts'] + 1, 'completed_at' => current_time( 'mysql', true ), 'error_message' => null ), array( 'id' => $job_id, 'tenant_id' => $tenant_id ) );
		$this->log_print( $job );
		return $html;
	}

	private function load_document( $tenant_id, $type, $entity_id ) {
		if ( in_array( $type, array( 'medication_label', 'dispensing_summary' ), true ) ) {
			$header = $this->wpdb->get_row( $this->wpdb->prepare(
				"SELECT pr.*, pa.patient_number,pa.first_name,pa.last_name,b.name branch_name,b.receipt_header_text,b.receipt_footer_text,t.name tenant_name
				 FROM {$this->p}prescriptions pr JOIN {$this->p}patients pa ON pa.id=pr.patient_id AND pa.tenant_id=pr.tenant_id
				 JOIN {$this->p}branches b ON b.id=pr.branch_id AND b.tenant_id=pr.tenant_id JOIN {$this->p}tenants t ON t.id=pr.tenant_id
				 WHERE pr.id=%d AND pr.tenant_id=%d", $entity_id, $tenant_id ), ARRAY_A );
			if ( ! $header ) { return new \WP_Error( 'not_found', 'Prescription not found for this tenant' ); }
			$header['items'] = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->p}prescription_items WHERE prescription_id=%d ORDER BY id", $entity_id ), ARRAY_A );
			return $header;
		}
		if ( 'stock_receipt' === $type ) {
			$header = $this->wpdb->get_row( $this->wpdb->prepare(
				"SELECT r.*,s.name supplier_name,b.name branch_name,b.receipt_header_text,b.receipt_footer_text,t.name tenant_name
				 FROM {$this->p}stock_receipts r JOIN {$this->p}suppliers s ON s.id=r.supplier_id AND s.tenant_id=r.tenant_id
				 JOIN {$this->p}branches b ON b.id=r.branch_id AND b.tenant_id=r.tenant_id JOIN {$this->p}tenants t ON t.id=r.tenant_id
				 WHERE r.id=%d AND r.tenant_id=%d", $entity_id, $tenant_id ), ARRAY_A );
			if ( ! $header ) { return new \WP_Error( 'not_found', 'Stock receipt not found for this tenant' ); }
			$header['items'] = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT l.*,d.name drug_name,d.sku FROM {$this->p}stock_receipt_lines l JOIN {$this->p}drugs d ON d.id=l.drug_id WHERE l.receipt_id=%d ORDER BY l.id", $entity_id ), ARRAY_A );
			return $header;
		}
		$header = $this->wpdb->get_row( $this->wpdb->prepare(
			"SELECT s.*,pa.patient_number,pa.first_name,pa.last_name,b.name branch_name,b.receipt_header_text,b.receipt_footer_text,t.name tenant_name
			 FROM {$this->p}sales s LEFT JOIN {$this->p}patients pa ON pa.id=s.patient_id AND pa.tenant_id=s.tenant_id
			 JOIN {$this->p}branches b ON b.id=s.branch_id AND b.tenant_id=s.tenant_id JOIN {$this->p}tenants t ON t.id=s.tenant_id
			 WHERE s.id=%d AND s.tenant_id=%d", $entity_id, $tenant_id ), ARRAY_A );
		if ( ! $header ) { return new \WP_Error( 'not_found', 'Sale not found for this tenant' ); }
		$header['items'] = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT description,quantity,unit_price_minor,line_total_minor FROM {$this->p}sale_items WHERE sale_id=%d AND tenant_id=%d ORDER BY id", $entity_id, $tenant_id ), ARRAY_A );
		$header['payments'] = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT method,amount_minor,currency,external_reference,status FROM {$this->p}sale_payments WHERE sale_id=%d AND tenant_id=%d ORDER BY id", $entity_id, $tenant_id ), ARRAY_A );
		return $header;
	}

	private function document_html( $job, $data ) {
		$title = array( 'medication_label' => 'Medication Labels', 'dispensing_summary' => 'Dispensing Summary', 'stock_receipt' => 'Goods Received Note', 'sale_receipt' => 'Receipt' )[ $job['document_type'] ];
		$is_label = 'medication_label' === $job['document_type'];
		$body = $is_label ? $this->labels( $data ) : $this->standard_document( $job['document_type'], $data );
		$reprint = ! empty( $job['is_reprint'] ) ? '<div class="reprint">REPRINT</div>' : '';
		return '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html( $title ) . '</title><style>' . $this->css( $is_label ) . '</style></head><body>' . $reprint . $body . '<script>window.addEventListener("load",()=>window.print());</script></body></html>';
	}

	private function labels( $d ) {
		$out = '';
		foreach ( $d['items'] as $item ) {
			$out .= '<section class="label"><strong>' . esc_html( $d['tenant_name'] ) . '</strong><div>' . esc_html( $d['branch_name'] ) . '</div><hr><b>' . esc_html( trim( $d['first_name'] . ' ' . $d['last_name'] ) ) . '</b><div class="medicine">' . esc_html( $item['drug_name'] ) . ' ' . esc_html( $item['strength'] ) . '</div><div>' . esc_html( $item['dose'] . ' · ' . $item['frequency'] ) . '</div><div>' . esc_html( $item['route'] . ( $item['duration'] ? ' · ' . $item['duration'] : '' ) ) . '</div><small>Rx #' . esc_html( $d['id'] ) . ' · ' . esc_html( $d['prescription_date'] ) . '</small></section>';
		}
		return $out ?: '<p>No prescription items.</p>';
	}

	private function standard_document( $type, $d ) {
		$title = 'dispensing_summary' === $type ? 'Dispensing Summary' : ( 'stock_receipt' === $type ? 'Goods Received Note' : 'Receipt' );
		$out = '<header><h1>' . esc_html( $d['tenant_name'] ) . '</h1><div>' . esc_html( $d['branch_name'] ) . '</div><div>' . nl2br( esc_html( $d['receipt_header_text'] ?? '' ) ) . '</div></header><h2>' . esc_html( $title ) . '</h2>';
		if ( 'dispensing_summary' === $type ) {
			$out .= '<p><b>Patient:</b> ' . esc_html( trim( $d['first_name'] . ' ' . $d['last_name'] ) ) . ' (' . esc_html( $d['patient_number'] ) . ')<br><b>Prescriber:</b> ' . esc_html( $d['prescriber'] ) . '<br><b>Date:</b> ' . esc_html( $d['prescription_date'] ) . '</p><table><thead><tr><th>Medicine</th><th>Directions</th><th>Qty</th></tr></thead><tbody>';
			foreach ( $d['items'] as $i ) { $out .= '<tr><td>' . esc_html( $i['drug_name'] . ' ' . $i['strength'] ) . '</td><td>' . esc_html( $i['dose'] . ', ' . $i['frequency'] ) . '</td><td>' . esc_html( $i['quantity'] ) . '</td></tr>'; }
			$out .= '</tbody></table>';
		} elseif ( 'stock_receipt' === $type ) {
			$out .= '<p><b>GRN:</b> ' . esc_html( $d['id'] ) . '<br><b>Supplier:</b> ' . esc_html( $d['supplier_name'] ) . '<br><b>Reference:</b> ' . esc_html( $d['purchase_reference'] ) . '<br><b>Received:</b> ' . esc_html( $d['received_date'] ) . '</p><table><thead><tr><th>SKU / Medicine</th><th>Batch</th><th>Expiry</th><th>Qty</th><th>Unit cost</th></tr></thead><tbody>';
			foreach ( $d['items'] as $i ) { $out .= '<tr><td>' . esc_html( $i['sku'] . ' / ' . $i['drug_name'] ) . '</td><td>' . esc_html( $i['batch_number'] ) . '</td><td>' . esc_html( $i['expiry_date'] ) . '</td><td>' . esc_html( $i['quantity'] ) . '</td><td>' . esc_html( number_format_i18n( $i['unit_cost_minor'] / 100, 2 ) ) . '</td></tr>'; }
			$out .= '</tbody></table>';
		} else {
			$out .= '<p><b>Receipt:</b> ' . esc_html( ( $d['receipt_number'] ?? '' ) ?: $d['id'] ) . '<br><b>Date:</b> ' . esc_html( $d['created_at'] ) . '<br><b>Patient:</b> ' . esc_html( trim( ( $d['first_name'] ?? '' ) . ' ' . ( $d['last_name'] ?? '' ) ) ?: 'Walk-in' ) . '</p>';
			if ( ! empty( $d['items'] ) ) {
				$out .= '<table><thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead><tbody>';
				foreach ( $d['items'] as $i ) { $out .= '<tr><td>' . esc_html( $i['description'] ) . '</td><td>' . esc_html( $i['quantity'] ) . '</td><td>' . esc_html( number_format_i18n( $i['unit_price_minor'] / 100, 2 ) ) . '</td><td>' . esc_html( number_format_i18n( $i['line_total_minor'] / 100, 2 ) ) . '</td></tr>'; }
				$out .= '</tbody></table>';
			}
			$out .= '<p class="total">Subtotal: ' . esc_html( number_format_i18n( ( $d['subtotal_amount_minor'] ?? $d['total_amount_minor'] ) / 100, 2 ) ) . '<br>Discount: ' . esc_html( number_format_i18n( ( $d['discount_amount_minor'] ?? 0 ) / 100, 2 ) ) . '<br>Tax: ' . esc_html( number_format_i18n( ( $d['tax_amount_minor'] ?? 0 ) / 100, 2 ) ) . '<br>Total: ' . esc_html( number_format_i18n( $d['total_amount_minor'] / 100, 2 ) ) . '</p>';
			if ( ! empty( $d['payments'] ) ) { $out .= '<h3>Payments</h3><ul>'; foreach ( $d['payments'] as $payment ) { $out .= '<li>' . esc_html( ucwords( str_replace( '_', ' ', $payment['method'] ) ) . ': ' . $payment['currency'] . ' ' . number_format_i18n( $payment['amount_minor'] / 100, 2 ) . ( $payment['external_reference'] ? ' (' . $payment['external_reference'] . ')' : '' ) ) . '</li>'; } $out .= '</ul>'; }
		}
		return $out . '<footer>' . nl2br( esc_html( $d['receipt_footer_text'] ?? '' ) ) . '</footer>';
	}

	private function css( $label ) {
		return '@page{margin:' . ( $label ? '3mm;size:62mm 40mm' : '12mm' ) . '}body{font:14px Arial,sans-serif;color:#111}.reprint{position:fixed;right:10px;top:5px;color:#b91c1c;font-weight:bold}header{text-align:center}h1{font-size:20px;margin:0}h2{text-align:center}table{width:100%;border-collapse:collapse}th,td{border:1px solid #bbb;padding:6px;text-align:left}footer{text-align:center;margin-top:20px}.total{font-size:20px;font-weight:bold;text-align:right}.label{box-sizing:border-box;width:62mm;min-height:40mm;padding:3mm;page-break-after:always}.label .medicine{font-size:16px;font-weight:bold;margin:3mm 0}.label hr{border:0;border-top:1px solid #555}@media screen{body{max-width:' . ( $label ? '70mm' : '900px' ) . ';margin:20px auto}.label{border:1px dashed #888;margin-bottom:10px}}';
	}

	private function fail_job( $job_id, $message ) {
		$this->wpdb->update( $this->p . 'print_jobs', array( 'status' => 'failed', 'error_message' => sanitize_text_field( $message ) ), array( 'id' => $job_id ) );
	}

	private function log_print( $job ) {
		do_action( 'pharmasure_audit_log', array(
			'correlation_id' => $job['correlation_id'], 'tenant_id' => $job['tenant_id'], 'actor_id' => $job['requested_by'],
			'event_type' => ! empty( $job['is_reprint'] ) ? 'document_reprinted' : 'document_printed', 'entity_type' => $job['document_type'],
			'entity_id' => $job['entity_id'], 'action' => ! empty( $job['is_reprint'] ) ? 'reprint' : 'print', 'status' => 'success',
			'details' => array( 'print_job_id' => $job['id'], 'adapter' => $job['adapter'] ),
		) );
	}
}
