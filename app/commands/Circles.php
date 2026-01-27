<?php

namespace membercore\cli\commands;

use membercore\circles\models\Circle;
use membercore\circles\models\CircleMember;
use membercore\circles\helpers\RoleManager;

/**
 * MemberCore Circles CLI Commands
 * 
 * Manage circles and circle members via WP-CLI
 */
class Circles
{
    protected $faker;

    public function __construct()
    {
        $this->faker = \Faker\Factory::create();
    }

    /**
     * Create a new circle
     *
     * ## OPTIONS
     *
     * [--title=<title>]
     * : Circle title. If not provided, a random title will be generated.
     *
     * [--description=<description>]
     * : Circle description.
     *
     * [--creator=<user_id>]
     * : User ID of the circle creator. Defaults to first admin user.
     *
     * [--status=<status>]
     * : Circle status (publish, draft, private). Default: publish
     *
     * [--members=<user_ids>]
     * : Comma-separated list of user IDs to add as members.
     *
     * ## EXAMPLES
     *
     *     wp mccirc create
     *     wp mccirc create --title="Developer Circle" --description="For developers only"
     *     wp mccirc create --title="VIP Circle" --members=2,3,4
     *
     * @when after_wp_load
     */
    public function create($args, $assoc_args)
    {
        if (!class_exists('membercore\circles\models\Circle')) {
            \WP_CLI::error('MemberCore Circles plugin is not active.');
            return;
        }

        $title = $assoc_args['title'] ?? $this->faker->words(3, true);
        $description = $assoc_args['description'] ?? $this->faker->sentence();
        $status = $assoc_args['status'] ?? 'publish';
        
        // Get creator - first admin if not specified
        $creator_id = isset($assoc_args['creator']) ? absint($assoc_args['creator']) : null;
        if (!$creator_id) {
            $admins = get_users(['role' => 'administrator', 'number' => 1]);
            $creator_id = !empty($admins) ? $admins[0]->ID : 1;
        }

        // Create the circle post
        $circle_id = wp_insert_post([
            'post_type' => 'mc-circle',
            'post_title' => $title,
            'post_content' => $description,
            'post_status' => $status,
            'post_author' => $creator_id,
        ]);

        if (is_wp_error($circle_id)) {
            \WP_CLI::error('Failed to create circle: ' . $circle_id->get_error_message());
            return;
        }

        $circle = new Circle($circle_id);

        // Add creator as admin
        CircleMember::add($circle_id, $creator_id, 'mccirc-admin', 'active');

        // Add additional members if specified
        if (!empty($assoc_args['members'])) {
            $member_ids = array_map('absint', explode(',', $assoc_args['members']));
            foreach ($member_ids as $user_id) {
                if ($user_id === $creator_id) {
                    continue; // Skip creator, already added
                }
                
                if (get_user_by('id', $user_id)) {
                    CircleMember::add($circle_id, $user_id, 'mccirc-member', 'active');
                    \WP_CLI::log("Added user {$user_id} as member");
                }
            }
        }

        \WP_CLI::success("Circle created: {$title} (ID: {$circle_id})");
    }

    /**
     * Add a member to a circle
     *
     * ## OPTIONS
     *
     * <circle_id>
     * : The circle ID.
     *
     * <user_id>
     * : The user ID to add.
     *
     * [--role=<role>]
     * : Member role (mccirc-admin, mccirc-moderator, mccirc-member). Default: mccirc-member
     *
     * [--status=<status>]
     * : Member status (active, banned, muted). Default: active
     *
     * ## EXAMPLES
     *
     *     wp mccirc add-member 123 456
     *     wp mccirc add-member 123 456 --role=mccirc-moderator
     *
     * @when after_wp_load
     */
    public function add_member($args, $assoc_args)
    {
        if (!class_exists('membercore\circles\models\CircleMember')) {
            \WP_CLI::error('MemberCore Circles plugin is not active.');
            return;
        }

        list($circle_id, $user_id) = $args;
        $circle_id = absint($circle_id);
        $user_id = absint($user_id);
        $role = $assoc_args['role'] ?? 'mccirc-member';
        $status = $assoc_args['status'] ?? 'active';

        // Validate circle exists
        $circle = get_post($circle_id);
        if (!$circle || $circle->post_type !== 'mc-circle') {
            \WP_CLI::error("Circle {$circle_id} not found.");
            return;
        }

        // Validate user exists
        if (!get_user_by('id', $user_id)) {
            \WP_CLI::error("User {$user_id} not found.");
            return;
        }

        // Add member
        CircleMember::add($circle_id, $user_id, $role, $status);

        \WP_CLI::success("Added user {$user_id} to circle {$circle_id} as {$role}");
    }

