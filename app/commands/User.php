<?php

namespace membercore\cli\commands;

use membercore\cli\helpers\UserHelper;
use membercore\cli\helpers\MembershipHelper;

/**
 * User management commands
 */
class User extends Base
{
    /**
     * Show user information including membership details
     *
     * ## OPTIONS
     *
     * <user_id>
     * : User ID, email, or username
     *
     * [--memberships]
     * : Show detailed membership information
     *
     * [--transactions]
     * : Show recent transactions
     *
     * ## EXAMPLES
     *
     *     wp meco user info 123
     *     wp meco user info admin@example.com --memberships
     *     wp meco user info john_doe --transactions
     *
     * @when after_wp_load
     */
    public function info($args, $assoc_args)
    {
        $user_identifier = $args[0];

        // Get user
        $user = UserHelper::get_user($user_identifier);
        if (!$user) {
            $this->error("User '{$user_identifier}' not found.");
            return;
        }

        // Basic user information
        \WP_CLI::line("User Information:");
        \WP_CLI::line("ID: {$user->ID}");
        \WP_CLI::line("Username: {$user->user_login}");
        \WP_CLI::line("Email: {$user->user_email}");
        \WP_CLI::line("Display Name: {$user->display_name}");
        \WP_CLI::line("Role: " . implode(', ', $user->roles));
        \WP_CLI::line("Registered: {$user->user_registered}");

        // Show membership information
        $this->show_user_memberships($user->ID);

        // Show detailed membership information if requested
        if (isset($assoc_args['memberships'])) {
            $this->show_detailed_membership_info($user->ID);
        }

        // Show recent transactions if requested
        if (isset($assoc_args['transactions'])) {
            $this->show_user_transactions($user->ID);
        }
    }

    /**
     * Create a new user with optional membership assignment
     *
     * ## OPTIONS
     *
     * <username>
     * : Username for the new user
     *
     * <email>
     * : Email address for the new user
     *
     * [--password=<password>]
     * : Password for the new user. If not provided, one will be generated.
     *
     * [--role=<role>]
     * : Role for the new user. Default: subscriber
     *
     * [--membership=<membership_id>]
     * : Membership ID to assign to the new user
     *
     * [--first-name=<first_name>]
     * : First name for the new user
     *
     * [--last-name=<last_name>]
     * : Last name for the new user
     *
     * [--display-name=<display_name>]
     * : Display name for the new user
     *
     * [--send-email]
     * : Send new user notification email
     *
     * ## EXAMPLES
     *
     *     wp meco user create john_doe john@example.com
     *     wp meco user create jane_doe jane@example.com --membership=123
     *     wp meco user create admin admin@example.com --role=administrator --send-email
     *
     * @when after_wp_load
     */
    public function create($args, $assoc_args)
    {
        $username = $args[0];
        $email = $args[1];

        // Validate email
        if (!is_email($email)) {
            $this->error("Invalid email address: {$email}");
            return;
        }

        // Check if user already exists
        if (username_exists($username) || email_exists($email)) {
            $this->error("User with username '{$username}' or email '{$email}' already exists.");
            return;
        }

        // Build user data
        $user_data = [
            'user_login' => $username,
            'user_email' => $email,
            'user_pass' => $assoc_args['password'] ?? wp_generate_password(),
            'role' => $assoc_args['role'] ?? 'subscriber',
        ];

        // Add optional fields
        if (isset($assoc_args['first-name'])) {
            $user_data['first_name'] = $assoc_args['first-name'];
        }
        if (isset($assoc_args['last-name'])) {
            $user_data['last_name'] = $assoc_args['last-name'];
        }
        if (isset($assoc_args['display-name'])) {
            $user_data['display_name'] = $assoc_args['display-name'];
        }

        // Create user
        $user_id = UserHelper::create_user($user_data);

        if (is_wp_error($user_id)) {
            $this->error("Failed to create user: " . $user_id->get_error_message());
            return;
        }

        $this->success("Successfully created user '{$username}' with ID {$user_id}.");

        // Send notification email if requested
        if (isset($assoc_args['send-email'])) {
            wp_send_new_user_notifications($user_id, 'both');
            $this->log("Sent new user notification email.");
        }

        // Assign membership if specified
        if (isset($assoc_args['membership'])) {
            $membership_id = intval($assoc_args['membership']);
            
            if ($this->validate_membership_exists($membership_id)) {
                $result = MembershipHelper::assign_membership_to_user($user_id, $membership_id);
                
                if ($result) {
                    $membership = MembershipHelper::get_membership_by_id($membership_id);
                    $this->success("Successfully assigned membership '{$membership['title']}' to user.");
                } else {
                    $this->warning("User created but failed to assign membership.");
                }
            }
        }

        // Show user information
        $this->log("User Password: {$user_data['user_pass']}");
        $this->log("User Email: {$email}");
        $this->log("User Role: {$user_data['role']}");
    }

