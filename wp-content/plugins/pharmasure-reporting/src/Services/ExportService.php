<?php
namespace PharmaSure\Reporting\Services;

final class ExportService {
	private const MAX_ROWS = 1048576;
	private const MAX_COLUMNS = 16384;

	public function generate( $format, $type, $tenant, $branch, $from, $to ) {
		$reports = new ReportService();
		$report  = $reports->report( $type, $tenant, $branch, $from, $to );
		if ( is_wp_error( $report ) ) {
			return $report;
		}

		$result = match ( sanitize_key( $format ) ) {
			'csv'  => $reports->csv( $type, $tenant, $branch, $from, $to ),
			'xlsx' => $this->xlsx( $type, $report, $from, $to ),
			'pdf'  => $this->pdf( $type, $report, $from, $to ),
			default => new \WP_Error( 'unsupported_export', 'Supported export formats are CSV, XLSX and PDF.', array( 'status' => 422 ) ),
		};

		if ( ! is_wp_error( $result ) && 'csv' !== sanitize_key( $format ) ) {
			do_action(
				'pharmasure_audit_log',
				array(
					'tenant_id'   => (int) $tenant,
					'action'      => 'report.exported',
					'object_type' => 'report',
					'details'     => array(
						'report_type' => sanitize_key( $type ),
						'format'      => sanitize_key( $format ),
						'branch_id'   => (int) $branch,
						'from'        => (string) $from,
						'to'          => (string) $to,
						'row_count'   => count( $report['rows'] ?? array() ),
					),
				)
			);
		}

		return $result;
	}

	public function mime( $format ) {
		return array(
			'csv'  => 'text/csv',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'pdf'  => 'application/pdf',
		)[ $format ] ?? 'application/octet-stream';
	}

