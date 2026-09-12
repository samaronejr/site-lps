import { writeFile } from "node:fs/promises";

const output = process.argv[2];
if (!output) throw new Error("usage: node scripts/todo10-blueprint.mjs <output.json>");

const password = process.env.LPS_TASK10_PASSWORD;
if (!password) throw new Error("LPS_TASK10_PASSWORD is required");

const accounts = [
  ["task10-ana", "lps_contributor"],
  ["task10-bruno", "lps_translator"],
  ["task10-carla", "lps_section_editor"],
  ["task10-diego", "lps_publisher"],
  ["task10-elisa", "lps_administrator"],
  ["task10-fabia", "lps_privacy_auditor"],
  ["task10-gustavo", "lps_deployer"],
];

const fixture = `<?php
$docroot = getenv('DOCROOT') ?: '/wordpress';
require_once $docroot . '/wp-load.php';
$accounts = ${JSON.stringify(accounts)};
$collections = \\LPS\\ContentModel\\Roles::collections();
$receipt = array();
foreach ($accounts as [$login, $role]) {
    $existing = get_user_by('login', $login);
    if ($existing instanceof WP_User) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($existing->ID);
    }
    $id = wp_insert_user(array(
        'user_login' => $login,
        'user_pass' => ${JSON.stringify(password)},
        'user_email' => $login . '@example.test',
        'display_name' => $login,
        'role' => $role,
    ));
    if (is_wp_error($id)) {
        throw new RuntimeException($login . ': ' . $id->get_error_message());
    }
    update_user_meta((int) $id, \\LPS\\ContentModel\\Roles::COLLECTIONS_META, $collections);
    $receipt[] = array('id' => (int) $id, 'login' => $login, 'role' => $role);
}
update_option('_task10_fixture_receipt', $receipt, false);
`;

const blueprint = {
  $schema: "https://playground.wordpress.net/blueprint-schema.json",
  landingPage: "/wp-admin/",
  preferredVersions: { php: "8.3", wp: "latest" },
  steps: [
    { step: "activatePlugin", pluginPath: "/wordpress/wp-content/plugins/lps-content-model" },
    { step: "activatePlugin", pluginPath: "/wordpress/wp-content/plugins/polylang" },
    { step: "activatePlugin", pluginPath: "/wordpress/wp-content/plugins/two-factor" },
    { step: "defineWpConfigConsts", consts: { WP_DEBUG: true, WP_DEBUG_LOG: true, WP_ENVIRONMENT_TYPE: "local" } },
    { step: "runPHP", code: { filename: "todo10-provision.php", content: fixture } },
  ],
};

await writeFile(output, `${JSON.stringify(blueprint, null, 2)}\n`, { mode: 0o600 });
console.log(`blueprint=${output}`);
console.log(`accounts=${accounts.length}`);
