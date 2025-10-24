<?php

namespace membercore\cli\commands;

if (!defined('ABSPATH')) {
    die('You are not allowed to call this page directly.');
}

/**
 * WP-CLI commands for directory enrollment management
 */
class Directory
{
    /**
     * Enroll users in a specific directory
     *
     * ## OPTIONS
     *
     * <directory_id>
     * : The ID of the directory to enroll users in
     *
     * [--dry-run]
     * : Preview what would happen without making changes
     *
     * ## EXAMPLES
     *
     *     wp meco directory enroll 123
     *     wp meco directory enroll 123 --dry-run
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function enroll($args, $assoc_args)
    {
        $this->ensure_profiles_plugin_loaded();

        if (empty($args[0])) {
            \WP_CLI::error('Please provide a directory ID');
        }

        $directory_id = intval($args[0]);
        $dry_run      = isset($assoc_args['dry-run']);

        $directory = get_post($directory_id);
        if (!$directory || $directory->post_type !== \membercore\directory\models\Directory::CPT) {
            \WP_CLI::error("Directory with ID {$directory_id} not found");
        }

        \WP_CLI::log("Processing directory: {$directory->post_title} (ID: {$directory_id})");

        if ($dry_run) {
            \WP_CLI::log('DRY RUN MODE - No changes will be made');
        }

        $job = new \membercore\directory\jobs\DirectoryEnrollmentJob();

        if ($dry_run) {
            // Simulate the job
            $directory_model = new \membercore\directory\models\Directory($directory_id);
            $users           = \membercore\directory\helpers\EnrollmentHelper::get_eligible_users($directory_model);

            \WP_CLI::log('Found ' . count($users) . ' eligible users');

            foreach ($users as $user) {
                $is_enrolled = \membercore\directory\models\Enrollment::is_enrolled($user->ID, $directory_id);
                if (!$is_enrolled) {
                    \WP_CLI::log("Would enroll: {$user->user_login} (ID: {$user->ID})");
                } else {
                    \WP_CLI::log("Already enrolled: {$user->user_login} (ID: {$user->ID})");
                }
            }
        } else {
            try {
                \WP_CLI::log('Setting up enrollment job...');
                $job->setAttribute('args', json_encode(['directory_id' => $directory_id]));

                \WP_CLI::log('Running enrollment job...');
                $job->run();

                \WP_CLI::success('Directory enrollment completed');
            } catch (\Exception $e) {
                \WP_CLI::error('Enrollment failed: ' . $e->getMessage());
                \WP_CLI::log('Error details: ' . $e->getTraceAsString());
            }
        }
    }

    /**
     * Sync all directories
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Preview what would happen without making changes
     *
     * ## EXAMPLES
     *
     *     wp meco directory sync-all
     *     wp meco directory sync-all --dry-run
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function sync_all($args, $assoc_args)
    {
        $this->ensure_profiles_plugin_loaded();

        $dry_run = isset($assoc_args['dry-run']);

        $directories = \membercore\directory\models\Directory::get_all();

        if (empty($directories)) {
            \WP_CLI::log('No published directories found');
            return;
        }

        \WP_CLI::log('Found ' . count($directories) . ' directories to sync');

        if ($dry_run) {
            \WP_CLI::log('DRY RUN MODE - No changes will be made');
        }

        foreach ($directories as $directory) {
            \WP_CLI::log("Syncing directory: {$directory->post_title} (ID: {$directory->ID})");

            if ($dry_run) {
                $this->preview_sync($directory->ID);
            } else {
                $this->sync_directory($directory->ID);
            }
        }

        \WP_CLI::success('Directory sync completed');
    }

    /**
     * Sync a specific directory
     *
     * ## OPTIONS
     *
     * <directory_id>
     * : The ID of the directory to sync
     *
     * [--dry-run]
     * : Preview what would happen without making changes
     *
     * ## EXAMPLES
     *
     *     wp meco directory sync 123
     *     wp meco directory sync 123 --dry-run
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function sync($args, $assoc_args)
    {
        $this->ensure_profiles_plugin_loaded();

        if (empty($args[0])) {
            \WP_CLI::error('Please provide a directory ID');
        }

        $directory_id = intval($args[0]);
        $dry_run      = isset($assoc_args['dry-run']);

        $directory = get_post($directory_id);
        if (!$directory || $directory->post_type !== \membercore\directory\models\Directory::CPT) {
            \WP_CLI::error("Directory with ID {$directory_id} not found");
        }

        \WP_CLI::log("Syncing directory: {$directory->post_title} (ID: {$directory_id})");

        if ($dry_run) {
            \WP_CLI::log('DRY RUN MODE - No changes will be made');
            $this->preview_sync($directory_id);
        } else {
            $this->sync_directory($directory_id);
        }

        \WP_CLI::success('Directory sync completed');
    }

    /**
     * Unenroll all users from directories
     *
     * ## OPTIONS
     *
     * [<directory_id>]
     * : The ID of the directory to unenroll users from. If not provided, unenrolls from all directories.
     *
     * [--dry-run]
     * : Preview what would happen without making changes
     *
     * [--confirm]
     * : Skip confirmation prompt (use with caution)
     *
     * ## EXAMPLES
     *
     *     wp meco directory unenroll-all 123
     *     wp meco directory unenroll-all 123 --dry-run
     *     wp meco directory unenroll-all --confirm
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function unenroll_all($args, $assoc_args)
    {
        $this->ensure_profiles_plugin_loaded();

        $directory_id = isset($args[0]) ? intval($args[0]) : null;
        $dry_run      = isset($assoc_args['dry-run']);
        $skip_confirm = isset($assoc_args['confirm']);

        if ($directory_id) {
            // Unenroll all users from a specific directory
            $directory = get_post($directory_id);
            if (!$directory || $directory->post_type !== \membercore\directory\models\Directory::CPT) {
                \WP_CLI::error("Directory with ID {$directory_id} not found");
            }

            \WP_CLI::log("Processing directory: {$directory->post_title} (ID: {$directory_id})");

            // Get current enrollments
            $enrollments = \membercore\directory\models\Enrollment::get_by_directory($directory_id, true);

            if (empty($enrollments)) {
                \WP_CLI::log('No active enrollments found for this directory');
                return;
            }

            \WP_CLI::log('Found ' . count($enrollments) . ' active enrollments');

            if ($dry_run) {
                \WP_CLI::log('DRY RUN MODE - No changes will be made');
                foreach ($enrollments as $enrollment) {
                    $user = get_user_by('ID', $enrollment->user_id);
                    if ($user) {
                        \WP_CLI::log("Would unenroll: {$user->user_login} (ID: {$user->ID})");
                    }
                }
                return;
            }

            // Confirm action
            if (!$skip_confirm) {
                \WP_CLI::confirm(
                    'Are you sure you want to unenroll all ' . count($enrollments) .
                    " users from directory '{$directory->post_title}'? This action cannot be undone."
                );
            }

            // Process unenrollments
            $success_count = 0;
            $error_count   = 0;

            foreach ($enrollments as $enrollment) {
                $user = get_user_by('ID', $enrollment->user_id);
                if ($user) {
                    $result = \membercore\directory\models\Enrollment::unenroll_user($enrollment->user_id, $directory_id);
                    if ($result) {
                        \WP_CLI::log("✓ Unenrolled: {$user->user_login} (ID: {$user->ID})");
                        ++$success_count;
                    } else {
                        \WP_CLI::log("✗ Failed to unenroll: {$user->user_login} (ID: {$user->ID})");
                        ++$error_count;
                    }
                } else {
                    \WP_CLI::log("✗ User not found for enrollment ID: {$enrollment->id}");
                    ++$error_count;
                }
            }

            \WP_CLI::success("Unenrolled {$success_count} users from directory '{$directory->post_title}'");
            if ($error_count > 0) {
                \WP_CLI::warning("Failed to unenroll {$error_count} users");
            }
        } else {
            // Unenroll all users from all directories
            $directories = \membercore\directory\models\Directory::get_all();

            if (empty($directories)) {
                \WP_CLI::log('No published directories found');
                return;
            }

            \WP_CLI::log('Found ' . count($directories) . ' directories');

            // Count total enrollments
            $total_enrollments = 0;
            foreach ($directories as $directory) {
                $enrollments        = \membercore\directory\models\Enrollment::get_by_directory($directory->ID, true);
                $total_enrollments += count($enrollments);
            }

            if ($total_enrollments === 0) {
                \WP_CLI::log('No active enrollments found across all directories');
                return;
            }

            \WP_CLI::log("Found {$total_enrollments} total active enrollments across all directories");

            if ($dry_run) {
                \WP_CLI::log('DRY RUN MODE - No changes will be made');
                foreach ($directories as $directory) {
                    $enrollments = \membercore\directory\models\Enrollment::get_by_directory($directory->ID, true);
                    if (!empty($enrollments)) {
                        \WP_CLI::log("Directory '{$directory->post_title}' (ID: {$directory->ID}): " . count($enrollments) . ' enrollments');
                        foreach ($enrollments as $enrollment) {
                            $user = get_user_by('ID', $enrollment->user_id);
                            if ($user) {
                                \WP_CLI::log("  Would unenroll: {$user->user_login} (ID: {$user->ID})");
                            }
                        }
                    }
                }
                return;
            }

            // Confirm action
            if (!$skip_confirm) {
                \WP_CLI::confirm(
                    "Are you sure you want to unenroll ALL {$total_enrollments} users from ALL directories? " .
                    'This action cannot be undone and will completely clear all directory enrollments.'
                );
            }

            // Process unenrollments for all directories
            $total_success = 0;
            $total_errors  = 0;

            foreach ($directories as $directory) {
                $enrollments = \membercore\directory\models\Enrollment::get_by_directory($directory->ID, true);

                if (empty($enrollments)) {
                    continue;
                }

                \WP_CLI::log("Processing directory: {$directory->post_title} (ID: {$directory->ID}) - " . count($enrollments) . ' enrollments');

                foreach ($enrollments as $enrollment) {
                    $user = get_user_by('ID', $enrollment->user_id);
                    if ($user) {
                        $result = \membercore\directory\models\Enrollment::unenroll_user($enrollment->user_id, $directory->ID);
                        if ($result) {
                            \WP_CLI::log("  ✓ Unenrolled: {$user->user_login} (ID: {$user->ID})");
                            ++$total_success;
                        } else {
                            \WP_CLI::log("  ✗ Failed to unenroll: {$user->user_login} (ID: {$user->ID})");
                            ++$total_errors;
                        }
                    } else {
                        \WP_CLI::log("  ✗ User not found for enrollment ID: {$enrollment->id}");
                        ++$total_errors;
                    }
                }
            }

            \WP_CLI::success("Unenrolled {$total_success} users from all directories");
            if ($total_errors > 0) {
                \WP_CLI::warning("Failed to unenroll {$total_errors} users");
            }
        }
    }

    /**
     * Show enrollment statistics for all directories
     *
     * ## EXAMPLES
     *
     *     wp meco directory stats
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function stats($args, $assoc_args)
    {
        $this->ensure_profiles_plugin_loaded();

        $directories = \membercore\directory\models\Directory::get_all();

        if (empty($directories)) {
            \WP_CLI::log('No published directories found');
            return;
        }

        $table_data = [];

        foreach ($directories as $directory) {
            $total_enrolled    = \membercore\directory\models\Enrollment::get_count([
                'directory_id' => $directory->ID,
                'is_active'    => 1,
            ]);
            $last_sync         = $directory->last_sync;
            $enrollment_status = $directory->enrollment_status;

            $table_data[] = [
                'ID'        => $directory->ID,
                'Title'     => $directory->post_title,
                'Enrolled'  => $total_enrolled,
                'Status'    => $enrollment_status ?: 'N/A',
                'Last Sync' => $last_sync ?: 'Never',
            ];
        }

        \WP_CLI\Utils\format_items('table', $table_data, ['ID', 'Title', 'Enrolled', 'Status', 'Last Sync']);
    }

    /**
     * List users enrolled in a specific directory
     *
     * ## OPTIONS
     *
     * <directory_id>
     * : The ID of the directory to list users for
     *
     * [--status=<status>]
     * : Filter by enrollment status (active, inactive, all)
     * ---
     * default: active
     * options:
     *   - active
     *   - inactive
     *   - all
     * ---
     *
     * [--format=<format>]
     * : Output format (table, csv, json)
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp meco directory users 110
     *     wp meco directory users 110 --status=all
     *     wp meco directory users 110 --format=json
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function users($args, $assoc_args)
    {
        $this->ensure_profiles_plugin_loaded();

        if (empty($args[0])) {
            \WP_CLI::error('Please provide a directory ID');
        }

        $directory_id  = intval($args[0]);
        $status_filter = isset($assoc_args['status']) ? $assoc_args['status'] : 'active';
        $format        = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';

        $directory = get_post($directory_id);
        if (!$directory || $directory->post_type !== \membercore\directory\models\Directory::CPT) {
            \WP_CLI::error("Directory with ID {$directory_id} not found");
        }

        \WP_CLI::log("Users enrolled in directory: {$directory->post_title} (ID: {$directory_id})");

        // Get enrollments based on status filter
        $enrollment_args = ['directory_id' => $directory_id];
        if ($status_filter !== 'all') {
            $enrollment_args['is_active'] = ($status_filter === 'active') ? 1 : 0;
        }

        $enrollments = \membercore\directory\models\Enrollment::get_by_directory($directory_id, ($status_filter !== 'all'));

        if (empty($enrollments)) {
            \WP_CLI::log("No users found with status: {$status_filter}");
            return;
        }

        $table_data = [];

        foreach ($enrollments as $enrollment) {
            $user = get_user_by('ID', $enrollment->user_id);
            if ($user) {
                $table_data[] = [
                    'User ID'       => $user->ID,
                    'Username'      => $user->user_login,
                    'Display Name'  => $user->display_name,
                    'Email'         => $user->user_email,
                    'Status'        => $enrollment->is_active ? 'Active' : 'Inactive',
                    'Enrolled Date' => $enrollment->created_at,
                    'Last Updated'  => $enrollment->updated_at,
                ];
            }
        }

        if (empty($table_data)) {
            \WP_CLI::log('No valid users found');
            return;
        }

        \WP_CLI::log('Found ' . count($table_data) . ' users');
        \WP_CLI\Utils\format_items($format, $table_data, [
            'User ID',
            'Username',
            'Display Name',
            'Email',
            'Status',
            'Enrolled Date',
            'Last Updated',
        ]);
    }

    /**
     * Update enrollment counts for all directories
     *
     * ## EXAMPLES
     *
     *     wp meco directory update-enrollment-counts
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function update_enrollment_counts($args, $assoc_args)
    {
        $this->ensure_profiles_plugin_loaded();

        \WP_CLI::line('Updating enrollment counts for all directories...');
        
        $directories = \membercore\directory\models\Directory::get_all();
        $count = 0;
        
        foreach ($directories as $directory) {
            $old_count = $directory->total_enrolled;
            // $directory->update_total_enrolled();
            $new_count = $directory->total_enrolled;
            
            \WP_CLI::line(sprintf(
                'Directory "%s" (ID: %d): %d enrolled users (was %d)',
                $directory->post_title,
                $directory->ID,
                $new_count,
                $old_count
            ));
            
            $count++;
        }
        
        \WP_CLI::success(sprintf('Updated enrollment counts for %d directories.', $count));
    }

    /**
     * Show job queue status and statistics
     *
     * ## OPTIONS
     *
     * [--status=<status>]
     * : Filter by job status (pending, working, complete, failed)
     *
     * [--class=<class>]
     * : Filter by job class name
     *
     * [--format=<format>]
     * : Output format (table, csv, json)
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp meco jobs status
     *     wp meco jobs status --status=failed
     *     wp meco jobs status --class=DirectoryEnrollmentJob
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function status($args, $assoc_args)
    {
        $this->ensure_profiles_plugin_loaded();

        $status_filter = isset($assoc_args['status']) ? $assoc_args['status'] : null;
        $class_filter  = isset($assoc_args['class']) ? $assoc_args['class'] : null;
        $format        = isset($assoc_args['format']) ? $assoc_args['format'] : 'table';

        global $wpdb;
        $prefix = $wpdb->prefix . 'mcdir_';

        // Get job counts by status
        $stats = $this->get_job_statistics($prefix);

        \WP_CLI::log('=== Job Queue Statistics ===');
        \WP_CLI::log('Pending: ' . $stats['pending']);
        \WP_CLI::log('Working: ' . $stats['working']);
        \WP_CLI::log('Completed: ' . $stats['completed']);
        \WP_CLI::log('Failed: ' . $stats['failed']);
        \WP_CLI::log('Total: ' . $stats['total']);
        \WP_CLI::log('');

        // Get detailed job list
        $jobs = $this->get_jobs($prefix, $status_filter, $class_filter);

        if (empty($jobs)) {
            \WP_CLI::log('No jobs found matching criteria.');
            return;
        }

        \WP_CLI\Utils\format_items($format, $jobs, [
            'ID',
            'Class',
            'Status',
            'Priority',
            'Tries',
            'Created',
            'Runtime',
            'Last Run',
        ]);
    }

    /**
     * Watch job status in real-time
     *
     * ## OPTIONS
     *
     * [--interval=<seconds>]
     * : Refresh interval in seconds
     * ---
     * default: 5
     * ---
     *
     * [--changes-only]
     * : Only show when statistics change
     *
     * [--status=<status>]
     * : Filter by job status (pending, working, completed, failed)
     *
     * [--class=<class>]
     * : Filter by job class
     *
     * ## EXAMPLES
     *
     *     wp meco jobs watch
     *     wp meco jobs watch --interval=10
     *     wp meco jobs watch --changes-only
     *     wp meco jobs watch --status=pending
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function watch($args, $assoc_args)
    {
        $this->ensure_profiles_plugin_loaded();

        $interval      = isset($assoc_args['interval']) ? intval($assoc_args['interval']) : 5;
        $changes_only  = isset($assoc_args['changes-only']);
        $status_filter = isset($assoc_args['status']) ? $assoc_args['status'] : null;
        $class_filter  = isset($assoc_args['class']) ? $assoc_args['class'] : null;

        // Ensure interval is reasonable
        if ($interval < 1) {
            $interval = 1;
        }

        global $wpdb;
        $prefix = $wpdb->prefix . 'mcdir_';

        \WP_CLI::log('=== Job Queue Watcher ===');
        \WP_CLI::log("Watching job status every {$interval} seconds...");
        \WP_CLI::log('Press Ctrl+C to stop');
        \WP_CLI::log('');

        $last_stats = null;
        $iteration  = 0;

        while (true) {
            $current_stats = $this->get_job_statistics($prefix);
            $timestamp     = date('Y-m-d H:i:s');

            // Check if we should display this update
            $should_display = !$changes_only || 
                            $last_stats === null || 
                            $current_stats !== $last_stats;

            if ($should_display) {
                if ($iteration > 0) {
                    \WP_CLI::log(''); // Add spacing between updates
                }

                \WP_CLI::log("=== Update at {$timestamp} ===");
                
                // Show statistics with change indicators
                if ($last_stats !== null) {
                    $this->display_stats_with_changes($current_stats, $last_stats);
                } else {
                    $this->display_stats($current_stats);
                }

                // Show active jobs if any
                if ($current_stats['pending'] > 0 || $current_stats['working'] > 0) {
                    \WP_CLI::log('');
                    \WP_CLI::log('=== Active Jobs ===');
                    
                    $active_jobs = $this->get_jobs($prefix, null, $class_filter);
                    $active_jobs = array_filter($active_jobs, function($job) {
                        return in_array($job['Status'], ['pending', 'working']);
                    });

                    if (!empty($active_jobs)) {
                        \WP_CLI\Utils\format_items('table', array_slice($active_jobs, 0, 10), [
                            'ID',
                            'Class',
                            'Status',
                            'Priority',
                            'Tries',
                            'Created',
                        ]);
                        
                        if (count($active_jobs) > 10) {
                            \WP_CLI::log('... and ' . (count($active_jobs) - 10) . ' more');
                        }
                    }
                }

                $last_stats = $current_stats;
            }

            $iteration++;
            sleep($interval);
        }
    }

    /**
     * Display statistics with change indicators
     *
     * @param array $current_stats
     * @param array $last_stats
     */
    private function display_stats_with_changes($current_stats, $last_stats)
    {
        $status_types = ['pending', 'working', 'completed', 'failed', 'total'];
        
        foreach ($status_types as $type) {
            $current = $current_stats[$type];
            $last    = $last_stats[$type];
            $change  = $current - $last;
            
            $label = ucfirst($type);
            $indicator = '';
            
            if ($change > 0) {
                $indicator = \WP_CLI::colorize(" %g(+{$change})%n");
            } elseif ($change < 0) {
                $indicator = \WP_CLI::colorize(" %r({$change})%n");
            }
            
            \WP_CLI::log("{$label}: {$current}{$indicator}");
        }
    }