	private function xlsx( $type, array $report, $from, $to ) {
		if ( ! class_exists( 'ZipArchive' ) || ! class_exists( 'XMLWriter' ) ) {
			return new \WP_Error( 'xlsx_unavailable', 'The PHP Zip and XMLWriter extensions are required for XLSX exports.', array( 'status' => 503 ) );
		}
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$columns = $report['columns'] ?? array();
		$rows    = $report['rows'] ?? array();
		if ( count( $columns ) > self::MAX_COLUMNS || count( $rows ) + 1 > self::MAX_ROWS ) {
			return new \WP_Error( 'xlsx_limit_exceeded', 'The report exceeds Excel worksheet limits.', array( 'status' => 422 ) );
		}

		$archive_path = wp_tempnam( 'pharmasure-report.xlsx' );
		$sheet_path   = wp_tempnam( 'pharmasure-report-sheet.xml' );
		if ( ! $archive_path || ! $sheet_path ) {
			return new \WP_Error( 'xlsx_failed', 'Temporary export files could not be created.', array( 'status' => 500 ) );
		}

		try {
			$this->write_data_sheet( $sheet_path, $columns, $rows );
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $archive_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				return new \WP_Error( 'xlsx_failed', 'XLSX archive could not be created.', array( 'status' => 500 ) );
			}

			$parts = array(
				'[Content_Types].xml'        => $this->content_types(),
				'_rels/.rels'                => $this->root_relationships(),
				'docProps/app.xml'           => $this->app_properties(),
				'docProps/core.xml'          => $this->core_properties(),
				'xl/workbook.xml'            => $this->workbook(),
				'xl/_rels/workbook.xml.rels' => $this->workbook_relationships(),
				'xl/styles.xml'              => $this->styles(),
				'xl/worksheets/sheet2.xml'   => $this->summary_sheet( $type, $report['summary'] ?? array(), $from, $to ),
			);
			foreach ( $parts as $path => $xml ) {
				if ( ! $zip->addFromString( $path, $xml ) ) {
					$zip->close();
					return new \WP_Error( 'xlsx_failed', 'A required XLSX package part could not be written.', array( 'status' => 500 ) );
				}
			}
			if ( ! $zip->addFile( $sheet_path, 'xl/worksheets/sheet1.xml' ) || ! $zip->close() ) {
				return new \WP_Error( 'xlsx_failed', 'The XLSX workbook could not be finalized.', array( 'status' => 500 ) );
			}

			$validation = $this->validate_archive( $archive_path );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
			$data = file_get_contents( $archive_path );
			return false === $data ? new \WP_Error( 'xlsx_failed', 'The completed workbook could not be read.', array( 'status' => 500 ) ) : $data;
		} finally {
			wp_delete_file( $archive_path );
			wp_delete_file( $sheet_path );
		}
	}

	private function write_data_sheet( $path, array $columns, array $rows ) {
		$writer = new \XMLWriter();
		$writer->openURI( $path );
		$writer->startDocument( '1.0', 'UTF-8' );
		$writer->startElementNS( null, 'worksheet', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
		$last_column = $this->column( max( 1, count( $columns ) ) );
		$last_row    = max( 1, count( $rows ) + 1 );
		$writer->writeAttribute( 'xmlns:r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );
		$writer->writeElement( 'dimension', 'A1:' . $last_column . $last_row );
		$writer->startElement( 'sheetViews' );
		$writer->startElement( 'sheetView' );
		$writer->writeAttribute( 'workbookViewId', '0' );
		$writer->startElement( 'pane' );
		$writer->writeAttribute( 'ySplit', '1' );
		$writer->writeAttribute( 'topLeftCell', 'A2' );
		$writer->writeAttribute( 'activePane', 'bottomLeft' );
		$writer->writeAttribute( 'state', 'frozen' );
		$writer->endElement();
		$writer->endElement();
		$writer->endElement();
		$writer->startElement( 'cols' );
		foreach ( array_values( $columns ) as $index => $label ) {
			$writer->startElement( 'col' );
			$writer->writeAttribute( 'min', (string) ( $index + 1 ) );
			$writer->writeAttribute( 'max', (string) ( $index + 1 ) );
			$writer->writeAttribute( 'width', (string) min( 42, max( 12, strlen( (string) $label ) + 3 ) ) );
			$writer->writeAttribute( 'customWidth', '1' );
			$writer->endElement();
		}
		$writer->endElement();
		$writer->startElement( 'sheetData' );
		$this->write_row( $writer, 1, array_values( $columns ), array_keys( $columns ), true );
		foreach ( array_values( $rows ) as $index => $row ) {
			$values = array();
			foreach ( array_keys( $columns ) as $key ) {
				$values[] = $row[ $key ] ?? '';
			}
			$this->write_row( $writer, $index + 2, $values, array_keys( $columns ), false );
		}
		$writer->endElement();
		$writer->startElement( 'autoFilter' );
		$writer->writeAttribute( 'ref', 'A1:' . $last_column . $last_row );
		$writer->endElement();
		$writer->startElement( 'pageMargins' );
		foreach ( array( 'left' => '0.25', 'right' => '0.25', 'top' => '0.5', 'bottom' => '0.5', 'header' => '0.2', 'footer' => '0.2' ) as $name => $value ) {
			$writer->writeAttribute( $name, $value );
		}
		$writer->endElement();
		$writer->startElement( 'pageSetup' );
		$writer->writeAttribute( 'orientation', 'landscape' );
		$writer->writeAttribute( 'fitToWidth', '1' );
		$writer->writeAttribute( 'fitToHeight', '0' );
		$writer->endElement();
		$writer->endElement();
		$writer->endDocument();
		$writer->flush();
	}

	private function write_row( \XMLWriter $writer, $row_number, array $values, array $keys, $header ) {
		$writer->startElement( 'row' );
		$writer->writeAttribute( 'r', (string) $row_number );
		foreach ( $values as $index => $value ) {
			$key = $keys[ $index ] ?? '';
			$ref = $this->column( $index + 1 ) . $row_number;
			if ( $header ) {
				$label = preg_replace( '/\s*\(minor\)$/i', ' (USD)', (string) $value );
				$this->write_inline_cell( $writer, $ref, $label, 1 );
			} elseif ( $this->is_numeric_column( $key, $value ) ) {
				$number = $this->numeric_value( $key, $value );
				$this->write_number_cell( $writer, $ref, $number, $this->style_for_key( $key ) );
			} else {
				$this->write_inline_cell( $writer, $ref, $value, 0 );
			}
		}
		$writer->endElement();
	}

	private function write_inline_cell( \XMLWriter $writer, $ref, $value, $style ) {
		$writer->startElement( 'c' );
		$writer->writeAttribute( 'r', $ref );
		$writer->writeAttribute( 's', (string) $style );
		$writer->writeAttribute( 't', 'inlineStr' );
		$writer->startElement( 'is' );
		$writer->startElement( 't' );
		$writer->writeAttribute( 'xml:space', 'preserve' );
		$writer->text( $this->safe_text( $value ) );
		$writer->endElement();
		$writer->endElement();
		$writer->endElement();
	}

	private function write_number_cell( \XMLWriter $writer, $ref, $value, $style ) {
		$writer->startElement( 'c' );
		$writer->writeAttribute( 'r', $ref );
		$writer->writeAttribute( 's', (string) $style );
		$writer->writeElement( 'v', $this->number( $value ) );
		$writer->endElement();
	}

	private function is_numeric_column( $key, $value ) {
		if ( '' === $value || null === $value || ! is_numeric( $value ) ) {
			return false;
		}
		return (bool) preg_match( '/(_minor|_bps|_id|_count|_quantity|quantity_|quantity$|age_days|is_resolved$)/', (string) $key );
	}

	private function numeric_value( $key, $value ) {
		if ( str_ends_with( (string) $key, '_minor' ) ) {
			return (float) $value / 100;
		}
		if ( str_ends_with( (string) $key, '_bps' ) ) {
			return (float) $value / 10000;
		}
		return (float) $value;
	}

	private function style_for_key( $key ) {
		if ( str_ends_with( (string) $key, '_minor' ) ) {
			return 4;
		}
		if ( str_ends_with( (string) $key, '_bps' ) ) {
			return 5;
		}
		return preg_match( '/(_id|_count|age_days|is_resolved)$/', (string) $key ) ? 2 : 3;
	}

	private function summary_sheet( $type, array $summary, $from, $to ) {
		$rows = array(
			array( 'Report', ucwords( str_replace( '_', ' ', (string) $type ) ) ),
			array( 'Period', (string) $from . ' to ' . (string) $to ),
			array( 'Generated UTC', gmdate( 'Y-m-d H:i:s' ) ),
			array( '', '' ),
		);
		foreach ( $summary as $key => $value ) {
			$rows[] = array( ucwords( str_replace( '_', ' ', preg_replace( '/_minor$/', ' (USD)', (string) $key ) ) ), $value, $key );
		}

		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
		$xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="1" width="30" customWidth="1"/><col min="2" max="2" width="32" customWidth="1"/></cols><sheetData>';
		foreach ( $rows as $index => $row ) {
			$number = $index + 1;
			$xml .= '<row r="' . $number . '">' . $this->inline_xml( 'A' . $number, $row[0], $index < 3 ? 1 : 6 );
			$key = $row[2] ?? '';
			if ( $key && $this->is_numeric_column( $key, $row[1] ) ) {
				$xml .= '<c r="B' . $number . '" s="' . $this->style_for_key( $key ) . '"><v>' . $this->number( $this->numeric_value( $key, $row[1] ) ) . '</v></c>';
			} else {
				$xml .= $this->inline_xml( 'B' . $number, $row[1], 0 );
			}
			$xml .= '</row>';
		}
		return $xml . '</sheetData></worksheet>';
	}

	private function inline_xml( $ref, $value, $style ) {
		return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . $this->xml( $value ) . '</t></is></c>';
	}

	private function content_types() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>';
	}

	private function root_relationships() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>';
	}

	private function workbook() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets><sheet name="Report data" sheetId="1" r:id="rId1"/><sheet name="Summary" sheetId="2" r:id="rId2"/></sheets><calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>';
	}

	private function workbook_relationships() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
	}

	private function styles() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="3"><numFmt numFmtId="164" formatCode="0.0000"/><numFmt numFmtId="165" formatCode="&quot;$&quot;#,##0.00;[Red]-&quot;$&quot;#,##0.00"/><numFmt numFmtId="166" formatCode="0.00%"/></numFmts><fonts count="2"><font><sz val="10"/><name val="Aptos"/><family val="2"/></font><font><b/><color rgb="FFF8FAFC"/><sz val="10"/><name val="Aptos Display"/><family val="2"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF059669"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFD4D4D8"/></left><right style="thin"><color rgb="FFD4D4D8"/></right><top style="thin"><color rgb="FFD4D4D8"/></top><bottom style="thin"><color rgb="FFD4D4D8"/></bottom><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="7"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf><xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
	}

	private function core_properties() {
		$created = gmdate( 'Y-m-d\TH:i:s\Z' );
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>PharmaSure</dc:creator><cp:lastModifiedBy>PharmaSure</cp:lastModifiedBy><dc:title>PharmaSure report</dc:title><dcterms:created xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:modified></cp:coreProperties>';
	}

	private function app_properties() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>PharmaSure</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop><HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>2</vt:i4></vt:variant></vt:vector></HeadingPairs><TitlesOfParts><vt:vector size="2" baseType="lpstr"><vt:lpstr>Report data</vt:lpstr><vt:lpstr>Summary</vt:lpstr></vt:vector></TitlesOfParts><Company>PharmaSure</Company><AppVersion>1.0</AppVersion></Properties>';
	}

	private function validate_archive( $path ) {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::CHECKCONS ) ) {
			return new \WP_Error( 'xlsx_invalid', 'The generated workbook archive failed integrity validation.', array( 'status' => 500 ) );
		}
		$required = array( '[Content_Types].xml', '_rels/.rels', 'docProps/app.xml', 'docProps/core.xml', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml' );
		foreach ( $required as $part ) {
			$xml = $zip->getFromName( $part );
			if ( false === $xml || '' === $xml || false === simplexml_load_string( $xml ) ) {
				$zip->close();
				return new \WP_Error( 'xlsx_invalid', 'The generated workbook is missing or contains an invalid package part.', array( 'status' => 500, 'part' => $part ) );
			}
		}
		$zip->close();
		return true;
	}

	private function safe_text( $value ) {
		$text = preg_replace( '/[^\P{C}\t\r\n]/u', '', (string) $value );
		return mb_substr( false === $text ? '' : $text, 0, 32767 );
	}

	private function xml( $value ) {
		return htmlspecialchars( $this->safe_text( $value ), ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	private function number( $value ) {
		$number = (float) $value;
		if ( floor( $number ) === $number ) {
			return sprintf( '%.0F', $number );
		}
		return rtrim( rtrim( sprintf( '%.10F', $number ), '0' ), '.' );
	}

	private function pdf( $type, $report, $from, $to ) {
		$lines = array( 'PharmaSure ' . ucwords( str_replace( '_', ' ', $type ) ) . ' Report', "Period: $from to $to", '' );
		$headers = array_values( $report['columns'] );
		$lines[] = implode( ' | ', $headers );
		foreach ( array_slice( $report['rows'], 0, 500 ) as $row ) {
			$lines[] = implode( ' | ', array_map( static fn( $key ) => str_replace( array( "\r", "\n" ), ' ', (string) ( $row[ $key ] ?? '' ) ), array_keys( $report['columns'] ) ) );
		}
		$content = 'BT /F1 8 Tf 36 806 Td 11 TL ';
		foreach ( $lines as $index => $line ) {
			$safe = str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), substr( $line, 0, 180 ) );
			$content .= ( $index ? 'T* ' : '' ) . "($safe) Tj ";
		}
		$content .= 'ET';
		$objects = array( '<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>', '<< /Length ' . strlen( $content ) . ">>\nstream\n$content\nendstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>' );
		$pdf = "%PDF-1.4\n";
		$offset = array( 0 );
		foreach ( $objects as $index => $object ) {
			$offset[] = strlen( $pdf );
			$pdf .= ( $index + 1 ) . " 0 obj\n$object\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= "xref\n0 6\n0000000000 65535 f \n";
		for ( $index = 1; $index <= 5; $index++ ) {
			$pdf .= sprintf( '%010d 00000 n ', $offset[ $index ] ) . "\n";
		}
		return $pdf . "trailer << /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
	}

	private function column( $number ) {
		$result = '';
		while ( $number > 0 ) {
			$number--;
			$result = chr( 65 + $number % 26 ) . $result;
			$number = intdiv( $number, 26 );
		}
		return $result;
	}
}
