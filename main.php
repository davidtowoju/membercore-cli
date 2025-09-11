<?php
/**
 * Plugin Name:     MemberCore CLI
 * Plugin URI:      https://membercore.com/
 * Description:     Extended WP-CLI commands for MemberCore management including membership assignment, user management, and more.
 * Author:          MemberCore
 * Author URI:      https://membercore.com/
 * Text Domain:     membercore-cli
 * Domain Path:     /languages
 * Version:         1.0.0
 *
 * @package         membercore/cli
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('MEMBERCORE_CLI_VERSION', '1.0.0');
define('MEMBERCORE_CLI_PLUGIN_FILE', __FILE__);
define('MEMBERCORE_CLI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MEMBERCORE_CLI_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoload dependencies
if (file_exists(MEMBERCORE_CLI_PLUGIN_DIR . '/vendor/autoload.php')) {
    require MEMBERCORE_CLI_PLUGIN_DIR . '/vendor/autoload.php';
} else {
    // Fallback: require files manually if no autoloader
    require_once MEMBERCORE_CLI_PLUGIN_DIR . '/app/helpers/UserHelper.php';
    require_once MEMBERCORE_CLI_PLUGIN_DIR . '/app/helpers/MembershipHelper.php';
    require_once MEMBERCORE_CLI_PLUGIN_DIR . '/app/commands/Base.php';
    require_once MEMBERCORE_CLI_PLUGIN_DIR . '/app/commands/Membership.php';
    require_once MEMBERCORE_CLI_PLUGIN_DIR . '/app/commands/User.php';
    require_once MEMBERCORE_CLI_PLUGIN_DIR . '/app/commands/Transaction.php';
    require_once MEMBERCORE_CLI_PLUGIN_DIR . '/app/commands/Coaching.php';
    require_once MEMBERCORE_CLI_PLUGIN_DIR . '/app/commands/Courses.php';
    require_once MEMBERCORE_CLI_PLUGIN_DIR . '/app/commands/Directory.php';
}

// Only run if WP-CLI is available
if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

/**
 * Initialize MemberCore CLI commands
 */
add_action('plugins_loaded', function() {
    
    // Command configuration
    $commands = [
        [
            'slug'       => 'meco',
            'class'      => 'Base',
            'namespace'  => 'membercore\\cli\\commands',
            'dependency' => 'MecoTransaction',
            'description' => 'Base MemberCore commands for system management',
        ],
        [
            'slug'       => 'meco membership',
            'class'      => 'Membership',
            'namespace'  => 'membercore\\cli\\commands',
            'dependency' => 'MecoTransaction',
            'description' => 'Membership assignment and management commands',
        ],
        [
            'slug'       => 'meco user',
            'class'      => 'User',
            'namespace'  => 'membercore\\cli\\commands',
            'dependency' => 'MecoTransaction',
            'description' => 'User management commands with membership integration',
        ],
        [
            'slug'       => 'meco transaction',
            'class'      => 'Transaction',
            'namespace'  => 'membercore\\cli\\commands',
            'dependency' => 'MecoTransaction',
            'description' => 'Transaction management commands',
        ],
        [
            'slug'       => 'mpcs',
            'class'      => 'Courses',
            'namespace'  => 'membercore\\cli\\commands',
            'dependency' => 'membercore\\courses\\models\\Course',
            'description' => 'MemberCore Courses management commands',
        ],
        [
            'slug'       => 'mpch',
            'class'      => 'Coaching',
            'namespace'  => 'membercore\\cli\\commands',
            'dependency' => 'memberpress\\coachkit\\models\\Program',
            'description' => 'MemberCore Coaching management commands',
        ],
        [
            'slug'       => 'meco directory',
            'class'      => 'Directory',
            'namespace'  => 'membercore\\cli\\commands',
            'dependency' => 'membercore\\profiles\\models\\Directory',
            'description' => 'Directory enrollment management commands',
        ],
        [
            'slug'       => 'meco jobs',
            'class'      => 'Directory',
            'namespace'  => 'membercore\\cli\\commands',
            'dependency' => 'membercore\\profiles\\models\\Directory',
            'description' => 'Directory job management commands',
        ],
        // Backwards compatibility for old mcpd commands
        [
            'slug'       => 'mcpd directory',
            'class'      => 'Directory',
            'namespace'  => 'membercore\\cli\\commands',
            'dependency' => 'membercore\\profiles\\models\\Directory',
            'description' => 'Directory enrollment management commands (legacy)',
        ],
        [
            'slug'       => 'mcpd jobs',
            'class'      => 'Directory',
            'namespace'  => 'membercore\\cli\\commands',
            'dependency' => null,
            'description' => 'Directory job management commands (legacy)',
        ],
    ];

    // Register commands
    foreach ($commands as $command) {
        $class_name = $command['namespace'] . '\\' . $command['class'];

        // Check if command class exists
        if (!class_exists($class_name)) {
            WP_CLI::debug("Command class {$class_name} not found", 'membercore-cli');
            continue;
        }

        // Check if dependency exists (if specified)
        if (isset($command['dependency']) && !class_exists($command['dependency'])) {
            WP_CLI::debug("Dependency {$command['dependency']} not found for {$command['slug']}", 'membercore-cli');
            continue;
        }

        // Register the command
        try {
            WP_CLI::add_command($command['slug'], $class_name);
            WP_CLI::debug("Registered command: {$command['slug']}", 'membercore-cli');
        } catch (Exception $e) {
            WP_CLI::debug("Failed to register command {$command['slug']}: " . $e->getMessage(), 'membercore-cli');
        }
    }

    // Add success message for debugging
    WP_CLI::debug('MemberCore CLI commands initialized', 'membercore-cli');

}, 10);

/**
 * Show warning if MemberCore is not active
 */
add_action('admin_notices', function() {
    if (!class_exists('MecoTransaction') && current_user_can('activate_plugins')) {
        ?>
        <div class="notice notice-warning">
            <p>
                <strong>MemberCore CLI:</strong> 
                MemberCore plugin is not active. Some CLI commands may not work properly.
            </p>
        </div>
        <?php
    }
});

/**
 * Add plugin action links
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $plugin_links = [
        '<a href="' . admin_url('admin.php?page=membercore-options') . '">Settings</a>',
    ];
    
    return array_merge($plugin_links, $links);
});

/**
 * Plugin activation hook
 */
register_activation_hook(__FILE__, function() {
    // Check if WP-CLI is available
    if (!defined('WP_CLI')) {
        wp_die(
            'MemberCore CLI requires WP-CLI to be installed and available. ' .
            'Please install WP-CLI first: https://wp-cli.org/'
        );
    }

    // Check if MemberCore is active
    if (!class_exists('MecoTransaction')) {
        // Create admin notice for missing MemberCore
        set_transient('membercore_cli_missing_membercore', true, 60);
    }
});

/**
 * Show admin notice if MemberCore is missing on activation
 */
add_action('admin_notices', function() {
    if (get_transient('membercore_cli_missing_membercore')) {
        ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <strong>MemberCore CLI:</strong> 
                MemberCore plugin is required but not active. 
                Please install and activate MemberCore first.
            </p>
        </div>
        <?php
        delete_transient('membercore_cli_missing_membercore');
    }
});

/**
 * Plugin deactivation hook
 */
register_deactivation_hook(__FILE__, function() {
    // Clean up any transients
    delete_transient('membercore_cli_missing_membercore');
});

/**
 * Load text domain for translations
 */
add_action('init', function() {
    load_plugin_textdomain(
        'membercore-cli',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages/'
    );
});