    /**
     * List all circles
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format (table, csv, json, yaml). Default: table
     *
     * [--fields=<fields>]
     * : Comma-separated list of fields to display.
     *
     * ## EXAMPLES
     *
     *     wp mccirc list
     *     wp mccirc list --format=json
     *
     * @when after_wp_load
     */
    public function list($args, $assoc_args)
    {
        if (!class_exists('membercore\circles\models\Circle')) {
            \WP_CLI::error('MemberCore Circles plugin is not active.');
            return;
        }

        $circles = get_posts([
            'post_type' => 'mc-circle',
            'posts_per_page' => -1,
            'post_status' => 'any',
        ]);

        if (empty($circles)) {
            \WP_CLI::warning('No circles found.');
            return;
        }

        $data = [];
        foreach ($circles as $circle) {
            $members_count = CircleMember::get_user_ids_by_circle($circle->ID, true);
            
            $data[] = [
                'ID' => $circle->ID,
                'Title' => $circle->post_title,
                'Status' => $circle->post_status,
                'Members' => count($members_count),
                'Author' => get_the_author_meta('display_name', $circle->post_author),
                'Created' => $circle->post_date,
            ];
        }

        $format = $assoc_args['format'] ?? 'table';
        $fields = isset($assoc_args['fields']) ? explode(',', $assoc_args['fields']) : ['ID', 'Title', 'Status', 'Members', 'Author', 'Created'];

        \WP_CLI\Utils\format_items($format, $data, $fields);
    }

