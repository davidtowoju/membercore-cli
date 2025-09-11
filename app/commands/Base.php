<?php

namespace membercore\cli\commands;

use membercore\cli\helpers\UserHelper;
use membercore\cli\helpers\MembershipHelper;

/**
 * Base command class with common functionality
 */
class Base
{
    protected $faker;

    public function __construct()
    {
        $this->faker = \Faker\Factory::create();
    }

    /**
     * Reset/truncate MemberCore tables and data
     *
     * ## OPTIONS
     *
     * [<model>...]
     * : The specific tables/models to truncate. If none specified, truncates all MemberCore tables.
     *
     * [--dry-run]
     * : Show what would be truncated without actually doing it.
     *
     * [--confirm]
     * : Skip confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp meco fresh
     *     wp meco fresh transactions subscriptions
     *     wp meco fresh --dry-run
     *
     * @when after_wp_load
     */
    public function fresh($args, $assoc_args)
    {
        global $wpdb;

        $dry_run = isset($assoc_args['dry-run']);
        $confirm = isset($assoc_args['confirm']);

        if (!$dry_run && !$confirm) {
            \WP_CLI::confirm('This will permanently delete MemberCore data. Are you sure?');
        }

        if (empty($args)) {
            $this->truncate_all_tables($dry_run);
        } else {
            $this->truncate_specific_tables($args, $dry_run);
        }

        if (!$dry_run) {
            \WP_CLI::success('MemberCore data has been reset.');
        } else {
            \WP_CLI::log('DRY RUN: No changes were made.');
        }
    }

    /**
     * Show MemberCore system information
     *
     * ## EXAMPLES
     *
     *     wp meco info
     *
     * @when after_wp_load
     */
    public function info($args, $assoc_args)
    {
        $this->show_membercore_info();
    }

    /**
     * List all available MemberCore commands
     *
     * ## EXAMPLES
     *
     *     wp meco list
     *
     * @when after_wp_load
     */
    public function list($args, $assoc_args)
    {
        \WP_CLI::line('Available MemberCore CLI commands:');
        \WP_CLI::line('');
        \WP_CLI::line('Base commands (wp meco):');
        \WP_CLI::line('  fresh     - Reset/truncate MemberCore tables');
        \WP_CLI::line('  info      - Show system information');
        \WP_CLI::line('  list      - Show this help');
        \WP_CLI::line('');
        \WP_CLI::line('Membership commands (wp meco membership):');
        \WP_CLI::line('  list      - List all memberships');
        \WP_CLI::line('  assign    - Assign membership to user');
        \WP_CLI::line('  remove    - Remove membership from user');
        \WP_CLI::line('');
        \WP_CLI::line('User commands (wp meco user):');
        \WP_CLI::line('  info      - Show user membership information');
        \WP_CLI::line('  create    - Create new user with membership');
        \WP_CLI::line('');
        \WP_CLI::line('Transaction commands (wp meco transaction):');
        \WP_CLI::line('  expire    - Expire specific transactions');
        \WP_CLI::line('');
        \WP_CLI::line('For more details on each command, use: wp help <command>');
    }

    /**
     * Truncate all MemberCore tables
     *
     * @param bool $dry_run
     */
    protected function truncate_all_tables(bool $dry_run): void
    {
        global $wpdb;

        $core_tables = $wpdb->get_results("SHOW TABLES LIKE '%meco_%'");

        if ($dry_run) {
            \WP_CLI::line('Tables that would be truncated:');
            foreach ($core_tables as $table) {
                foreach ($table as $tableName) {
                    \WP_CLI::line("  - {$tableName}");
                }
            }
        } else {
            foreach ($core_tables as $table) {
                foreach ($table as $tableName) {
                    $wpdb->query("TRUNCATE TABLE {$tableName}");
                    \WP_CLI::log("Truncated table: {$tableName}");
                }
            }

            // Delete MemberCore posts
            $post_types = ['membercoreproduct', 'meco_rule', 'meco_group', 'meco_reminder'];
            foreach ($post_types as $post_type) {
                $posts = get_posts([
                    'post_type' => $post_type,
                    'numberposts' => -1,
                    'post_status' => 'any'
                ]);

                foreach ($posts as $post) {
                    wp_delete_post($post->ID, true);
                }
                \WP_CLI::log("Deleted {$post_type} posts");
            }
        }
    }

