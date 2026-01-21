<?php

namespace membercore\cli\commands;

use membercore\cli\helpers\UserHelper;
use membercore\cli\helpers\MembershipHelper;

/**
 * Membership management commands
 */
class Membership extends Base
{
    /**
     * List all memberships
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Options: table, csv, json, yaml. Default: table
     *
     * [--fields=<fields>]
     * : Fields to display. Default: id,title,slug,status
     *
     * ## EXAMPLES
     *
     *     wp meco membership list
     *     wp meco membership list --format=json
     *     wp meco membership list --fields=id,title
     *
     * @when after_wp_load
     */
    public function list($args, $assoc_args)
    {
        $memberships = MembershipHelper::get_all_memberships();

        if (empty($memberships)) {
            \WP_CLI::warning('No memberships found.');
            return;
        }

        $format = $assoc_args['format'] ?? 'table';
        $fields = $assoc_args['fields'] ?? 'id,title,slug,status';

        \WP_CLI\Utils\format_items($format, $memberships, explode(',', $fields));
    }

    /**
     * Assign membership to user
     *
     * ## OPTIONS
     *
     * <user_id>
     * : User ID, email, or username
     *
     * [<membership_id>]
     * : Membership ID (optional if --random is used)
     *
     * [--random]
     * : Assign a random membership to the user
     *
     * [--amount=<amount>]
     * : Transaction amount. Default: 0.00
     *
     * [--expires=<expires>]
     * : Expiration date (YYYY-MM-DD) or 'never'. Default: calculated from membership settings
     *
     * [--gateway=<gateway>]
     * : Payment gateway. Default: manual
     *
     * [--dry-run]
     * : Show what would happen without making changes
     *
     * ## EXAMPLES
     *
     *     wp meco membership assign 123 456
     *     wp meco membership assign admin@example.com 456
     *     wp meco membership assign john_doe 456 --amount=99.00
     *     wp meco membership assign 123 456 --expires=2024-12-31
     *     wp meco membership assign 123 456 --expires=never
     *     wp meco membership assign 123 456 --dry-run
     *     wp meco membership assign 123 --random
     *     wp meco membership assign admin@example.com --random --dry-run
     *
     * @when after_wp_load
     */
    public function assign($args, $assoc_args)
    {
        $user_identifier = $args[0];
        $dry_run = isset($assoc_args['dry-run']);
        $use_random = isset($assoc_args['random']);

        // Get user
        $user = UserHelper::get_user($user_identifier);
        if (!$user) {
            $this->error("User '{$user_identifier}' not found.");
            return;
        }

        // Determine membership ID
        if ($use_random) {
            // Get all memberships and pick one randomly
            $memberships = MembershipHelper::get_all_memberships();
            if (empty($memberships)) {
                $this->error('No memberships found to assign randomly.');
                return;
            }

            // Filter out memberships the user already has
            $available_memberships = array_filter($memberships, function($membership) use ($user) {
                return !MembershipHelper::user_has_active_membership($user->ID, $membership['id']);
            });

            if (empty($available_memberships)) {
                $this->warning("User {$user->user_login} already has all available memberships.");
                return;
            }

            // Pick a random membership
            $random_membership = $available_memberships[array_rand($available_memberships)];
            $membership_id = intval($random_membership['id']);
            $this->log("Randomly selected membership: {$random_membership['title']} (ID: {$membership_id})");
        } else {
            // Use provided membership ID
            if (!isset($args[1])) {
                $this->error('Please provide a membership ID or use --random flag.');
                return;
            }
            $membership_id = intval($args[1]);
        }

        // Validate membership exists
        if (!$this->validate_membership_exists($membership_id)) {
            return;
        }

        $membership = MembershipHelper::get_membership_by_id($membership_id);

        // Check if user already has this membership
        if (MembershipHelper::user_has_active_membership($user->ID, $membership_id)) {
            $this->warning("User {$user->user_login} already has membership '{$membership['title']}'.");
            return;
        }

        if ($dry_run) {
            $this->log("DRY RUN: Would assign membership '{$membership['title']}' to user {$user->user_login} (ID: {$user->ID})");
            return;
        }

        // Build transaction arguments
        $transaction_args = [];
        
        if (isset($assoc_args['amount'])) {
            $amount = floatval($assoc_args['amount']);
            $transaction_args['amount'] = $amount;
            $transaction_args['total'] = $amount;
        }

        if (isset($assoc_args['expires'])) {
            if ($assoc_args['expires'] === 'never') {
                $transaction_args['expires_at'] = '0000-00-00 00:00:00';
            } else {
                $date = \DateTime::createFromFormat('Y-m-d', $assoc_args['expires']);
                if ($date) {
                    $transaction_args['expires_at'] = $date->format('Y-m-d H:i:s');
                } else {
                    $this->error("Invalid date format. Use YYYY-MM-DD or 'never'.");
                    return;
                }
            }
        }

        if (isset($assoc_args['gateway'])) {
            $transaction_args['gateway'] = $assoc_args['gateway'];
        }

        // Assign membership
        $result = MembershipHelper::assign_membership_to_user($user->ID, $membership_id, $transaction_args);

        if ($result) {
            $this->success("Successfully assigned membership '{$membership['title']}' to user {$user->user_login}.");
            
            // Show transaction details
            if (is_object($result)) {
                $this->log("Transaction ID: {$result->id}");
                $this->log("Transaction Number: {$result->trans_num}");
                $this->log("Amount: \${$result->amount}");
                $this->log("Status: {$result->status}");
                $this->log("Expires: {$result->expires_at}");
            }
        } else {
            $this->error("Failed to assign membership. Please check MemberCore is active and configured correctly.");
        }
    }