    /**
     * Display statistics without change indicators
     *
     * @param array $stats
     */
    private function display_stats($stats)
    {
        \WP_CLI::log('Pending: ' . $stats['pending']);
        \WP_CLI::log('Working: ' . $stats['working']);
        \WP_CLI::log('Completed: ' . $stats['completed']);
        \WP_CLI::log('Failed: ' . $stats['failed']);
        \WP_CLI::log('Total: ' . $stats['total']);
    }

    /**
     * Show detailed information about a specific job
     *
     * ## OPTIONS
     *
     * <job_id>
     * : The ID of the job to inspect
     *
     * [--table=<table>]
     * : Which table to look in (jobs, completed, failed)
     * ---
     * default: jobs
     * options:
     *   - jobs
     *   - completed
     *   - failed
     * ---
     *
     * ## EXAMPLES
     *
     *     wp meco jobs inspect 123
     *     wp meco jobs inspect 123 --table=failed
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function inspect($args, $assoc_args)
    {
        $this->ensure_profiles_plugin_loaded();

        if (empty($args[0])) {
            \WP_CLI::error('Please provide a job ID');
        }

        $job_id = intval($args[0]);
        $table  = isset($assoc_args['table']) ? $assoc_args['table'] : 'jobs';

        global $wpdb;
        $prefix = $wpdb->prefix . 'mcdir_';

        // Map table options to actual table names
        $table_map = [
            'jobs'      => 'jobs',
            'completed' => 'completed_jobs',
            'failed'    => 'failed_jobs',
        ];

        $actual_table = $table_map[$table] ?? $table;
        $table_name   = $prefix . $actual_table;

        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $job_id
        ));

        if (!$job) {
            \WP_CLI::error("Job with ID {$job_id} not found in {$table} table");
        }

        \WP_CLI::log('=== Job Details ===');
        \WP_CLI::log('ID: ' . $job->id);
        \WP_CLI::log('Class: ' . $job->class);
        \WP_CLI::log('Status: ' . $job->status);
        \WP_CLI::log('Priority: ' . $job->priority);
        \WP_CLI::log('Tries: ' . $job->tries);
        \WP_CLI::log('Created: ' . $job->created_at);
        \WP_CLI::log('Runtime: ' . $job->runtime);
        \WP_CLI::log('First Run: ' . $job->firstrun);
        \WP_CLI::log('Last Run: ' . $job->lastrun);
        \WP_CLI::log('Batch: ' . ($job->batch ?: 'N/A'));
        \WP_CLI::log('Reason: ' . ($job->reason ?: 'N/A'));
        \WP_CLI::log('');
        \WP_CLI::log('=== Job Arguments ===');

        if ($job->args) {
            $args_decoded = json_decode($job->args, true);
            if ($args_decoded) {
                foreach ($args_decoded as $key => $value) {
                    \WP_CLI::log($key . ': ' . (is_array($value) ? json_encode($value) : $value));
                }
            } else {
                \WP_CLI::log('Raw args: ' . $job->args);
            }
        } else {
            \WP_CLI::log('No arguments');
        }
    }

    /**
     * Clear jobs from the queue
     *
     * ## OPTIONS
     *
     * [--status=<status>]
     * : Clear jobs with specific status (pending, working, complete, failed)
     *
     * [--class=<class>]
     * : Clear jobs of specific class
     *
     * [--older-than=<hours>]
     * : Clear jobs older than specified hours
     *
     * [--dry-run]
     * : Show what would be deleted without actually deleting
     *
     * ## EXAMPLES
     *
     *     wp meco jobs clear --status=failed
     *     wp meco jobs clear --class=DirectoryEnrollmentJob --dry-run
     *     wp meco jobs clear --older-than=24
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function clear($args, $assoc_args)
    {
        $this->ensure_profiles_plugin_loaded();

        $status_filter = isset($assoc_args['status']) ? $assoc_args['status'] : null;
        $class_filter  = isset($assoc_args['class']) ? $assoc_args['class'] : null;
        $older_than    = isset($assoc_args['older-than']) ? intval($assoc_args['older-than']) : null;
        $dry_run       = isset($assoc_args['dry-run']);

        global $wpdb;
        $prefix = $wpdb->prefix . 'mcdir_';

        // Build the where clause
        $where_conditions = [];
        $where_values     = [];

        if ($status_filter) {
            $where_conditions[] = 'status = %s';
            $where_values[]     = $status_filter;
        }

        if ($class_filter) {
            $where_conditions[] = 'class = %s';
            $where_values[]     = $class_filter;
        }

        if ($older_than) {
            $where_conditions[] = 'created_at < %s';
            $where_values[]     = date('Y-m-d H:i:s', time() - ($older_than * 3600));
        }

        if (empty($where_conditions)) {
            \WP_CLI::error('Please specify at least one filter (status, class, or older-than)');
        }

        $where_clause = implode(' AND ', $where_conditions);

        // Determine which tables to clear from
        $tables = [];
        if (!$status_filter || in_array($status_filter, ['pending', 'working'])) {
            $tables[] = $prefix . 'jobs';
        }
        if (!$status_filter || $status_filter === 'complete') {
            $tables[] = $prefix . 'completed_jobs';
        }
        if (!$status_filter || $status_filter === 'failed') {
            $tables[] = $prefix . 'failed_jobs';
        }

        $total_deleted = 0;

        foreach ($tables as $table) {
            // First, get count of jobs that would be deleted
            $count_query = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";
            $count       = $wpdb->get_var($wpdb->prepare($count_query, $where_values));

            if ($count > 0) {
                \WP_CLI::log("Table {$table}: {$count} jobs would be cleared");

                if (!$dry_run) {
                    $delete_query   = "DELETE FROM {$table} WHERE {$where_clause}";
                    $deleted        = $wpdb->query($wpdb->prepare($delete_query, $where_values));
                    $total_deleted += $deleted;
                    \WP_CLI::log("Deleted {$deleted} jobs from {$table}");
                }
            }
        }

        if ($dry_run) {
            \WP_CLI::log("DRY RUN: Would delete {$total_deleted} jobs total");
        } else {
            \WP_CLI::success("Cleared {$total_deleted} jobs from queue");
        }
    }

    /**
     * Retry failed jobs
     *
     * ## OPTIONS
     *
     * [<job_id>]
     * : Retry a specific job by ID
     *
     * [--class=<class>]
     * : Retry jobs of specific class only
     *
     * [--limit=<limit>]
     * : Maximum number of jobs to retry
     * ---
     * default: 10
     * ---
     *
     * [--dry-run]
     * : Show what would be retried without actually retrying
     *
     * ## EXAMPLES
     *
     *     wp meco jobs retry
     *     wp meco jobs retry 123
     *     wp meco jobs retry --class=DirectoryEnrollmentJob
     *     wp meco jobs retry --limit=5 --dry-run
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function retry($args, $assoc_args)
    {
        $this->ensure_profiles_plugin_loaded();

        $job_id       = isset($args[0]) ? intval($args[0]) : null;
        $class_filter = isset($assoc_args['class']) ? $assoc_args['class'] : null;
        $limit        = isset($assoc_args['limit']) ? intval($assoc_args['limit']) : 10;
        $dry_run      = isset($assoc_args['dry-run']);

        global $wpdb;
        $prefix = $wpdb->prefix . 'mcdir_';

        // If specific job ID is provided, retry only that job
        if ($job_id) {
            $failed_job = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$prefix}failed_jobs WHERE id = %d",
                $job_id
            ));

            if (!$failed_job) {
                \WP_CLI::error("Failed job with ID {$job_id} not found");
            }

            \WP_CLI::log("Retrying job {$job_id}: {$failed_job->class} - {$failed_job->reason}");

            if (!$dry_run) {
                $retried = $this->retry_single_job($failed_job, $prefix);
                if ($retried) {
                    \WP_CLI::success("Job {$job_id} retried successfully");
                } else {
                    \WP_CLI::error("Failed to retry job {$job_id}");
                }
            } else {
                \WP_CLI::log("DRY RUN: Would retry job {$job_id}");
            }
            return;
        }

        // Get failed jobs using filters
        $where_clause = '1=1';
        $where_values = [];

        if ($class_filter) {
            $where_clause  .= ' AND class = %s';
            $where_values[] = $class_filter;
        }

        $query          = "SELECT * FROM {$prefix}failed_jobs WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d";
        $where_values[] = $limit;

        $failed_jobs = $wpdb->get_results($wpdb->prepare($query, $where_values));

        if (empty($failed_jobs)) {
            \WP_CLI::log('No failed jobs found matching criteria');
            return;
        }

        \WP_CLI::log('Found ' . count($failed_jobs) . ' failed jobs to retry');

        $retried_count = 0;

        foreach ($failed_jobs as $job) {
            \WP_CLI::log("Job {$job->id}: {$job->class} - {$job->reason}");

            if (!$dry_run) {
                if ($this->retry_single_job($job, $prefix)) {
                    ++$retried_count;
                    \WP_CLI::log('  → Retried successfully');
                } else {
                    \WP_CLI::log('  → Failed to retry');
                }
            }
        }

        if ($dry_run) {
            \WP_CLI::log('DRY RUN: Would retry ' . count($failed_jobs) . ' jobs');
        } else {
            \WP_CLI::success("Retried {$retried_count} jobs");
        }
    }

    /**
     * Ensure the profiles plugin is loaded
     */
    private function ensure_profiles_plugin_loaded(): void
    {
        if (!class_exists('\\membercore\\directory\\models\\Directory')) {
            \WP_CLI::error('MemberCore Directory plugin is not active or not found');
        }
    }

