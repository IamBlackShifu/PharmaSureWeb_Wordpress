<?php
/** Public pharmacy platform introduction. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$sign_in = wp_login_url( network_home_url( '/app/' ) );
$home = network_home_url( '/' );
$brand_mark = PHARMASURE_CORE_URL . 'assets/brand/pharmasure-mark.svg';
$features = array(
	array( 'Rx', 'Clinical dispensing', 'Keep patient records, prescription review and verified directions together, from intake to the printed label.' ),
	array( '01', 'Inventory that stays visible', 'Track medicine batches, expiry dates and stock movement across branches, with first-expiry-first-out selection.' ),
	array( '02', 'A connected point of sale', 'Keep the counter moving with barcode sales, held carts, split payments and separate cashier till sessions.' ),
	array( '03', 'Accountability across branches', 'Give each team the access they need and follow stock, cash reconciliation and recorded changes by branch.' ),
);
?>
<div class="pharma-landing">
<a class="pl-skip" href="#wp--skip-link--target">Skip to content</a>
<header class="pl-header"><div class="pl-wrap pl-nav">
<a class="pl-brand" href="<?php echo esc_url( $home ); ?>" aria-label="PharmaSure home"><img class="pl-brand-mark" src="<?php echo esc_url( $brand_mark ); ?>" width="52" height="52" alt=""><span>Pharma<span class="pl-brand-accent">Sure</span><small>PHARMACY OPERATIONS</small></span></a>
<nav aria-label="Main navigation"><a href="#platform">Platform</a><a href="#workflow">How it works</a><a class="pl-button pl-button-small" href="<?php echo esc_url( $sign_in ); ?>">Sign in <span aria-hidden="true">&rarr;</span></a></nav>
</div></header>
<main id="wp--skip-link--target">
<section class="pl-hero"><div class="pl-wrap pl-hero-grid">
<div><p class="pl-eyebrow"><span></span> FOR THE PEOPLE BEHIND EVERY PRESCRIPTION</p>
<h1>The confidence<br>behind every<br><em>prescription.</em></h1>
<p class="pl-lead">Exceptional care starts with a pharmacy in control. Connect your medicines, your team and your daily operations in one considered workspace.</p>
<div class="pl-actions"><a class="pl-button" href="<?php echo esc_url( $sign_in ); ?>">Sign in to your pharmacy <span aria-hidden="true">&rarr;</span></a><a class="pl-text-link" href="#platform">Explore the platform <span aria-hidden="true">&darr;</span></a></div>
<div class="pl-hero-foot"><span>Clinical workflows</span><span>Branch visibility</span><span>Cashier accountability</span></div>
</div>
<div class="pl-scene" aria-label="Illustration of connected pharmacy operations">
<div class="pl-scene-orbit" aria-hidden="true"></div>
<img class="pl-medicine-art" src="<?php echo esc_url( get_template_directory_uri() . '/assets/pharmacy-care.svg' ); ?>" alt="" width="580" height="600" fetchpriority="high">
<div class="pl-floating pl-floating-stock"><span class="pl-floating-icon" aria-hidden="true">&#9638;</span><div><small>INVENTORY CONTROL</small><strong>Every batch. Every branch.</strong><p>Stock and expiry, in view.</p></div></div>
<div class="pl-floating pl-floating-care"><span class="pl-floating-icon" aria-hidden="true">Rx</span><div><small>CLINICAL DISPENSING</small><strong>Clarity at every handoff.</strong><p>Reviewed directions. Traceable care.</p></div></div>
<span class="pl-scene-caption">MEDICINE &middot; PEOPLE &middot; PRECISION</span>
</div>
</div></section>
<div class="pl-strip"><div class="pl-wrap"><p>One workspace. A connected pharmacy team.</p><span>Dispensing</span><span>Inventory</span><span>Point of sale</span><span>Branch management</span></div></div>
<section class="pl-section pl-wrap" id="platform"><div class="pl-section-heading"><div><p class="pl-eyebrow">PURPOSE-BUILT FOR PHARMACY</p><h2>Complex operations.<br><em>Beautifully connected.</em></h2></div><p>One shared picture of your pharmacy, from the medicines on your shelves to the decisions at your counter.</p></div>
<div class="pl-features"><?php foreach ( $features as $feature ) : ?><article><span class="pl-feature-icon" aria-hidden="true"><?php echo esc_html( $feature[0] ); ?></span><h3><?php echo esc_html( $feature[1] ); ?></h3><p><?php echo esc_html( $feature[2] ); ?></p></article><?php endforeach; ?></div></section>
<section class="pl-workflow" id="workflow"><div class="pl-wrap"><div class="pl-section-heading"><div><p class="pl-eyebrow">FROM THE FIRST DELIVERY TO THE LAST SALE</p><h2>A better rhythm<br>for your pharmacy.</h2></div><p>Follow medicines from delivery to dispensing, with a record of the people and actions along the way.</p></div><ol><li><span>01</span><h3>Receive</h3><p>Record suppliers, batches, quantities and expiry dates.</p></li><li><span>02</span><h3>Review</h3><p>Check prescriptions, patient records and available stock.</p></li><li><span>03</span><h3>Dispense &amp; sell</h3><p>Verify directions, print labels and complete the sale.</p></li><li><span>04</span><h3>Reconcile</h3><p>Count the till and review branch activity with accountability.</p></li></ol></div></section>
<section class="pl-wrap pl-callout"><div><p class="pl-eyebrow">YOUR TEAM. YOUR WORKSPACE.</p><h2>Ready for the day ahead.</h2><p>Sign in with the account provided by your pharmacy administrator.</p></div><a class="pl-button" href="<?php echo esc_url( $sign_in ); ?>">Open your workspace <span aria-hidden="true">&rarr;</span></a></section>
</main>
<footer class="pl-footer"><div class="pl-wrap"><a class="pl-brand" href="<?php echo esc_url( $home ); ?>"><img class="pl-brand-mark" src="<?php echo esc_url( $brand_mark ); ?>" width="52" height="52" alt=""><span>PharmaSure<small>BETTER PHARMACY OPERATIONS</small></span></a><p>Designed and engineered by Infinity Lines of Code Pvt Ltd.</p><a href="<?php echo esc_url( $sign_in ); ?>">Sign in &rarr;</a></div></footer>
</div>
