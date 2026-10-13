<?php
/**
 * PharmaSure Portal theme setup.
 *
 * @package PharmaSure_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PHARMASURE_PORTAL_VERSION', '1.5.0' );

add_action(
	'after_setup_theme',
	static function () {
		load_theme_textdomain( 'pharmasure-portal', get_template_directory() . '/languages' );
		add_theme_support( 'wp-block-styles' );
		add_theme_support( 'responsive-embeds' );
		add_theme_support( 'editor-styles' );
		add_editor_style( 'style.css' );
	}
);

add_action(
	'wp_enqueue_scripts',
	static function () {
		wp_enqueue_style(
			'pharmasure-portal',
			get_stylesheet_uri(),
			array(),
			PHARMASURE_PORTAL_VERSION
		);
		if ( is_front_page() ) {
			wp_enqueue_style( 'pharmasure-landing', get_template_directory_uri() . '/landing.css', array( 'pharmasure-portal' ), PHARMASURE_PORTAL_VERSION );
		}
	}
);

add_action(
	'init',
	static function () {
		register_block_type( 'pharmasure/landing', array(
			'api_version' => 3,
			'render_callback' => static function () {
				ob_start();
				include get_template_directory() . '/landing.php';
				return ob_get_clean();
			},
		) );
		register_block_pattern_category(
			'pharmasure',
			array( 'label' => __( 'PharmaSure', 'pharmasure-portal' ) )
		);
	}
);

// Keep navigation links correct on both the network and pharmacy sites.
add_filter( 'render_block_data', static function ( $block ) {
	if ( 'core/navigation-link' === ( $block['blockName'] ?? '' ) && 'Sign in' === ( $block['attrs']['label'] ?? '' ) ) {
		$block['attrs']['url'] = wp_login_url( network_home_url( '/app/' ) );
	}
	return $block;
} );

add_filter( 'render_block', static function ( $html, $block ) {
	if ( 'core/html' === ( $block['blockName'] ?? '' ) ) {
		$html = str_replace( '<span class="portal-brand__mark" aria-hidden="true">P</span>', '<img class="portal-brand__mark" src="' . esc_url( PHARMASURE_CORE_URL . 'assets/brand/pharmasure-mark.svg' ) . '" width="44" height="44" alt="">', $html );
	}
	return $html;
}, 10, 2 );