    /**
     * Remove membership from user
     *
     * ## OPTIONS
     *
     * <user_id>
     * : User ID, email, or username
     *
     * <membership_id>
     * : Membership ID
     *
     * [--dry-run]
     * : Show what would happen without making changes
     *
     * ## EXAMPLES
     *
     *     wp meco membership remove 123 456
     *     wp meco membership remove admin@example.com 456
     *     wp meco membership remove john_doe 456 --dry-run
     *
     * @when after_wp_load
     */
    public function remove($args, $assoc_args)
    {
        $user_identifier = $args[0];
        $membership_id = intval($args[1]);
        $dry_run = isset($assoc_args['dry-run']);

        // Get user
        $user = UserHelper::get_user($user_identifier);
        if (!$user) {
            $this->error("User '{$user_identifier}' not found.");
            return;
        }

        // Validate membership exists
        if (!$this->validate_membership_exists($membership_id)) {
            return;
        }

        $membership = MembershipHelper::get_membership_by_id($membership_id);

        // Check if user has this membership
        if (!MembershipHelper::user_has_active_membership($user->ID, $membership_id)) {
            $this->warning("User {$user->user_login} does not have membership '{$membership['title']}'.");
            return;
        }

        if ($dry_run) {
            $this->log("DRY RUN: Would remove membership '{$membership['title']}' from user {$user->user_login} (ID: {$user->ID})");
            return;
        }

        // Remove membership
        $result = MembershipHelper::remove_membership_from_user($user->ID, $membership_id);

        if ($result) {
            $this->success("Successfully removed membership '{$membership['title']}' from user {$user->user_login}.");
        } else {
            $this->error("Failed to remove membership. User may not have active transactions for this membership.");
        }
    }