    /**
     * Create multiple users in bulk with optional random membership assignment
     *
     * ## OPTIONS
     *
     * <count>
     * : Number of users to create
     *
     * [--prefix=<prefix>]
     * : Prefix for usernames and emails. Default: testuser
     *
     * [--domain=<domain>]
     * : Email domain for generated users. Default: example.com
     *
     * [--role=<role>]
     * : Role for the new users. Default: subscriber
     *
     * [--memberships=<membership_ids>]
     * : Comma-separated list of membership IDs to randomly assign
     *
     * [--membership-probability=<probability>]
     * : Probability (0-100) that each user gets a membership. Default: 50
     *
     * [--batch-size=<size>]
     * : Number of users to create per batch. Default: 50
     *
     * [--dry-run]
     * : Show what would be created without actually creating users
     *
     * [--send-emails]
     * : Send new user notification emails (not recommended for bulk)
     *
     * [--start-from=<number>]
     * : Starting number for user suffix. If not provided, auto-detects next available number
     *
     * ## EXAMPLES
     *
     *     wp meco user bulk-create 1000
     *     wp meco user bulk-create 500 --prefix=member --domain=mysite.com
     *     wp meco user bulk-create 100 --memberships=123,456,789 --membership-probability=75
     *     wp meco user bulk-create 1000 --batch-size=100 --dry-run
     *     wp meco user bulk-create 50 --prefix=testuser --start-from=101
     *
     * @when after_wp_load
     * @alias bulk_create
     */
    public function bulk_create($args, $assoc_args)
    {
        return $this->_bulk_create_implementation($args, $assoc_args);
    }

    /**
     * Alias for bulk_create to support hyphenated command format
     * 
     * @when after_wp_load
     * @alias bulk-create
     */
    public function bulk_create_hyphenated($args, $assoc_args)
    {
        return $this->_bulk_create_implementation($args, $assoc_args);
    }

