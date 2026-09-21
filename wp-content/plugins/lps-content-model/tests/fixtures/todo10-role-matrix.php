<?php
/**
 * Todo 10 table-driven authorization fixture.
 *
 * @package LPS\ContentModel\Tests
 */

return array(
	'contributor'     => array(
		'allow' => array( 'create', 'edit', 'submit' ),
		'deny'  => array( 'review', 'publish', 'unpublish', 'archive', 'import', 'redirect', 'settings', 'deploy', 'audit', 'dormant-report', 'grant-scope', 'revoke-scope', 'copy-forward' ),
	),
	'translator'      => array(
		'allow' => array( 'edit', 'submit' ),
		'deny'  => array( 'create', 'review', 'publish', 'unpublish', 'archive', 'import', 'redirect', 'settings', 'deploy', 'audit', 'dormant-report', 'grant-scope', 'revoke-scope', 'copy-forward' ),
	),
	'section-editor'  => array(
		'allow' => array( 'create', 'edit', 'submit', 'review', 'archive', 'grant-scope', 'revoke-scope' ),
		'deny'  => array( 'publish', 'unpublish', 'import', 'redirect', 'settings', 'deploy', 'audit', 'dormant-report', 'copy-forward' ),
	),
	'publisher'       => array(
		'allow' => array( 'create', 'edit', 'submit', 'review', 'publish', 'unpublish', 'archive', 'redirect' ),
		'deny'  => array( 'import', 'settings', 'deploy', 'audit', 'dormant-report', 'grant-scope', 'revoke-scope', 'copy-forward' ),
	),
	'administrator'   => array(
		'allow' => array( 'create', 'edit', 'submit', 'review', 'publish', 'unpublish', 'archive', 'import', 'redirect', 'settings', 'audit', 'dormant-report', 'grant-scope', 'revoke-scope' ),
		'deny'  => array( 'deploy', 'copy-forward' ),
	),
	'privacy-auditor' => array(
		'allow' => array( 'review', 'audit', 'dormant-report' ),
		'deny'  => array( 'create', 'edit', 'submit', 'publish', 'unpublish', 'archive', 'import', 'redirect', 'settings', 'deploy', 'grant-scope', 'revoke-scope', 'copy-forward' ),
	),
	'deployer'        => array(
		'allow' => array( 'deploy' ),
		'deny'  => array( 'create', 'edit', 'submit', 'review', 'publish', 'unpublish', 'archive', 'import', 'redirect', 'settings', 'audit', 'dormant-report', 'grant-scope', 'revoke-scope', 'copy-forward' ),
	),
	'professor'       => array(
		'allow' => array( 'create', 'edit', 'submit', 'publish', 'copy-forward' ),
		'deny'  => array( 'review', 'unpublish', 'archive', 'import', 'redirect', 'settings', 'deploy', 'audit', 'dormant-report', 'grant-scope', 'revoke-scope' ),
	),
	'delegate'        => array(
		'allow' => array( 'create', 'edit', 'submit' ),
		'deny'  => array( 'review', 'publish', 'unpublish', 'archive', 'import', 'redirect', 'settings', 'deploy', 'audit', 'dormant-report', 'grant-scope', 'revoke-scope', 'copy-forward' ),
	),
);