    /**
     * Preview sync changes for a directory
     *
     * @param  integer $directory_id Directory ID.
     * @return void
     */
    private function preview_sync(int $directory_id): void
    {
        $directory_model = new \membercore\directory\models\Directory($directory_id);

        // Get all current enrollments for this directory
        $current_enrollments = \membercore\directory\models\Enrollment::get_by_directory($directory_id, true);
        $enrolled_user_ids   = array_column($current_enrollments, 'user_id');

        // Get eligible users
        $eligible_users    = \membercore\directory\helpers\EnrollmentHelper::get_eligible_users($directory_model);
        $eligible_user_ids = array_column($eligible_users, 'ID');

        // Find users who should be enrolled but aren't
        $users_to_enroll = array_diff($eligible_user_ids, $enrolled_user_ids);

        // Find users who are enrolled but shouldn't be
        $users_to_unenroll = array_diff($enrolled_user_ids, $eligible_user_ids);

        \WP_CLI::log('  Current enrollments: ' . count($enrolled_user_ids));
        \WP_CLI::log('  Eligible users: ' . count($eligible_user_ids));
        \WP_CLI::log('  Users to enroll: ' . count($users_to_enroll));
        \WP_CLI::log('  Users to unenroll: ' . count($users_to_unenroll));

        if (!empty($users_to_enroll)) {
            foreach ($users_to_enroll as $user_id) {
                $user = get_user_by('ID', $user_id);
                \WP_CLI::log("    + Would enroll: {$user->user_login} (ID: {$user_id})");
            }
        }

        if (!empty($users_to_unenroll)) {
            foreach ($users_to_unenroll as $user_id) {
                $user = get_user_by('ID', $user_id);
                \WP_CLI::log("    - Would unenroll: {$user->user_login} (ID: {$user_id})");
            }
        }
    }