    /**
     * Remove all memberships from user
     *
     * ## OPTIONS
     *
     * <user_id>
     * : User ID, email, or username
     *
     * [--dry-run]
     * : Show what would happen without making changes
     *
     * ## EXAMPLES
     *
     *     wp meco membership remove-all 123
     *     wp meco membership remove-all admin@example.com
     *     wp meco membership remove-all john_doe --dry-run
     *
     * @when after_wp_load
     */
    public function remove_all($args, $assoc_args)
    {
        $user_identifier = $args[0];
        $dry_run = isset($assoc_args['dry-run']);

        // Get user
        $user = UserHelper::get_user($user_identifier);
        if (!$user) {
            $this->error("User '{$user_identifier}' not found.");
            return;
        }

        // Get all active memberships for this user
        $active_memberships = MembershipHelper::get_user_active_memberships($user->ID);

        if (empty($active_memberships)) {
            $this->warning("User {$user->user_login} has no active memberships.");
            return;
        }

        $count = count($active_memberships);
        $this->log("Found {$count} active membership(s) for user {$user->user_login}:");
        
        foreach ($active_memberships as $membership) {
            $this->log("  - {$membership['title']} (ID: {$membership['id']})");
        }

        if ($dry_run) {
            $this->log("DRY RUN: Would remove all {$count} membership(s) from user {$user->user_login} (ID: {$user->ID})");
            return;
        }

        // Remove each membership
        $removed_count = 0;
        $failed_count = 0;

        foreach ($active_memberships as $membership) {
            $result = MembershipHelper::remove_membership_from_user($user->ID, $membership['id']);
            
            if ($result) {
                $this->log("Removed: {$membership['title']}");
                $removed_count++;
            } else {
                $this->warning("Failed to remove: {$membership['title']}");
                $failed_count++;
            }
        }

        if ($failed_count === 0) {
            $this->success("Successfully removed all {$removed_count} membership(s) from user {$user->user_login}.");
        } else {
            $this->warning("Removed {$removed_count} membership(s), but {$failed_count} failed.");
        }
    }

    /**
     * Show membership details
     *
     * ## OPTIONS
     *
     * <membership_id>
     * : Membership ID
     *
     * [--users]
     * : Show users who have this membership
     *
     * ## EXAMPLES
     *
     *     wp meco membership info 123
     *     wp meco membership info 123 --users
     *
     * @when after_wp_load
     */
    public function info($args, $assoc_args)
    {
        $membership_id = intval($args[0]);

        // Validate membership exists
        if (!$this->validate_membership_exists($membership_id)) {
            return;
        }

        $membership = MembershipHelper::get_membership_by_id($membership_id);

        \WP_CLI::line("Membership Information:");
        \WP_CLI::line("ID: {$membership['id']}");
        \WP_CLI::line("Title: {$membership['title']}");
        \WP_CLI::line("Slug: {$membership['slug']}");
        \WP_CLI::line("Status: {$membership['status']}");

        // Get additional membership details
        $this->show_membership_details($membership_id);

        // Show users with this membership if requested
        if (isset($assoc_args['users'])) {
            $this->show_membership_users($membership_id);
        }
    }

