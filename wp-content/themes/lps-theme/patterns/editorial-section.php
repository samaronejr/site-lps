<?php
/**
 * Title: Editorial section
 * Slug: lps-theme/editorial-section
 * Categories: lps-layout
 * Block Types: core/group
 * Description: Two-part section that preserves label-before-content source order.
 *
 * @package LPS\Theme
 */

?>
<!-- wp:group {"className":"lps-editorial-section lps-page-grid","templateLock":"all","layout":{"type":"default"}} -->
<div class="wp-block-group lps-editorial-section lps-page-grid"><!-- wp:heading {"level":2,"placeholder":"<?php echo esc_attr_x( 'Section title', 'pattern placeholder', 'lps-theme' ); ?>"} /--><!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:paragraph {"placeholder":"<?php echo esc_attr_x( 'Add reviewed content.', 'pattern placeholder', 'lps-theme' ); ?>"} /--></div><!-- /wp:group --></div>
<!-- /wp:group -->