    /**
     * Sync a directory
     *
     * @param  integer $directory_id Directory ID.
     * @return void
     */
    private function sync_directory(int $directory_id): void
    {
        try {
            $job = new \membercore\directory\jobs\DirectorySyncJob();
            $job->setAttribute('args', json_encode(['directory_id' => $directory_id]));
            $job->run();
        } catch (\Exception $e) {
            \WP_CLI::error('Sync failed: ' . $e->getMessage());
        }
    }

    /**
     * Retry a single job
     *
     * @param  object $job    Job object
     * @param  string $prefix Database table prefix
     * @return boolean Success status
     */
    private function retry_single_job($job, string $prefix): bool
    {
        global $wpdb;

        // Move job back to pending jobs table
        $job_data = [
            'runtime'    => current_time('mysql'),
            'firstrun'   => $job->firstrun,
            'priority'   => $job->priority,
            'tries'      => 0, // Reset tries
            'class'      => $job->class,
            'batch'      => $job->batch,
            'args'       => $job->args,
            'reason'     => '',
            'status'     => 'pending',
            'lastrun'    => current_time('mysql'),
            'created_at' => current_time('mysql'),
        ];

        $inserted = $wpdb->insert($prefix . 'jobs', $job_data);

        if ($inserted) {
            // Remove from failed jobs table
            $wpdb->delete($prefix . 'failed_jobs', ['id' => $job->id]);
            return true;
        }

        return false;
    }