    /**
     * List members of a circle
     *
     * ## OPTIONS
     *
     * <circle_id>
     * : The circle ID.
     *
     * [--format=<format>]
     * : Output format (table, csv, json, yaml). Default: table
     *
     * ## EXAMPLES
     *
     *     wp mccirc list-members 123
     *     wp mccirc list-members 123 --format=json
     *
     * @when after_wp_load
     */
    public function list_members($args, $assoc_args)
    {
        if (!class_exists('membercore\circles\models\CircleMember')) {
            \WP_CLI::error('MemberCore Circles plugin is not active.');
            return;
        }

        $circle_id = absint($args[0]);

        // Validate circle exists
        $circle = get_post($circle_id);
        if (!$circle || $circle->post_type !== 'mc-circle') {
            \WP_CLI::error("Circle {$circle_id} not found.");
            return;
        }

        global $wpdb;
        $members_table = $wpdb->prefix . 'mccirc_circle_members';
        
        $members = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$members_table} WHERE circle_id = %d ORDER BY created_at DESC",
            $circle_id
        ));

        if (empty($members)) {
            \WP_CLI::warning("No members found in circle {$circle_id}.");
            return;
        }

        $data = [];
        foreach ($members as $member) {
            $user = get_user_by('id', $member->user_id);
            $data[] = [
                'User ID' => $member->user_id,
                'Name' => $user ? $user->display_name : 'Unknown',
                'Email' => $user ? $user->user_email : 'Unknown',
                'Role' => $member->role,
                'Status' => $member->status,
                'Joined' => $member->created_at,
            ];
        }

        $format = $assoc_args['format'] ?? 'table';
        \WP_CLI\Utils\format_items($format, $data, ['User ID', 'Name', 'Email', 'Role', 'Status', 'Joined']);
    }

    /**
     * Delete a circle
     *
     * ## OPTIONS
     *
     * <circle_id>
     * : The circle ID to delete.
     *
     * [--force]
     * : Force delete (bypass trash).
     *
     * ## EXAMPLES
     *
     *     wp mccirc delete 123
     *     wp mccirc delete 123 --force
     *
     * @when after_wp_load
     */
    public function delete($args, $assoc_args)
    {
        if (!class_exists('membercore\circles\models\Circle')) {
            \WP_CLI::error('MemberCore Circles plugin is not active.');
            return;
        }

        $circle_id = absint($args[0]);
        $force = isset($assoc_args['force']);

        // Validate circle exists
        $circle = get_post($circle_id);
        if (!$circle || $circle->post_type !== 'mc-circle') {
            \WP_CLI::error("Circle {$circle_id} not found.");
            return;
        }

        // Delete all members
        global $wpdb;
        $members_table = $wpdb->prefix . 'mccirc_circle_members';
        $deleted_members = $wpdb->delete($members_table, ['circle_id' => $circle_id]);

        // Delete the post
        $result = wp_delete_post($circle_id, $force);

        if (!$result) {
            \WP_CLI::error("Failed to delete circle {$circle_id}.");
            return;
        }

        \WP_CLI::success("Deleted circle {$circle_id} and {$deleted_members} members.");
    }

    /**
     * Truncate all circles and circle members
     *
     * ## OPTIONS
     *
     * [--confirm]
     * : Skip confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp mccirc truncate
     *     wp mccirc truncate --confirm
     *
     * @when after_wp_load
     */
    public function truncate($args, $assoc_args)
    {
        if (!class_exists('membercore\circles\models\Circle')) {
            \WP_CLI::error('MemberCore Circles plugin is not active.');
            return;
        }

        if (!isset($assoc_args['confirm'])) {
            \WP_CLI::confirm('This will delete ALL circles and their members. Are you sure?', true);
        }

        global $wpdb;

        // Delete all circle members
        $members_table = $wpdb->prefix . 'mccirc_circle_members';
        $wpdb->query("TRUNCATE TABLE {$members_table}");

        // Delete all circle posts
        $circles = get_posts([
            'post_type' => 'mc-circle',
            'posts_per_page' => -1,
            'post_status' => 'any',
        ]);

        $count = 0;
        foreach ($circles as $circle) {
            wp_delete_post($circle->ID, true);
            $count++;
        }

        \WP_CLI::success("Truncated {$count} circles and all circle members.");
    }

    /**
     * Seed circles with fake data
     *
     * ## OPTIONS
     *
     * [--count=<count>]
     * : Number of circles to create. Default: 5
     *
     * [--members=<members>]
     * : Number of members per circle. Default: 5-15 (random)
     *
     * ## EXAMPLES
     *
     *     wp mccirc seed
     *     wp mccirc seed --count=10 --members=20
     *
     * @when after_wp_load
     */
    public function seed($args, $assoc_args)
    {
        if (!class_exists('membercore\circles\models\Circle')) {
            \WP_CLI::error('MemberCore Circles plugin is not active.');
            return;
        }

        $count = isset($assoc_args['count']) ? absint($assoc_args['count']) : 5;
        $members_count = isset($assoc_args['members']) ? absint($assoc_args['members']) : 0;

        // Get all users
        $users = get_users(['fields' => 'ID']);
        if (count($users) < 2) {
            \WP_CLI::error('Need at least 2 users to seed circles.');
            return;
        }

        $progress = \WP_CLI\Utils\make_progress_bar("Creating {$count} circles", $count);

        for ($i = 0; $i < $count; $i++) {
            $title = $this->faker->words(3, true);
            $description = $this->faker->sentences(3, true);
            $creator_id = $users[array_rand($users)];

            // Create circle
            $circle_id = wp_insert_post([
                'post_type' => 'mc-circle',
                'post_title' => ucwords($title),
                'post_content' => $description,
                'post_status' => 'publish',
                'post_author' => $creator_id,
            ]);

            if (is_wp_error($circle_id)) {
                continue;
            }

            // Add creator as admin
            CircleMember::add($circle_id, $creator_id, 'mccirc-admin', 'active');

            // Add random members
            $member_count = $members_count > 0 ? $members_count : rand(5, 15);
            $added_users = [$creator_id]; // Track to avoid duplicates

            for ($j = 0; $j < $member_count && $j < count($users); $j++) {
                $user_id = $users[array_rand($users)];
                
                // Skip if already added
                if (in_array($user_id, $added_users)) {
                    continue;
                }
                
                $added_users[] = $user_id;
                
                // Randomly assign role
                $roles = ['mccirc-member', 'mccirc-member', 'mccirc-member', 'mccirc-moderator']; // More members than moderators
                $role = $roles[array_rand($roles)];
                
                CircleMember::add($circle_id, $user_id, $role, 'active');
            }

            $progress->tick();
        }

        $progress->finish();
        \WP_CLI::success("Created {$count} circles with random members.");
    }
}
