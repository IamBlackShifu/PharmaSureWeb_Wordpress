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
		if ( is_wp_error( $data ) ) { $this->fail_job( $tenant_id, $job_id, $data->get_error_message() ); return $data; }
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
			$header['items'] = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->p}prescription_items WHERE prescription_id=%d AND tenant_id=%d AND branch_id=%d ORDER BY id", $entity_id, $tenant_id, $header['branch_id'] ), ARRAY_A );
			return $header;
		}
		if ( 'stock_receipt' === $type ) {
			$header = $this->wpdb->get_row( $this->wpdb->prepare(
				"SELECT r.*,s.name supplier_name,b.name branch_name,b.receipt_header_text,b.receipt_footer_text,t.name tenant_name
				 FROM {$this->p}stock_receipts r JOIN {$this->p}suppliers s ON s.id=r.supplier_id AND s.tenant_id=r.tenant_id
				 JOIN {$this->p}branches b ON b.id=r.branch_id AND b.tenant_id=r.tenant_id JOIN {$this->p}tenants t ON t.id=r.tenant_id
				 WHERE r.id=%d AND r.tenant_id=%d", $entity_id, $tenant_id ), ARRAY_A );
			if ( ! $header ) { return new \WP_Error( 'not_found', 'Stock receipt not found for this tenant' ); }
			$header['items'] = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT l.*,d.name drug_name,d.sku FROM {$this->p}stock_receipt_lines l JOIN {$this->p}drugs d ON d.id=l.drug_id AND d.tenant_id=l.tenant_id WHERE l.receipt_id=%d AND l.tenant_id=%d ORDER BY l.id", $entity_id, $tenant_id ), ARRAY_A );
			return $header;
		}
		$users = $this->wpdb->users;
		$header = $this->wpdb->get_row( $this->wpdb->prepare(
			"SELECT s.*,pa.patient_number,pa.first_name,pa.last_name,b.name branch_name,b.code branch_code,b.address_line_1,b.address_line_2,b.city,b.phone branch_phone,b.email branch_email,b.receipt_header_text,b.receipt_footer_text,t.name tenant_name,t.trading_name,t.currency,u.display_name cashier_name,tl.name till_name,tl.code till_code
			 FROM {$this->p}sales s LEFT JOIN {$this->p}patients pa ON pa.id=s.patient_id AND pa.tenant_id=s.tenant_id
			 JOIN {$this->p}branches b ON b.id=s.branch_id AND b.tenant_id=s.tenant_id JOIN {$this->p}tenants t ON t.id=s.tenant_id
			 LEFT JOIN {$users} u ON u.ID=s.cashier_id LEFT JOIN {$this->p}till_sessions ts ON ts.id=s.till_session_id AND ts.tenant_id=s.tenant_id AND ts.branch_id=s.branch_id
			 LEFT JOIN {$this->p}tills tl ON tl.id=ts.till_id AND tl.tenant_id=ts.tenant_id AND tl.branch_id=ts.branch_id
			 WHERE s.id=%d AND s.tenant_id=%d", $entity_id, $tenant_id ), ARRAY_A );
		if ( ! $header ) { return new \WP_Error( 'not_found', 'Sale not found for this tenant' ); }
		$header['items'] = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT description,quantity,unit_price_minor,line_total_minor FROM {$this->p}sale_items WHERE sale_id=%d AND tenant_id=%d AND branch_id=%d ORDER BY id", $entity_id, $tenant_id, $header['branch_id'] ), ARRAY_A );
		$header['payments'] = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT method,amount_minor,currency,external_reference,status FROM {$this->p}sale_payments WHERE sale_id=%d AND tenant_id=%d AND branch_id=%d ORDER BY id", $entity_id, $tenant_id, $header['branch_id'] ), ARRAY_A );
		return $header;
	}

	private function document_html( $job, $data ) {
		$title = array( 'medication_label' => 'Medication Labels', 'dispensing_summary' => 'Dispensing Summary', 'stock_receipt' => 'Goods Received Note', 'sale_receipt' => 'Receipt' )[ $job['document_type'] ];
		$is_label = 'medication_label' === $job['document_type'];
		$body = $is_label ? $this->labels( $data ) : $this->standard_document( $job['document_type'], $data );
		$reprint = ! empty( $job['is_reprint'] ) ? '<div class="reprint">REPRINT</div>' : '';
		return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title ) . '</title><style>' . $this->css( $job['document_type'] ) . '</style></head><body class="document-' . esc_attr( $job['document_type'] ) . '">' . $reprint . $body . '<script>window.addEventListener("load",()=>window.print());</script></body></html>';
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
		} else { return $this->sale_receipt( $d ); }
		return $out . '<footer>' . nl2br( esc_html( $d['receipt_footer_text'] ?? '' ) ) . '</footer>';
	}

	private function sale_receipt( $d ) {
		$currency = preg_match( '/^[A-Z]{3}$/', (string) ( $d['currency'] ?? '' ) ) ? $d['currency'] : ( $d['payments'][0]['currency'] ?? 'USD' );
		$money = static fn( $minor ) => esc_html( $currency . ' ' . number_format_i18n( (int) $minor / 100, 2 ) );
		$patient = trim( (string) ( $d['first_name'] ?? '' ) . ' ' . (string) ( $d['last_name'] ?? '' ) );
		$location = implode( ', ', array_filter( array( $d['address_line_1'] ?? '', $d['address_line_2'] ?? '', $d['city'] ?? '' ) ) );
		$status = strtoupper( str_replace( '_', ' ', (string) ( $d['status'] ?? 'completed' ) ) );
		$date = (string) ( $d['created_at'] ?? '' );
		if ( $date ) { $date = date_i18n( 'd M Y H:i', strtotime( get_date_from_gmt( $date ) ) ); }
		$out = '<main class="receipt"><header class="receipt__brand"><div class="receipt__mark">P</div><h1>' . esc_html( ( $d['trading_name'] ?? '' ) ?: $d['tenant_name'] ) . '</h1><strong>' . esc_html( $d['branch_name'] ) . '</strong>';
		if ( $location ) { $out .= '<div>' . esc_html( $location ) . '</div>'; }
		if ( ! empty( $d['branch_phone'] ) ) { $out .= '<div>Tel ' . esc_html( $d['branch_phone'] ) . '</div>'; }
		if ( ! empty( $d['branch_email'] ) ) { $out .= '<div>' . esc_html( $d['branch_email'] ) . '</div>'; }
		if ( ! empty( $d['receipt_header_text'] ) ) { $out .= '<div class="receipt__notice">' . nl2br( esc_html( $d['receipt_header_text'] ) ) . '</div>'; }
		$out .= '</header><div class="receipt__rule"></div><section class="receipt__meta" aria-label="Transaction details"><div><span>Receipt</span><b>' . esc_html( ( $d['receipt_number'] ?? '' ) ?: $d['id'] ) . '</b></div><div><span>Date</span><b>' . esc_html( $date ) . '</b></div><div><span>Cashier</span><b>' . esc_html( ( $d['cashier_name'] ?? '' ) ?: 'Pharmacy team' ) . '</b></div><div><span>Till</span><b>' . esc_html( trim( ( $d['till_name'] ?? '' ) . ' ' . ( ! empty( $d['till_code'] ) ? '(' . $d['till_code'] . ')' : '' ) ) ?: 'Clinical dispensing' ) . '</b></div><div><span>Customer</span><b>' . esc_html( $patient ?: 'Walk-in' ) . '</b></div><div><span>Status</span><b>' . esc_html( $status ) . '</b></div></section><div class="receipt__rule"></div>';
		$out .= '<section class="receipt__items" aria-label="Items"><div class="receipt__item receipt__item--head"><span>Item / quantity</span><span>Amount</span></div>';
		foreach ( (array) $d['items'] as $item ) {
			$out .= '<div class="receipt__item"><div><strong>' . esc_html( $item['description'] ) . '</strong><small>' . esc_html( rtrim( rtrim( number_format( (float) $item['quantity'], 3, '.', '' ), '0' ), '.' ) ) . ' × ' . $money( $item['unit_price_minor'] ) . '</small></div><b>' . $money( $item['line_total_minor'] ) . '</b></div>';
		}
		$out .= '</section><div class="receipt__rule"></div><section class="receipt__totals" aria-label="Totals including Discount:"><div><span>Subtotal</span><span>' . $money( $d['subtotal_amount_minor'] ?? $d['total_amount_minor'] ) . '</span></div>';
		if ( ! empty( $d['discount_amount_minor'] ) ) { $out .= '<div><span>Discount</span><span>−' . $money( $d['discount_amount_minor'] ) . '</span></div>'; }
		if ( ! empty( $d['tax_amount_minor'] ) ) { $out .= '<div><span>Tax</span><span>' . $money( $d['tax_amount_minor'] ) . '</span></div>'; }
		$out .= '<div class="receipt__grand"><span>TOTAL</span><b>' . $money( $d['total_amount_minor'] ) . '</b></div></section>';
		if ( ! empty( $d['payments'] ) ) {
			$out .= '<section class="receipt__payments"><h2>Payments</h2>';
			foreach ( $d['payments'] as $payment ) { $reference = $payment['external_reference'] ? ' · ' . $payment['external_reference'] : ''; $out .= '<div><span>' . esc_html( ucwords( str_replace( '_', ' ', $payment['method'] ) ) . $reference ) . '</span><b>' . $money( $payment['amount_minor'] ) . '</b></div>'; }
			$out .= '</section>';
		}
		$out .= '<div class="receipt__rule"></div><footer><strong>Thank you for choosing your community pharmacy.</strong>' . ( ! empty( $d['receipt_footer_text'] ) ? '<div>' . nl2br( esc_html( $d['receipt_footer_text'] ) ) . '</div>' : '' ) . '<small>Retain this receipt for returns and reimbursement enquiries.</small><code>' . esc_html( ( $d['receipt_number'] ?? '' ) ?: $d['id'] ) . '</code></footer></main>';
		return $out;
	}

	private function css( $type ) {
		$label = 'medication_label' === $type;
		$receipt = 'sale_receipt' === $type;
		if ( $receipt ) {
			return '@page{size:80mm auto;margin:3mm}*{box-sizing:border-box}html,body{margin:0;padding:0;background:#fff;color:#09090b}body{width:74mm;font:10px/1.4 Arial,sans-serif}.receipt{width:100%;padding:1mm}.receipt__brand{text-align:center}.receipt__mark{display:inline-grid;place-items:center;width:8mm;height:8mm;margin-bottom:1.5mm;border:1.5px solid #09090b;font:bold 16px Arial}.receipt__brand h1{margin:0;font-size:17px;line-height:1.15;letter-spacing:-.3px}.receipt__brand>strong{display:block;margin:.8mm 0;text-transform:uppercase;letter-spacing:1px;font-size:9px}.receipt__notice{margin-top:2mm;font-weight:bold}.receipt__rule{height:0;margin:2.5mm 0;border-top:1px dashed #52525b}.receipt__meta>div,.receipt__totals>div,.receipt__payments>div{display:flex;justify-content:space-between;gap:3mm;margin:.8mm 0}.receipt__meta span{color:#52525b}.receipt__meta b{text-align:right}.receipt__item{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:3mm;padding:1.8mm 0;border-bottom:1px dotted #a1a1aa}.receipt__item strong,.receipt__item small{display:block}.receipt__item small{color:#52525b;margin-top:.5mm}.receipt__item--head{padding-top:0;text-transform:uppercase;letter-spacing:.7px;font-size:8px;color:#52525b}.receipt__totals{font-size:11px}.receipt__grand{align-items:end;margin-top:2mm!important;padding-top:2mm;border-top:2px solid #09090b;font-size:14px}.receipt__payments{margin-top:3mm}.receipt__payments h2{margin:0 0 1mm;font-size:9px;text-transform:uppercase;letter-spacing:1px}.receipt footer{text-align:center;margin-top:0}.receipt footer strong,.receipt footer small,.receipt footer code{display:block;margin-top:2mm}.receipt footer small{color:#52525b}.receipt footer code{font:9px "Courier New",monospace;letter-spacing:2px}.reprint{position:fixed;right:2mm;top:2mm;padding:1mm;border:1px solid #991b1b;color:#991b1b;font:bold 9px Arial;transform:rotate(4deg)}@media screen{html{background:#e4e4e7}body{margin:16px auto;padding:4mm;min-height:100vh;box-shadow:0 8px 30px rgba(0,0,0,.16)}}';
		}
		return '@page{margin:' . ( $label ? '3mm;size:62mm 40mm' : '12mm' ) . '}body{font:14px Arial,sans-serif;color:#111}.reprint{position:fixed;right:10px;top:5px;color:#b91c1c;font-weight:bold}header{text-align:center}h1{font-size:20px;margin:0}h2{text-align:center}table{width:100%;border-collapse:collapse}th,td{border:1px solid #bbb;padding:6px;text-align:left}footer{text-align:center;margin-top:20px}.total{font-size:20px;font-weight:bold;text-align:right}.label{box-sizing:border-box;width:62mm;min-height:40mm;padding:3mm;page-break-after:always}.label .medicine{font-size:16px;font-weight:bold;margin:3mm 0}.label hr{border:0;border-top:1px solid #555}@media screen{body{max-width:' . ( $label ? '70mm' : '900px' ) . ';margin:20px auto}.label{border:1px dashed #888;margin-bottom:10px}}';
	}

	private function fail_job( $tenant_id, $job_id, $message ) {
		$this->wpdb->update( $this->p . 'print_jobs', array( 'status' => 'failed', 'error_message' => sanitize_text_field( $message ) ), array( 'id' => $job_id, 'tenant_id' => $tenant_id ) );
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