    /**
     * Show membership details
     *
     * @param int $membership_id
     */
    protected function show_membership_details(int $membership_id): void
    {
        $product = new MecoProduct($membership_id);

        $price = $product->price;
        $period = $product->period;
        $period_type = $product->period_type;

        if ($price) {
            \WP_CLI::line("Price: \${$price}");
        }

        if ($period && $period_type) {
            \WP_CLI::line("Period: {$period} {$period_type}");
        } else {
            \WP_CLI::line("Period: Lifetime");
        }

        // Show transaction count
        if (class_exists('\MecoDb')) {
            global $wpdb;
            $meco_db = new \MecoDb();
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$meco_db->transactions} WHERE product_id = %d",
                    $membership_id
                )
            );
            \WP_CLI::line("Total Transactions: {$count}");
        }
    }

    /**
     * Show users with this membership
     *
     * @param int $membership_id
     */
    protected function show_membership_users(int $membership_id): void
    {
        if (!class_exists('\MecoDb')) {
            \WP_CLI::warning("Cannot show users: MemberCore not fully loaded.");
            return;
        }

        global $wpdb;
        $meco_db = new \MecoDb();

        $users = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT u.ID, u.user_login, u.user_email, t.status, t.created_at, t.expires_at
                 FROM {$wpdb->users} u
                 JOIN {$meco_db->transactions} t ON u.ID = t.user_id
                 WHERE t.product_id = %d
                 AND t.status IN ('complete', 'confirmed')
                 AND (t.expires_at >= %s OR t.expires_at = '0000-00-00 00:00:00')
                 ORDER BY t.created_at DESC",
                $membership_id,
                current_time('mysql')
            )
        );

        if (empty($users)) {
            \WP_CLI::line("No active users found for this membership.");
            return;
        }

        \WP_CLI::line("\nUsers with this membership:");
        \WP_CLI::line("User ID | Username | Email | Status | Created | Expires");
        \WP_CLI::line(str_repeat('-', 80));

        foreach ($users as $user) {
            $expires = $user->expires_at === '0000-00-00 00:00:00' ? 'Never' : $user->expires_at;
            \WP_CLI::line("{$user->ID} | {$user->user_login} | {$user->user_email} | {$user->status} | {$user->created_at} | {$expires}");
        }
    }

    /**
     * Bulk assign memberships to users
     *
     * ## OPTIONS
     *
     * <membership_id>
     * : Membership ID
     *
     * [--users=<users>]
     * : Comma-separated list of user IDs, emails, or usernames
     *
     * [--role=<role>]
     * : Assign to all users with this role
     *
     * [--dry-run]
     * : Show what would happen without making changes
     *
     * ## EXAMPLES
     *
     *     wp meco membership bulk-assign 123 --users=1,2,3
     *     wp meco membership bulk-assign 123 --role=subscriber
     *     wp meco membership bulk-assign 123 --users=admin@example.com,john_doe --dry-run
     *
     * @when after_wp_load
     */
    public function bulk_assign($args, $assoc_args)
    {
        $membership_id = intval($args[0]);
        $dry_run = isset($assoc_args['dry-run']);

        // Validate membership exists
        if (!$this->validate_membership_exists($membership_id)) {
            return;
        }

        $membership = MembershipHelper::get_membership_by_id($membership_id);

        // Get users to assign
        $users = [];
        if (isset($assoc_args['users'])) {
            $user_identifiers = array_map('trim', explode(',', $assoc_args['users']));
            foreach ($user_identifiers as $identifier) {
                $user = UserHelper::get_user($identifier);
                if ($user) {
                    $users[] = $user;
                } else {
                    $this->warning("User '{$identifier}' not found.");
                }
            }
        } elseif (isset($assoc_args['role'])) {
            $users = UserHelper::get_users_by_role($assoc_args['role']);
        } else {
            $this->error("You must specify either --users or --role.");
            return;
        }

        if (empty($users)) {
            $this->error("No users found to assign membership to.");
            return;
        }

        $this->log("Processing " . count($users) . " users for membership assignment...");

        $assigned_count = 0;
        $skipped_count = 0;

        foreach ($users as $user) {
            // Check if user already has this membership
            if (MembershipHelper::user_has_active_membership($user->ID, $membership_id)) {
                $this->log("Skipping {$user->user_login} - already has membership");
                $skipped_count++;
                continue;
            }

            if ($dry_run) {
                $this->log("DRY RUN: Would assign membership to {$user->user_login}");
                $assigned_count++;
                continue;
            }

            // Assign membership
            $result = MembershipHelper::assign_membership_to_user($user->ID, $membership_id);

            if ($result) {
                $this->log("Assigned membership to {$user->user_login}");
                $assigned_count++;
            } else {
                $this->warning("Failed to assign membership to {$user->user_login}");
            }
        }

        $message = $dry_run ? "DRY RUN: Would assign" : "Successfully assigned";
        $this->success("{$message} membership '{$membership['title']}' to {$assigned_count} users. Skipped {$skipped_count} users.");
    }
} 