    /**
     * Implementation for bulk user creation
     * 
     * @param array $args
     * @param array $assoc_args
     */
    private function _bulk_create_implementation($args, $assoc_args)
    {
        $count = intval($args[0]);
        
        if ($count <= 0) {
            $this->error("Count must be a positive integer.");
            return;
        }

        if ($count > 10000) {
            $this->error("Maximum bulk creation limit is 10,000 users per command.");
            return;
        }

        // Parse arguments
        $prefix = $assoc_args['prefix'] ?? 'testuser';
        $domain = $assoc_args['domain'] ?? 'example.com';
        $role = $assoc_args['role'] ?? 'subscriber';
        $batch_size = intval($assoc_args['batch-size'] ?? 50);
        $membership_probability = intval($assoc_args['membership-probability'] ?? 50);
        $dry_run = isset($assoc_args['dry-run']);
        $send_emails = isset($assoc_args['send-emails']);
        
        // Determine starting number
        $start_from = isset($assoc_args['start-from']) ? intval($assoc_args['start-from']) : null;
        if ($start_from === null) {
            // Auto-detect next available number
            $start_from = $this->get_next_available_user_number($prefix);
        }
        
        if ($start_from <= 0) {
            $this->error("Start-from number must be a positive integer.");
            return;
        }

        // Validate batch size
        if ($batch_size <= 0 || $batch_size > 500) {
            $this->error("Batch size must be between 1 and 500.");
            return;
        }

        // Validate membership probability
        if ($membership_probability < 0 || $membership_probability > 100) {
            $this->error("Membership probability must be between 0 and 100.");
            return;
        }

        // Parse membership IDs
        $membership_ids = [];
        if (!empty($assoc_args['memberships'])) {
            $membership_ids = array_map('intval', explode(',', $assoc_args['memberships']));
            
            // Validate memberships exist
            foreach ($membership_ids as $membership_id) {
                if (!$this->validate_membership_exists($membership_id)) {
                    $this->error("Membership ID {$membership_id} does not exist.");
                    return;
                }
            }
        }

        // Show summary
        $this->log("Bulk User Creation Summary:");
        $this->log("- Count: {$count}");
        $this->log("- Prefix: {$prefix}");
        $this->log("- Domain: {$domain}");
        $this->log("- Role: {$role}");
        $this->log("- Batch Size: {$batch_size}");
        $this->log("- Starting from: {$prefix}{$start_from}");
        
        if (!empty($membership_ids)) {
            $this->log("- Memberships: " . implode(', ', $membership_ids));
            $this->log("- Membership Probability: {$membership_probability}%");
        }
        
        if ($dry_run) {
            $this->log("- DRY RUN: No users will be created");
        }

        // Confirm unless dry run (no confirmation needed for bulk create)
        if (!$dry_run) {
            \WP_CLI::confirm("Are you sure you want to create {$count} users?");
        }

        // Track statistics
        $stats = [
            'total_requested' => $count,
            'created' => 0,
            'failed' => 0,
            'memberships_assigned' => 0,
            'start_time' => time(),
            'errors' => []
        ];

        // Create progress bar
        $progress = \WP_CLI\Utils\make_progress_bar("Creating {$count} users", $count);

        // Process in batches
        $batches = ceil($count / $batch_size);
        $current_user = 0;

        for ($batch = 0; $batch < $batches; $batch++) {
            $batch_start = $current_user;
            $batch_end = min($current_user + $batch_size, $count);
            $batch_count = $batch_end - $batch_start;

            $this->log("Processing batch " . ($batch + 1) . " of {$batches} ({$batch_count} users)");

            // Create users in this batch
            for ($i = $batch_start; $i < $batch_end; $i++) {
                $user_number = $start_from + $i;
                $username = $prefix . $user_number;
                $email = $prefix . $user_number . '@' . $domain;

                // Check if user already exists
                if (username_exists($username) || email_exists($email)) {
                    $stats['failed']++;
                    $stats['errors'][] = "User {$username} already exists";
                    $progress->tick();
                    continue;
                }

                if ($dry_run) {
                    // Just simulate the creation
                    $stats['created']++;
                    
                    // Simulate membership assignment
                    if (!empty($membership_ids) && rand(1, 100) <= $membership_probability) {
                        $stats['memberships_assigned']++;
                    }
                } else {
                    // Actually create the user
                    $user_data = [
                        'user_login' => $username,
                        'user_email' => $email,
                        'user_pass' => 'password',
                        'role' => $role,
                        'display_name' => ucfirst($prefix) . ' ' . $user_number,
                        'first_name' => ucfirst($prefix),
                        'last_name' => 'User' . $user_number,
                    ];

                    $user_id = UserHelper::create_user($user_data);

                    if (is_wp_error($user_id)) {
                        $stats['failed']++;
                        $stats['errors'][] = "Failed to create {$username}: " . $user_id->get_error_message();
                    } else {
                        $stats['created']++;

                        // Send email if requested
                        if ($send_emails) {
                            wp_send_new_user_notifications($user_id, 'user');
                        }

                        // Assign random membership if specified
                        if (!empty($membership_ids) && rand(1, 100) <= $membership_probability) {
                            $random_membership = $membership_ids[array_rand($membership_ids)];
                            $result = MembershipHelper::assign_membership_to_user($user_id, $random_membership);
                            
                            if ($result) {
                                $stats['memberships_assigned']++;
                            }
                        }
                    }
                }

                $progress->tick();

                // Add small delay to prevent overwhelming the server
                if (!$dry_run && $i % 10 === 0) {
                    usleep(100000); // 0.1 second delay every 10 users
                }
            }

            $current_user = $batch_end;

            // Memory cleanup between batches
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        $progress->finish();

        // Calculate execution time
        $execution_time = time() - $stats['start_time'];
        $minutes = floor($execution_time / 60);
        $seconds = $execution_time % 60;

        // Display final statistics
        $this->log("\n" . str_repeat('=', 50));
        $this->log("BULK USER CREATION COMPLETE");
        $this->log(str_repeat('=', 50));
        $this->log("Total Requested: {$stats['total_requested']}");
        $this->log("Successfully Created: {$stats['created']}");
        $this->log("Failed: {$stats['failed']}");
        $this->log("Memberships Assigned: {$stats['memberships_assigned']}");
        $this->log("Execution Time: {$minutes}m {$seconds}s");

        if ($stats['created'] > 0) {
            $rate = round($stats['created'] / max($execution_time, 1), 2);
            $this->log("Creation Rate: {$rate} users/second");
        }

        // Show errors if any
        if (!empty($stats['errors'])) {
            $this->log("\nErrors encountered:");
            foreach (array_slice($stats['errors'], 0, 10) as $error) {
                $this->log("- {$error}");
            }
            
            if (count($stats['errors']) > 10) {
                $remaining = count($stats['errors']) - 10;
                $this->log("... and {$remaining} more errors");
            }
        }

        // Final success/warning message
        if ($stats['failed'] === 0) {
            $this->success("All {$stats['created']} users created successfully!");
        } else {
            $this->warning("Created {$stats['created']} users with {$stats['failed']} failures. Check errors above.");
        }
    }

    /**
     * Create users from JSON file with avatar support
     *
     * ## OPTIONS
     *
     * [--json-file=<path>]
     * : Path to JSON file containing user data. Default: app/assets/users.json
     *
     * [--count=<count>]
     * : Number of users to create from JSON (random selection). If not specified, creates all users.
     *
     * [--domain=<domain>]
     * : Override email domain for generated users. Default: uses domain from JSON
     *
     * [--role=<role>]
     * : Role for the new users. Default: subscriber
     *
     * [--memberships=<membership_ids>]
     * : Comma-separated list of membership IDs to randomly assign
     *
     * [--membership-probability=<probability>]
     * : Probability (0-100) that each user gets a membership. Default: 50
     *
     * [--batch-size=<size>]
     * : Number of users to create per batch. Default: 50
     *
     * [--dry-run]
     * : Show what would be created without actually creating users
     *
     * [--send-emails]
     * : Send new user notification emails (not recommended for bulk)
     *
     * [--upload-avatars]
     * : Upload and set user avatars from the avatars directory
     *
     * [--skip-existing]
     * : Skip users that already exist instead of erroring
     *
     * [--confirm]
     * : Skip confirmation prompt
     *
     * [--randomize]
     * : Randomize the order of user creation instead of alphabetical order
     *
     * ## EXAMPLES
     *
     *     wp meco user bulk-create-from-json
     *     wp meco user bulk-create-from-json --count=50
     *     wp meco user bulk-create-from-json --memberships=9,10,11,12 --membership-probability=75
     *     wp meco user bulk-create-from-json --domain=mysite.com --upload-avatars
     *     wp meco user bulk-create-from-json --dry-run --count=10
     *     wp meco user bulk-create-from-json --randomize --count=50
     *
     * @when after_wp_load
     * @alias bulk-create-from-json
     */
    public function bulk_create_from_json($args, $assoc_args)
    {
        // Determine JSON file path
        $json_file = $assoc_args['json-file'] ?? dirname(dirname(__FILE__)) . '/assets/users.json';
        
        if (!file_exists($json_file)) {
            $this->error("JSON file not found: {$json_file}");
            return;
        }

        // Load and parse JSON
        $json_content = file_get_contents($json_file);
        $users_data = json_decode($json_content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error("Invalid JSON file: " . json_last_error_msg());
            return;
        }

        if (empty($users_data)) {
            $this->error("No user data found in JSON file.");
            return;
        }

        // Parse arguments
        $count = isset($assoc_args['count']) ? intval($assoc_args['count']) : count($users_data);
        $domain_override = $assoc_args['domain'] ?? null;
        $role = $assoc_args['role'] ?? 'subscriber';
        $batch_size = intval($assoc_args['batch-size'] ?? 50);
        $membership_probability = intval($assoc_args['membership-probability'] ?? 50);
        $dry_run = isset($assoc_args['dry-run']);
        $send_emails = isset($assoc_args['send-emails']);
        $upload_avatars = isset($assoc_args['upload-avatars']);
        $skip_existing = isset($assoc_args['skip-existing']);
        $skip_confirm = isset($assoc_args['confirm']);
        $randomize = isset($assoc_args['randomize']);

        // Validate count
        if ($count <= 0) {
            $this->error("Count must be a positive integer.");
            return;
        }

        if ($count > count($users_data)) {
            $this->warning("Requested count ({$count}) is greater than available users (" . count($users_data) . "). Creating all available users.");
            $count = count($users_data);
        }

        // Validate batch size
        if ($batch_size <= 0 || $batch_size > 500) {
            $this->error("Batch size must be between 1 and 500.");
            return;
        }

        // Validate membership probability
        if ($membership_probability < 0 || $membership_probability > 100) {
            $this->error("Membership probability must be between 0 and 100.");
            return;
        }

        // Parse membership IDs
        $membership_ids = [];
        if (!empty($assoc_args['memberships'])) {
            $membership_ids = array_map('intval', explode(',', $assoc_args['memberships']));
            
            // Validate memberships exist
            foreach ($membership_ids as $membership_id) {
                if (!$this->validate_membership_exists($membership_id)) {
                    $this->error("Membership ID {$membership_id} does not exist.");
                    return;
                }
            }
        }

        // Select users to create
        if ($count < count($users_data)) {
            // Randomly select users
            $random_indices = array_rand($users_data, $count);
            
            // Ensure $random_indices is an array (array_rand returns int if count is 1)
            if (!is_array($random_indices)) {
                $random_indices = [$random_indices];
            }
            
            $selected_users = [];
            foreach ($random_indices as $index) {
                $selected_users[] = $users_data[$index];
            }
        } else {
            // Use all users
            $selected_users = $users_data;
        }

        // Randomize order if requested
        if ($randomize) {
            shuffle($selected_users);
        }

        // Show summary
        $this->log("Bulk User Creation from JSON Summary:");
        $this->log("- JSON File: {$json_file}");
        $this->log("- Total Available Users: " . count($users_data));
        $this->log("- Users to Create: " . count($selected_users));
        $this->log("- Role: {$role}");
        $this->log("- Batch Size: {$batch_size}");
        
        if ($domain_override) {
            $this->log("- Email Domain Override: {$domain_override}");
        }
        
        if (!empty($membership_ids)) {
            $this->log("- Memberships: " . implode(', ', $membership_ids));
            $this->log("- Membership Probability: {$membership_probability}%");
        }

        if ($upload_avatars) {
            $avatars_dir = dirname(dirname(__FILE__)) . '/assets/avatars';
            if (!is_dir($avatars_dir)) {
                $this->warning("Avatars directory not found: {$avatars_dir}. Avatar upload disabled.");
                $upload_avatars = false;
            } else {
                $this->log("- Upload Avatars: Yes ({$avatars_dir})");
            }
        }

        if ($randomize) {
            $this->log("- Randomize Order: Yes (users will be created in random order)");
        }
        
        if ($dry_run) {
            $this->log("- DRY RUN: No users will be created");
        }

        // Confirm unless dry run or --confirm flag is used
        if (!$dry_run && !$skip_confirm) {
            \WP_CLI::confirm("Are you sure you want to create " . count($selected_users) . " users?");
        }

        // Track statistics
        $stats = [
            'total_requested' => count($selected_users),
            'created' => 0,
            'failed' => 0,
            'skipped' => 0,
            'memberships_assigned' => 0,
            'avatars_uploaded' => 0,
            'start_time' => time(),
            'errors' => []
        ];

        // Create progress bar
        $progress = \WP_CLI\Utils\make_progress_bar("Creating " . count($selected_users) . " users", count($selected_users));

        // Process in batches
        $batches = ceil(count($selected_users) / $batch_size);
        $current_user = 0;

        for ($batch = 0; $batch < $batches; $batch++) {
            $batch_start = $current_user;
            $batch_end = min($current_user + $batch_size, count($selected_users));
            $batch_count = $batch_end - $batch_start;

            $this->log("Processing batch " . ($batch + 1) . " of {$batches} ({$batch_count} users)");

            // Create users in this batch
            for ($i = $batch_start; $i < $batch_end; $i++) {
                $user_data = $selected_users[$i];
                
                // Build email
                $email = $domain_override ? 
                    $user_data['username'] . '@' . $domain_override : 
                    $user_data['email'];

                // Check if user already exists
                if (username_exists($user_data['username']) || email_exists($email)) {
                    if ($skip_existing) {
                        $stats['skipped']++;
                        $progress->tick();
                        continue;
                    } else {
                        $stats['failed']++;
                        $stats['errors'][] = "User {$user_data['username']} already exists";
                        $progress->tick();
                        continue;
                    }
                }

                if ($dry_run) {
                    // Just simulate the creation
                    $stats['created']++;
                    
                    // Simulate membership assignment
                    if (!empty($membership_ids) && rand(1, 100) <= $membership_probability) {
                        $stats['memberships_assigned']++;
                    }

                    // Simulate avatar upload
                    if ($upload_avatars) {
                        $stats['avatars_uploaded']++;
                    }
                } else {
                    // Actually create the user
                    $wp_user_data = [
                        'user_login' => $user_data['username'],
                        'user_email' => $email,
                        'user_pass' => 'password',
                        'role' => $role,
                        'display_name' => $user_data['first_name'] . ' ' . $user_data['last_name'],
                        'first_name' => $user_data['first_name'],
                        'last_name' => $user_data['last_name'],
                    ];

                    $user_id = UserHelper::create_user($wp_user_data);

                    if (is_wp_error($user_id)) {
                        $stats['failed']++;
                        $stats['errors'][] = "Failed to create {$user_data['username']}: " . $user_id->get_error_message();
                    } else {
                        $stats['created']++;

                        // Send email if requested
                        if ($send_emails) {
                            wp_send_new_user_notifications($user_id, 'user');
                        }

                        // Assign random membership if specified
                        if (!empty($membership_ids) && rand(1, 100) <= $membership_probability) {
                            $random_membership = $membership_ids[array_rand($membership_ids)];
                            $result = MembershipHelper::assign_membership_to_user($user_id, $random_membership);
                            
                            if ($result) {
                                $stats['memberships_assigned']++;
                            }
                        }

                        // Upload avatar if specified
                        if ($upload_avatars && !empty($user_data['avatar'])) {
                            $avatar_path = dirname(dirname(__FILE__)) . '/assets/avatars/' . $user_data['avatar'];
                            if (file_exists($avatar_path)) {
                                if ($this->upload_user_avatar($user_id, $avatar_path)) {
                                    $stats['avatars_uploaded']++;
                                }
                            }
                        }

                        // Save city if provided
                        if (!empty($user_data['city'])) {
                            update_user_meta($user_id, 'meco-address-city', sanitize_text_field($user_data['city']));
                        }

                        // Save country if provided
                        if (!empty($user_data['country'])) {
                            update_user_meta($user_id, 'meco-address-country', sanitize_text_field($user_data['country']));
                        }

                        // Save bio if provided
                        if (!empty($user_data['bio'])) {
                            update_user_meta($user_id, 'description', sanitize_textarea_field($user_data['bio']));
                        }

                        // Save social media links if provided
                        $social_fields = ['linkedin', 'facebook', 'twitter', 'bluesky', 'instagram', 'youtube', 'tiktok'];
                        foreach ($social_fields as $field) {
                            if (!empty($user_data[$field])) {
                                update_user_meta($user_id, 'mcdir_' . $field, esc_url_raw($user_data[$field]));
                            }
                        }

                        // Save website if provided
                        if (!empty($user_data['website'])) {
                            wp_update_user([
                                'ID' => $user_id,
                                'user_url' => esc_url_raw($user_data['website'])
                            ]);
                        }
                    }
                }

                $progress->tick();

                // Add small delay to prevent overwhelming the server
                if (!$dry_run && $i % 10 === 0) {
                    usleep(100000); // 0.1 second delay every 10 users
                }
            }

            $current_user = $batch_end;

            // Memory cleanup between batches
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        $progress->finish();

        // Display final statistics
        $this->show_creation_stats($stats, $dry_run);
    }

    /**
     * Update existing users with profile data from JSON
     *
     * ## OPTIONS
     *
     * [--json-file=<path>]
     * : Path to JSON file containing user data. Default: app/assets/users.json
     *
     * [--match-by=<field>]
     * : Field to match users by. Options: username, email. Default: username
     *
     * [--upload-avatars]
     * : Upload and set user avatars from the avatars directory
     *
     * [--dry-run]
     * : Show what would be updated without actually updating users
     *
     * [--confirm]
     * : Skip confirmation prompt
     *
     * ## EXAMPLES
     *
     *     wp meco user update-from-json
     *     wp meco user update-from-json --match-by=email
     *     wp meco user update-from-json --upload-avatars
     *     wp meco user update-from-json --dry-run
     *
     * @when after_wp_load
     * @alias update-from-json
     */
    public function update_from_json($args, $assoc_args)
    {
        // Determine JSON file path
        $json_file = $assoc_args['json-file'] ?? dirname(dirname(__FILE__)) . '/assets/users.json';
        
        if (!file_exists($json_file)) {
            $this->error("JSON file not found: {$json_file}");
            return;
        }

        // Load and parse JSON
        $json_content = file_get_contents($json_file);
        $users_data = json_decode($json_content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error("Invalid JSON file: " . json_last_error_msg());
            return;
        }

        if (empty($users_data)) {
            $this->error("No user data found in JSON file.");
            return;
        }

        // Parse arguments
        $match_by = $assoc_args['match-by'] ?? 'username';
        $upload_avatars = isset($assoc_args['upload-avatars']);
        $dry_run = isset($assoc_args['dry-run']);
        $skip_confirm = isset($assoc_args['confirm']);

        // Validate match_by
        if (!in_array($match_by, ['username', 'email'])) {
            $this->error("Invalid match-by value. Must be 'username' or 'email'.");
            return;
        }

        // Show summary
        $this->log("Update Existing Users from JSON:");
        $this->log("- JSON File: {$json_file}");
        $this->log("- Users in JSON: " . count($users_data));
        $this->log("- Match By: {$match_by}");
        
        if ($upload_avatars) {
            $avatars_dir = dirname(dirname(__FILE__)) . '/assets/avatars';
            if (!is_dir($avatars_dir)) {
                $this->warning("Avatars directory not found: {$avatars_dir}. Avatar upload disabled.");
                $upload_avatars = false;
            } else {
                $this->log("- Upload Avatars: Yes ({$avatars_dir})");
            }
        }
        
        if ($dry_run) {
            $this->log("- DRY RUN: No users will be updated");
        }

        // Confirm unless dry run or --confirm flag is used
        if (!$dry_run && !$skip_confirm) {
            \WP_CLI::confirm("Are you sure you want to update user profile data for matching users?");
        }

        // Track statistics
        $stats = [
            'total' => count($users_data),
            'updated' => 0,
            'not_found' => 0,
            'avatars_uploaded' => 0,
            'start_time' => time(),
            'errors' => []
        ];

        // Create progress bar
        $progress = \WP_CLI\Utils\make_progress_bar("Updating users", count($users_data));

        // Process each user
        foreach ($users_data as $user_data) {
            $identifier = $match_by === 'email' ? $user_data['email'] : $user_data['username'];
            
            // Find user
            if ($match_by === 'email') {
                $user = get_user_by('email', $user_data['email']);
            } else {
                $user = get_user_by('login', $user_data['username']);
            }

            if (!$user) {
                $stats['not_found']++;
                $progress->tick();
                continue;
            }

            if (!$dry_run) {
                $user_id = $user->ID;

                // Save city if provided
                if (!empty($user_data['city'])) {
                    update_user_meta($user_id, 'meco-address-city', sanitize_text_field($user_data['city']));
                }

                // Save country if provided
                if (!empty($user_data['country'])) {
                    update_user_meta($user_id, 'meco-address-country', sanitize_text_field($user_data['country']));
                }

                // Save bio if provided
                if (!empty($user_data['bio'])) {
                    update_user_meta($user_id, 'description', sanitize_textarea_field($user_data['bio']));
                }

                // Save social media links if provided
                $social_fields = ['linkedin', 'facebook', 'twitter', 'bluesky', 'instagram', 'youtube', 'tiktok'];
                foreach ($social_fields as $field) {
                    if (!empty($user_data[$field])) {
                        update_user_meta($user_id, 'mcdir_' . $field, esc_url_raw($user_data[$field]));
                    }
                }

                // Save website if provided
                if (!empty($user_data['website'])) {
                    wp_update_user([
                        'ID' => $user_id,
                        'user_url' => esc_url_raw($user_data['website'])
                    ]);
                }

                // Upload avatar if specified and not already exists
                if ($upload_avatars && !empty($user_data['avatar'])) {
                    $avatar_path = dirname(dirname(__FILE__)) . '/assets/avatars/' . $user_data['avatar'];
                    if (file_exists($avatar_path)) {
                        // Check if user already has avatar
                        $has_avatar = \membercore\directory\models\ProfileImage::has_profile_photo($user_id);
                        if (!$has_avatar) {
                            if ($this->upload_user_avatar($user_id, $avatar_path)) {
                                $stats['avatars_uploaded']++;
                            }
                        }
                    }
                }
            }

            $stats['updated']++;
            $progress->tick();
        }

        $progress->finish();

        // Calculate execution time
        $execution_time = time() - $stats['start_time'];
        $minutes = floor($execution_time / 60);
        $seconds = $execution_time % 60;

        // Display final statistics
        $this->log("\n" . str_repeat('=', 60));
        $this->log("USER UPDATE COMPLETE");
        $this->log(str_repeat('=', 60));
        $this->log("Total Users in JSON: {$stats['total']}");
        $this->log("Users Updated: {$stats['updated']}");
        $this->log("Users Not Found: {$stats['not_found']}");
        
        if ($upload_avatars) {
            $this->log("Avatars Uploaded: {$stats['avatars_uploaded']}");
        }
        
        $this->log("Execution Time: {$minutes}m {$seconds}s");

        // Final message
        if ($dry_run) {
            $this->success("DRY RUN: Would update {$stats['updated']} users!");
        } else {
            $this->success("Successfully updated {$stats['updated']} users!");
        }
    }

    /**
     * List users with their membership information
     *
     * ## OPTIONS
     *
     * [--role=<role>]
     * : Filter by user role
     *
     * [--membership=<membership_id>]
     * : Filter by membership ID
     *
     * [--format=<format>]
     * : Output format. Options: table, csv, json, yaml. Default: table
     *
     * [--fields=<fields>]
     * : Fields to display. Default: ID,user_login,user_email,roles,memberships
     *
     * [--limit=<limit>]
     * : Limit number of users returned. Default: 50
     *
     * ## EXAMPLES
     *
     *     wp meco user list
     *     wp meco user list --role=subscriber
     *     wp meco user list --membership=123
     *     wp meco user list --format=json --fields=ID,user_login,user_email
     *
     * @when after_wp_load
     */
    public function list($args, $assoc_args)
    {
        $query_args = [
            'number' => $assoc_args['limit'] ?? 50,
        ];

        // Filter by role if specified
        if (isset($assoc_args['role'])) {
            $query_args['role'] = $assoc_args['role'];
        }

        $users = get_users($query_args);

        if (empty($users)) {
            \WP_CLI::warning('No users found.');
            return;
        }

        // Filter by membership if specified
        if (isset($assoc_args['membership'])) {
            $membership_id = intval($assoc_args['membership']);
            $users = array_filter($users, function($user) use ($membership_id) {
                return MembershipHelper::user_has_active_membership($user->ID, $membership_id);
            });
        }

        // Prepare user data for display
        $user_data = [];
        foreach ($users as $user) {
            $memberships = MembershipHelper::get_user_active_memberships($user->ID);
            $membership_titles = array_column($memberships, 'title');

            $user_data[] = [
                'ID' => $user->ID,
                'user_login' => $user->user_login,
                'user_email' => $user->user_email,
                'display_name' => $user->display_name,
                'roles' => implode(', ', $user->roles),
                'memberships' => implode(', ', $membership_titles),
                'registered' => $user->user_registered,
            ];
        }

        $format = $assoc_args['format'] ?? 'table';
        $fields = $assoc_args['fields'] ?? 'ID,user_login,user_email,roles,memberships';

        \WP_CLI\Utils\format_items($format, $user_data, explode(',', $fields));
    }

    /**
     * Delete a user
     *
     * ## OPTIONS
     *
     * <user_id>
     * : User ID, email, or username
     *
     * [--reassign=<user_id>]
     * : User ID to reassign posts to
     *
     * [--yes]
     * : Skip confirmation prompt
     *
     * ## EXAMPLES
     *
     *     wp meco user delete 123
     *     wp meco user delete john_doe --reassign=1
     *     wp meco user delete admin@example.com --yes
     *
     * @when after_wp_load
     */
    public function delete($args, $assoc_args)
    {
        $user_identifier = $args[0];

        // Get user
        $user = UserHelper::get_user($user_identifier);
        if (!$user) {
            $this->error("User '{$user_identifier}' not found.");
            return;
        }

        // Don't allow deletion of current user
        if ($user->ID === get_current_user_id()) {
            $this->error("You cannot delete the current user.");
            return;
        }

        // Confirm deletion
        if (!isset($assoc_args['yes'])) {
            \WP_CLI::confirm("Are you sure you want to delete user '{$user->user_login}'?");
        }

        $reassign_user_id = null;
        if (isset($assoc_args['reassign'])) {
            $reassign_user_id = intval($assoc_args['reassign']);
            if (!UserHelper::user_exists($reassign_user_id)) {
                $this->error("Reassign user ID {$reassign_user_id} does not exist.");
                return;
            }
        }

        // Delete user
        $result = UserHelper::delete_user($user->ID, $reassign_user_id);

        if ($result) {
            $this->success("Successfully deleted user '{$user->user_login}'.");
        } else {
            $this->error("Failed to delete user '{$user->user_login}'.");
        }
    }

    /**
     * Show user's membership information
     *
     * @param int $user_id
     */
    protected function show_user_memberships(int $user_id): void
    {
        $memberships = MembershipHelper::get_user_active_memberships($user_id);

        if (empty($memberships)) {
            \WP_CLI::line("Active Memberships: None");
            return;
        }

        \WP_CLI::line("Active Memberships:");
        foreach ($memberships as $membership) {
            \WP_CLI::line("  - {$membership['title']} (ID: {$membership['id']})");
        }
    }

    /**
     * Show detailed membership information for user
     *
     * @param int $user_id
     */
    protected function show_detailed_membership_info(int $user_id): void
    {
        if (!class_exists('\MecoDb')) {
            \WP_CLI::warning("Cannot show detailed membership info: MemberCore not fully loaded.");
            return;
        }

        global $wpdb;
        $meco_db = new \MecoDb();

        $transactions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.*, p.post_title 
                 FROM {$meco_db->transactions} t
                 LEFT JOIN {$wpdb->posts} p ON t.product_id = p.ID
                 WHERE t.user_id = %d
                 AND t.status IN ('complete', 'confirmed')
                 AND (t.expires_at >= %s OR t.expires_at = '0000-00-00 00:00:00')
                 ORDER BY t.created_at DESC",
                $user_id,
                current_time('mysql')
            )
        );

        if (empty($transactions)) {
            \WP_CLI::line("\nDetailed Membership Information: None");
            return;
        }

        \WP_CLI::line("\nDetailed Membership Information:");
        foreach ($transactions as $transaction) {
            $expires = $transaction->expires_at === '0000-00-00 00:00:00' ? 'Never' : $transaction->expires_at;
            \WP_CLI::line("  Membership: {$transaction->post_title}");
            \WP_CLI::line("    Transaction ID: {$transaction->id}");
            \WP_CLI::line("    Status: {$transaction->status}");
            \WP_CLI::line("    Amount: \${$transaction->amount}");
            \WP_CLI::line("    Created: {$transaction->created_at}");
            \WP_CLI::line("    Expires: {$expires}");
            \WP_CLI::line("    Gateway: {$transaction->gateway}");
            \WP_CLI::line("");
        }
    }

