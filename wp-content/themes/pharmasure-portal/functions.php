<?php
/**
 * PharmaSure Portal theme setup.
 *
 * @package PharmaSure_Portal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PHARMASURE_PORTAL_VERSION', '1.1.0' );

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
	}
);

add_action(
	'init',
	static function () {
		register_block_pattern_category(
			'pharmasure',
			array( 'label' => __( 'PharmaSure', 'pharmasure-portal' ) )
		);
	}
);
