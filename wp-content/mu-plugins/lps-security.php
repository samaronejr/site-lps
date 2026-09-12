<?php
/**
 * Plugin Name: LPS Security Policy
 * Description: Mandatory early-loading production security and privacy controls.
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/plugins/lps-content-model/includes/class-hardening.php';
LPS\ContentModel\Hardening::boot();