    /**
     * Show user's transaction history
     *
     * @param int $user_id
     */
    protected function show_user_transactions(int $user_id): void
    {
        if (!class_exists('\MecoDb')) {
            \WP_CLI::warning("Cannot show transactions: MemberCore not fully loaded.");
            return;
        }

        global $wpdb;
        $meco_db = new \MecoDb();

        $transactions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.*, p.post_title 
                 FROM {$meco_db->transactions} t
                 LEFT JOIN {$wpdb->posts} p ON t.product_id = p.ID
                 WHERE t.user_id = %d
                 ORDER BY t.created_at DESC
                 LIMIT 10",
                $user_id
            )
        );

        if (empty($transactions)) {
            \WP_CLI::line("\nTransaction History: None");
            return;
        }

        \WP_CLI::line("\nRecent Transactions:");
        \WP_CLI::line("ID | Product | Status | Amount | Created | Expires");
        \WP_CLI::line(str_repeat('-', 80));

        foreach ($transactions as $transaction) {
            $expires = $transaction->expires_at === '0000-00-00 00:00:00' ? 'Never' : $transaction->expires_at;
            \WP_CLI::line("{$transaction->id} | {$transaction->post_title} | {$transaction->status} | \${$transaction->amount} | {$transaction->created_at} | {$expires}");
        }
    }

    /**
     * Validate that a membership exists
     *
     * @param int $membership_id
     * @return bool
     */
    protected function validate_membership_exists(int $membership_id): bool
    {
        $membership = get_post($membership_id);
        return $membership && $membership->post_type === 'membercoreproduct' && $membership->post_status === 'publish';
    }

    /**
     * Get the next available user number for a given prefix
     *
     * @param string $prefix
     * @return int
     */
    protected function get_next_available_user_number(string $prefix): int
    {
        global $wpdb;
        
        // Query for usernames that match the prefix pattern
        $pattern = $prefix . '%';
        $usernames = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT user_login FROM {$wpdb->users} WHERE user_login LIKE %s",
                $pattern
            )
        );
        
        if (empty($usernames)) {
            return 1; // No users with this prefix exist, start from 1
        }
        
        $max_number = 0;
        $prefix_length = strlen($prefix);
        
        foreach ($usernames as $username) {
            // Extract the number part after the prefix
            $number_part = substr($username, $prefix_length);
            
            // Check if it's all digits
            if (ctype_digit($number_part)) {
                $number = intval($number_part);
                if ($number > $max_number) {
                    $max_number = $number;
                }
            }
        }
        
        return $max_number + 1;
    }

    /**
     * Upload and set user avatar using the ProfileImage system
     *
     * @param int $user_id
     * @param string $avatar_path
     * @return bool
     */
    protected function upload_user_avatar(int $user_id, string $avatar_path): bool
    {
        if (!file_exists($avatar_path)) {
            return false;
        }

        try {
            // Get WordPress uploads directory info
            $upload_dir = wp_upload_dir();
            if ($upload_dir['error']) {
                return false;
            }

            // Create a unique filename to avoid conflicts
            $file_info = pathinfo($avatar_path);
            $filename = sanitize_file_name($file_info['filename'] . '_' . $user_id . '.' . $file_info['extension']);
            $destination = $upload_dir['path'] . '/' . $filename;

            // Copy the file to uploads directory
            if (!copy($avatar_path, $destination)) {
                return false;
            }

            // Create the uploaded file info
            $uploaded_file = [
                'file' => $destination,
                'url' => $upload_dir['url'] . '/' . $filename,
                'type' => wp_check_filetype($filename)['type']
            ];

            // Check if ProfileImage class is available
            if (!class_exists('membercore\directory\models\ProfileImage')) {
                return false;
            }

            // Remove any existing profile image for this user
            $existing_image = \membercore\directory\models\ProfileImage::has_profile_photo($user_id);
            if ($existing_image) {
                $existing_image->destroy();
            }

            // Create new ProfileImage record with proper timestamps
            $profile_image = new \membercore\directory\models\ProfileImage([
                'user_id' => $user_id,
                'url' => $uploaded_file['url'],
                'type' => 'avatar',
                'status' => 'approved', // Auto-approve CLI uploaded avatars
                'size' => filesize($uploaded_file['file'])
            ]);

            $result = $profile_image->store(false); // Skip validation for CLI uploads
            
            if (is_wp_error($result)) {
                // Clean up uploaded file if ProfileImage creation failed
                if (file_exists($uploaded_file['file'])) {
                    unlink($uploaded_file['file']);
                }
                return false;
            }
            
            return $result > 0;

        } catch (\Exception $e) {
            // Log error but don't fail the entire process
            error_log("Avatar upload failed for user {$user_id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Display creation statistics
     *
     * @param array $stats
     * @param bool $dry_run
     */
    protected function show_creation_stats(array $stats, bool $dry_run): void
    {
        // Calculate execution time
        $execution_time = time() - $stats['start_time'];
        $minutes = floor($execution_time / 60);
        $seconds = $execution_time % 60;

        // Display final statistics
        $this->log("\n" . str_repeat('=', 60));
        $this->log("BULK USER CREATION FROM JSON COMPLETE");
        $this->log(str_repeat('=', 60));
        $this->log("Total Requested: {$stats['total_requested']}");
        $this->log("Successfully Created: {$stats['created']}");
        $this->log("Failed: {$stats['failed']}");
        
        if (isset($stats['skipped']) && $stats['skipped'] > 0) {
            $this->log("Skipped (Already Exist): {$stats['skipped']}");
        }
        
        $this->log("Memberships Assigned: {$stats['memberships_assigned']}");
        
        if (isset($stats['avatars_uploaded']) && $stats['avatars_uploaded'] > 0) {
            $this->log("Avatars Uploaded: {$stats['avatars_uploaded']}");
        }
        
        $this->log("Execution Time: {$minutes}m {$seconds}s");

        if ($stats['created'] > 0) {
            $rate = round($stats['created'] / max($execution_time, 1), 2);
            $this->log("Creation Rate: {$rate} users/second");
        }

        // Show errors if any
        if (!empty($stats['errors'])) {
            $this->log("\nErrors encountered:");
            foreach (array_slice($stats['errors'], 0, 10) as $error) {
                $this->log("- {$error}");
            }
            
            if (count($stats['errors']) > 10) {
                $remaining = count($stats['errors']) - 10;
                $this->log("... and {$remaining} more errors");
            }
        }

        // Final success/warning message
        if ($stats['failed'] === 0) {
            if ($dry_run) {
                $this->success("DRY RUN: Would create {$stats['created']} users successfully!");
            } else {
                $this->success("All {$stats['created']} users created successfully!");
            }
        } else {
            if ($dry_run) {
                $this->warning("DRY RUN: Would create {$stats['created']} users with {$stats['failed']} failures. Check errors above.");
            } else {
                $this->warning("Created {$stats['created']} users with {$stats['failed']} failures. Check errors above.");
            }
        }
    }
} 