<?php
/** Admin menu registration regression test. Run with wp eval-file. */
if ( ! defined( 'ABSPATH' ) ) { exit( 1 ); }

wp_set_current_user( 1 );
do_action( 'admin_menu' );

global $submenu, $_registered_pages;
$pass = 0;
$fail = 0;
$assert = static function ( $condition, $message ) use ( &$pass, &$fail ) {
	echo ( $condition ? 'PASS: ' : 'FAIL: ' ) . $message . "\n";
	$condition ? ++$pass : ++$fail;
};

$clinical = array_values(
	array_filter(
		$submenu['pharmasure-core'] ?? array(),
		static fn( $item ) => 'pharmasure-clinical' === ( $item[2] ?? '' )
	)
);
$printing = array_values(
	array_filter(
		$submenu['pharmasure-core'] ?? array(),
		static fn( $item ) => 'pharmasure-print' === ( $item[2] ?? '' )
	)
);

$assert( current_user_can( 'pharmasure_view_inventory' ), 'administrator can access PharmaSure parent menu' );
$assert( current_user_can( 'pharmasure_view_prescriptions' ), 'administrator can view prescriptions' );
$assert( 1 === count( $clinical ), 'Clinical submenu is registered under PharmaSure' );
$clinical_page_keys = array_values( array_filter( array_keys( $_registered_pages ?? array() ), static fn( $key ) => str_contains( $key, 'pharmasure-clinical' ) ) );
foreach ( $clinical_page_keys as $key ) { echo "REGISTERED_PAGE: {$key}\n"; }
$assert( ! empty( $clinical_page_keys ), 'Clinical page callback is registered with WordPress' );
$assert( in_array( 'pharmasure_page_pharmasure-clinical', $clinical_page_keys, true ), 'Clinical page is attached to the PharmaSure parent hook' );
$assert( current_user_can( 'pharmasure_print_documents' ), 'administrator can print documents' );
$assert( 1 === count( $printing ), 'Printing submenu is registered under PharmaSure' );

echo "Admin menu tests: {$pass} passed, {$fail} failed.\n";
if ( $fail ) { throw new RuntimeException( 'Admin menu tests failed.' ); }
