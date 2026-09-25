<?php
/**
 * Plugin Name: LPS Content Model
 * Description: Portable structured-content boundary for the LPS institutional website.
 * Version: 0.1.0
 * Requires at least: 6.9
 * Requires PHP: 8.3
 * License: GPL-2.0-or-later
 * Text Domain: lps-content-model
 *
 * @package LPS\ContentModel
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-policy.php';
require_once __DIR__ . '/includes/class-mediarenderer.php';
require_once __DIR__ . '/includes/class-mediapolicy.php';
require_once __DIR__ . '/includes/class-mediausagepolicy.php';
require_once __DIR__ . '/includes/class-mediacontracts.php';
require_once __DIR__ . '/includes/class-mediablocks.php';
require_once __DIR__ . '/includes/class-mediauploads.php';
require_once __DIR__ . '/includes/class-mediaeditor.php';
require_once __DIR__ . '/includes/class-teachingstorage.php';
require_once __DIR__ . '/includes/class-securitypolicy.php';
require_once __DIR__ . '/includes/class-taxonomies.php';
require_once __DIR__ . '/includes/class-relationshippolicy.php';
require_once __DIR__ . '/includes/class-migrations.php';
require_once __DIR__ . '/includes/class-teachingcontracts.php';
require_once __DIR__ . '/includes/class-teachingmigrations.php';
require_once __DIR__ . '/includes/class-teachingrecords.php';
require_once __DIR__ . '/includes/class-teachingrest.php';
require_once __DIR__ . '/includes/class-teachingresources.php';
require_once __DIR__ . '/includes/class-teachingcopy.php';
require_once __DIR__ . '/includes/class-relationships.php';
require_once __DIR__ . '/includes/class-translationpolicy.php';
require_once __DIR__ . '/includes/class-publicationpolicy.php';
require_once __DIR__ . '/includes/class-publicationrecords.php';
require_once __DIR__ . '/includes/class-contracts.php';
require_once __DIR__ . '/includes/class-mfa.php';
require_once __DIR__ . '/includes/class-teachingpolicy.php';
require_once __DIR__ . '/includes/class-roles.php';
require_once __DIR__ . '/includes/class-membercategories.php';
require_once __DIR__ . '/includes/class-audit.php';
require_once __DIR__ . '/includes/class-notifications.php';
require_once __DIR__ . '/includes/class-reports.php';
require_once __DIR__ . '/includes/class-translations.php';
require_once __DIR__ . '/includes/class-media.php';
require_once __DIR__ . '/includes/class-migrationpolicy.php';
require_once __DIR__ . '/includes/class-redirectpolicy.php';
require_once __DIR__ . '/includes/class-importpackage.php';
require_once __DIR__ . '/includes/class-importrepository.php';
require_once __DIR__ . '/includes/class-importplanner.php';
require_once __DIR__ . '/includes/class-mediastager.php';
require_once __DIR__ . '/includes/class-importer.php';
require_once __DIR__ . '/includes/class-exporter.php';
require_once __DIR__ . '/includes/class-reconciler.php';
require_once __DIR__ . '/includes/class-reportwriter.php';
require_once __DIR__ . '/includes/class-clicontext.php';
require_once __DIR__ . '/includes/class-importcommand.php';
require_once __DIR__ . '/includes/class-exportcommand.php';
require_once __DIR__ . '/includes/class-redirectcommand.php';
require_once __DIR__ . '/includes/class-commandregistration.php';
require_once __DIR__ . '/includes/class-searchindex.php';
require_once __DIR__ . '/includes/class-taskdashboard.php';
require_once __DIR__ . '/includes/class-plugin.php';

LPS\ContentModel\Plugin::boot();
LPS\ContentModel\MemberCategories::boot();
LPS\ContentModel\TaskDashboard::boot();
LPS\ContentModel\CommandRegistration::boot();
register_activation_hook( __FILE__, array( LPS\ContentModel\Plugin::class, 'activate' ) );
