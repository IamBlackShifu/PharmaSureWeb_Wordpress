<?php
/** Standalone PharmaSure application document. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<!doctype html>
<html lang="<?php echo esc_attr( get_user_locale() ); ?>" data-theme="<?php echo esc_attr( $config['theme'] ); ?>">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
	<meta name="theme-color" content="<?php echo esc_attr( 'light' === $config['theme'] ? '#F4F6F7' : '#09090B' ); ?>">
	<meta name="color-scheme" content="dark light">
	<title><?php echo esc_html( get_bloginfo( 'name' ) . ' | PharmaSure' ); ?></title>
	<script nonce="<?php echo esc_attr( $csp_nonce ); ?>">window.PharmaSureConfig=<?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON is hex encoded. ?>;</script>
	<link rel="stylesheet" href="<?php echo esc_url( PHARMASURE_CORE_URL . 'assets/css/crisp-theme.css?ver=' . PHARMASURE_CORE_VERSION ); ?>">
</head>
<body>
	<a class="ps-skip" href="#ps-workspace"><?php esc_html_e( 'Skip to workspace', 'pharmasure-core' ); ?></a>
	<div id="pharmasure-app" class="ps-app" aria-busy="true">
		<div class="ps-boot" role="status"><span aria-hidden="true"></span><?php esc_html_e( 'Opening secure pharmacy workspace...', 'pharmasure-core' ); ?></div>
	</div>
	<noscript><div class="ps-noscript"><?php esc_html_e( 'PharmaSure requires JavaScript for its operational workspace.', 'pharmasure-core' ); ?></div></noscript>
	<script defer src="<?php echo esc_url( PHARMASURE_CORE_URL . 'assets/js/app.js?ver=' . PHARMASURE_CORE_VERSION ); ?>"></script>
</body>
</html>