    /**
     * Truncate specific MemberCore tables
     *
     * @param array $args
     * @param bool $dry_run
     */
    protected function truncate_specific_tables(array $args, bool $dry_run): void
    {
        global $wpdb;

        $table_map = [
            'transactions' => 'meco_transactions',
            'subscriptions' => 'meco_subscriptions',
            'events' => 'meco_events',
            'jobs' => 'meco_jobs',
            'members' => 'meco_members',
        ];

        if ($dry_run) {
            \WP_CLI::line('Tables that would be truncated:');
        }

        foreach ($args as $arg) {
            if (isset($table_map[$arg])) {
                $table_name = $wpdb->prefix . $table_map[$arg];
                
                if ($dry_run) {
                    \WP_CLI::line("  - {$table_name}");
                } else {
                    $wpdb->query("TRUNCATE TABLE {$table_name}");
                    \WP_CLI::log("Truncated table: {$table_name}");
                }
            } else {
                \WP_CLI::warning("Unknown table: {$arg}");
            }
        }
    }

    /**
     * Show MemberCore system information
     */
    protected function show_membercore_info(): void
    {
        \WP_CLI::line('MemberCore System Information:');
        \WP_CLI::line('');

        // Plugin version
        if (defined('MEPR_VERSION')) {
            \WP_CLI::line("Plugin Version: " . MEPR_VERSION);
        } else {
            \WP_CLI::line("Plugin Version: Not detected");
        }

        // Database status
        $this->show_database_status();

        // Memberships count
        $memberships = MembershipHelper::get_all_memberships();
        \WP_CLI::line("Total Memberships: " . count($memberships));

        // Users count
        $users = get_users(['count_total' => true]);
        \WP_CLI::line("Total Users: " . $users);

        // Recent activity
        $this->show_recent_activity();
    }

    /**
     * Show database status
     */
    protected function show_database_status(): void
    {
        global $wpdb;

        if (!class_exists('\MecoDb')) {
            \WP_CLI::line("Database: MemberCore not fully loaded");
            return;
        }

        $meco_db = new \MecoDb();
        $tables = [
            'Transactions' => $meco_db->transactions,
            'Subscriptions' => $meco_db->subscriptions,
            'Events' => $meco_db->events,
        ];

        \WP_CLI::line("Database Status:");
        foreach ($tables as $label => $table) {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            \WP_CLI::line("  {$label}: {$count}");
        }
    }

    /**
     * Show recent activity
     */
    protected function show_recent_activity(): void
    {
        if (!class_exists('\MecoTransaction')) {
            return;
        }

        global $wpdb;
        $meco_db = new \MecoDb();

        $recent_transactions = $wpdb->get_results(
            "SELECT * FROM {$meco_db->transactions} 
             ORDER BY created_at DESC 
             LIMIT 5"
        );

        if (!empty($recent_transactions)) {
            \WP_CLI::line("Recent Transactions:");
            foreach ($recent_transactions as $txn) {
                $user = get_user_by('ID', $txn->user_id);
                $username = $user ? $user->user_login : 'Unknown';
                \WP_CLI::line("  #{$txn->id} - {$username} - {$txn->status} - {$txn->created_at}");
            }
        }
    }

    /**
     * Common method to validate user exists
     *
     * @param int $user_id
     * @return bool
     */
    protected function validate_user_exists(int $user_id): bool
    {
        if (!UserHelper::user_exists($user_id)) {
            \WP_CLI::error("User with ID {$user_id} does not exist.");
            return false;
        }
        return true;
    }

    /**
     * Common method to validate membership exists
     *
     * @param int $membership_id
     * @return bool
     */
    protected function validate_membership_exists(int $membership_id): bool
    {
        $membership = MembershipHelper::get_membership_by_id($membership_id);
        if (!$membership) {
            \WP_CLI::error("Membership with ID {$membership_id} does not exist.");
            return false;
        }
        return true;
    }

    /**
     * Format success message
     *
     * @param string $message
     */
    protected function success(string $message): void
    {
        \WP_CLI::success($message);
    }

    /**
     * Format error message
     *
     * @param string $message
     */
    protected function error(string $message): void
    {
        \WP_CLI::error($message);
    }

    /**
     * Format warning message
     *
     * @param string $message
     */
    protected function warning(string $message): void
    {
        \WP_CLI::warning($message);
    }

    /**
     * Format log message
     *
     * @param string $message
     */
    protected function log(string $message): void
    {
        \WP_CLI::log($message);
    }
}