    /**
     * Get job statistics
     *
     * @param  string $prefix Database table prefix
     * @return array
     */
    private function get_job_statistics(string $prefix): array
    {
        global $wpdb;

        $pending   = $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}jobs WHERE status = 'pending'");
        $working   = $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}jobs WHERE status = 'working'");
        $completed = $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}completed_jobs");
        $failed    = $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}failed_jobs");

        return [
            'pending'   => intval($pending),
            'working'   => intval($working),
            'completed' => intval($completed),
            'failed'    => intval($failed),
            'total'     => intval($pending) + intval($working) + intval($completed) + intval($failed),
        ];
    }

    /**
     * Get jobs from database
     *
     * @param  string      $prefix        Database table prefix
     * @param  string|null $status_filter Status filter
     * @param  string|null $class_filter  Class filter
     * @return array
     */
    private function get_jobs(string $prefix, ?string $status_filter, ?string $class_filter): array
    {
        global $wpdb;

        $jobs   = [];
        $tables = [
            'jobs'           => ['pending', 'working'],
            'completed_jobs' => ['complete'],
            'failed_jobs'    => ['failed'],
        ];

        foreach ($tables as $table => $statuses) {
            if ($status_filter && !in_array($status_filter, $statuses)) {
                continue;
            }

            $where_clause = '1=1';
            $where_values = [];

            if ($class_filter) {
                $where_clause  .= ' AND class = %s';
                $where_values[] = $class_filter;
            }

            $query = "SELECT id, class, status, priority, tries, created_at, runtime, lastrun 
                      FROM {$prefix}{$table} 
                      WHERE {$where_clause} 
                      ORDER BY created_at DESC 
                      LIMIT 100";

            $results = empty($where_values) ?
                $wpdb->get_results($query) :
                $wpdb->get_results($wpdb->prepare($query, $where_values));

            foreach ($results as $job) {
                $jobs[] = [
                    'ID'       => $job->id,
                    'Class'    => basename($job->class),
                    'Status'   => $job->status,
                    'Priority' => $job->priority,
                    'Tries'    => $job->tries,
                    'Created'  => $job->created_at,
                    'Runtime'  => $job->runtime,
                    'Last Run' => $job->lastrun,
                ];
            }
        }

        return $jobs;
    }
} 