<?php
/**
 * Title: Partner logo marquee
 * Slug: lps-theme/partner-marquee
 * Categories: lps-layout
 * Block Types: core/group
 * Description: Slow left-to-right band of partner institution marks; the track is duplicated so the loop is seamless. Logos ship in the theme at assets/img/partners/.
 *
 * @package LPS\Theme
 */

$lps_partner_dir = get_stylesheet_directory_uri() . '/assets/img/partners/';
$lps_partners    = array(
	array( 'Eletrobras Cepel', 'eletrobras-cepel.png' ),
	array( 'Inmetro', 'inmetro.png' ),
	array( 'IPqM — Instituto de Pesquisas da Marinha', 'ipqm.png' ),
	array( 'IRD — Instituto de Radioproteção e Dosimetria', 'ird.png' ),
	array( 'CERN', 'cern.png' ),
	array( 'PPGEE — Universidade Federal da Bahia', 'ppgee-ufba.png' ),
	array( 'UFF — Universidade Federal Fluminense', 'uff.png' ),
	array( 'UFJF — Universidade Federal de Juiz de Fora', 'ufjf.png' ),
	array( 'Argonne National Laboratory', 'argonne.png' ),
	array( 'Brookhaven National Laboratory', 'brookhaven.png' ),
	array( 'CBPF — Centro Brasileiro de Pesquisas Físicas', 'cbpf.png' ),
	array( 'CEPARM', 'ceparm.png' ),
	array( 'Embrapa', 'embrapa.png' ),
	array( 'HUCFF — Hospital Universitário Clementino Fraga Filho', 'hucff.png' ),
	array( 'Petrobras', 'petrobras.gif' ),
	array( 'Marinha do Brasil', 'marinha.png' ),
	array( 'RENAFAE', 'renafae.png' ),
	array( 'Rede-TB', 'rede-tb.png' ),
	array( 'CNPq', 'cnpq.png' ),
	array( 'European Union', 'eu.png' ),
	array( 'FAPERJ', 'faperj.gif' ),
);

$lps_partner_items = '';
foreach ( $lps_partners as $lps_partner ) {
	$lps_partner_items .= sprintf(
		'<li><img src="%s" alt="%s" loading="lazy" decoding="async" /></li>',
		esc_url( $lps_partner_dir . $lps_partner[1] ),
		esc_attr( $lps_partner[0] )
	);
}

$lps_partner_track = sprintf(
	'<ul class="lps-partner-track">%s%s</ul>',
	$lps_partner_items,
	preg_replace( '/<li>/', '<li aria-hidden="true">', $lps_partner_items )
);
?>
<!-- wp:group {"className":"lps-partner-marquee","layout":{"type":"default"}} -->
<div class="wp-block-group lps-partner-marquee">
<!-- wp:html -->
<?php echo $lps_partner_track; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- items are escaped per logo above. ?>
<!-- /wp:html -->
</div>
<!-- /wp:group -->
