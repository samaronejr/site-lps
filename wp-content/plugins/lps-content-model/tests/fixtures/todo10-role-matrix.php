<?php
/** Todo 10 table-driven authorization fixture. */
return array(
	'contributor'     => array(
		'allow' => array( 'create', 'edit', 'submit' ),
		'deny'  => array( 'review', 'publish', 'unpublish', 'archive', 'import', 'redirect', 'settings', 'deploy', 'audit', 'dormant-report' ),
	),
	'translator'      => array(
		'allow' => array( 'edit', 'submit' ),
		'deny'  => array( 'create', 'review', 'publish', 'unpublish', 'archive', 'import', 'redirect', 'settings', 'deploy', 'audit', 'dormant-report' ),
	),
	'section-editor'  => array(
		'allow' => array( 'create', 'edit', 'submit', 'review', 'archive' ),
		'deny'  => array( 'publish', 'unpublish', 'import', 'redirect', 'settings', 'deploy', 'audit', 'dormant-report' ),
	),
	'publisher'       => array(
		'allow' => array( 'create', 'edit', 'submit', 'review', 'publish', 'unpublish', 'archive', 'redirect' ),
		'deny'  => array( 'import', 'settings', 'deploy', 'audit', 'dormant-report' ),
	),
	'administrator'   => array(
		'allow' => array( 'create', 'edit', 'submit', 'review', 'publish', 'unpublish', 'archive', 'import', 'redirect', 'settings', 'audit', 'dormant-report' ),
		'deny'  => array( 'deploy' ),
	),
	'privacy-auditor' => array(
		'allow' => array( 'review', 'audit', 'dormant-report' ),
		'deny'  => array( 'create', 'edit', 'submit', 'publish', 'unpublish', 'archive', 'import', 'redirect', 'settings', 'deploy' ),
	),
	'deployer'        => array(
		'allow' => array( 'deploy' ),
		'deny'  => array( 'create', 'edit', 'submit', 'review', 'publish', 'unpublish', 'archive', 'import', 'redirect', 'settings', 'audit', 'dormant-report' ),
	),
);
