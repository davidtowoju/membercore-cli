<?php

/**
 * MemberCore Maintenance REST API
 * 
 * Place this file in: /wp-content/mu-plugins/membercore-maintenance-api.php
 * 
 * Provides REST API endpoint to trigger daily maintenance script
 * Endpoint: POST /wp-json/membercore/v1/maintenance
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// echo '<div style="background:red;color:white;padding:10px;position:fixed;top:0;left:0;z-index:9999;">MU-PLUGIN LOADED!</div>';
// var_dump('rouuute');
// die('STOP HERE - MU PLUGIN DEBUG');


class MemberCore_Maintenance_API
{

    private $script_path;
    private $namespace = 'membercore-cli/v1';

    public function __construct()
    {
        $this->script_path = ABSPATH . 'wp-content/plugins/membercore-cli/scripts/daily-maintenance.sh';
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    /**
     * Register REST API routes
     */
    public function register_routes()
    {
        register_rest_route($this->namespace, '/maintenance', array(
            'methods' => 'POST',
            'callback' => array($this, 'run_maintenance'),
            'permission_callback' => array($this, 'check_permissions'),
            'args' => array(
                'dry_run' => array(
                    'required' => false,
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Run in dry-run mode (no actual changes)'
                ),
                'verbose' => array(
                    'required' => false,
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Enable verbose output'
                ),
                'auth_key' => array(
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Authentication key'
                )
            )
        ));

        // Status endpoint to check if maintenance is running
        register_rest_route($this->namespace, '/maintenance/status', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_maintenance_status'),
            'permission_callback' => array($this, 'check_permissions'),
            'args' => array(
                'auth_key' => array(
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Authentication key'
                )
            )
        ));

        // Get maintenance logs
        register_rest_route($this->namespace, '/maintenance/logs', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_maintenance_logs'),
            'permission_callback' => array($this, 'check_permissions'),
            'args' => array(
                'auth_key' => array(
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Authentication key'
                ),
                'date' => array(
                    'required' => false,
                    'type' => 'string',
                    'default' => date('Y-m-d'),
                    'description' => 'Date to get logs for (Y-m-d format)'
                )
            )
        ));
    }

    /**
     * Check permissions for API access
     */
    public function check_permissions($request)
    {
        $auth_key = $request->get_param('auth_key');
        $expected_key = defined('CUSTOM_MEMBERCORE_API_KEY') ? CUSTOM_MEMBERCORE_API_KEY : wp_salt('auth');

        if (!$auth_key) {
            return new WP_Error('missing_auth', 'Authentication key required', array('status' => 401));
        }

        if (!hash_equals($expected_key, $auth_key)) {
            return new WP_Error('invalid_auth', 'Invalid authentication key', array('status' => 403));
        }

        return true;
    }

    /**
     * Run maintenance script
     */
    public function run_maintenance($request)
    {
        $dry_run = $request->get_param('dry_run');
        $verbose = $request->get_param('verbose');

        // Check if script exists
        if (!file_exists($this->script_path)) {
            return new WP_Error('script_not_found', 'Maintenance script not found at: ' . $this->script_path, array('status' => 404));
        }

        // Check if script is already running
        $lock_file = '/tmp/membercore-maintenance.lock';
        if (file_exists($lock_file)) {
            $pid = file_get_contents($lock_file);
            return new WP_Error('already_running', 'Maintenance script is already running (PID: ' . $pid . ')', array('status' => 409));
        }

        // Build command
        $command = 'bash ' . escapeshellarg($this->script_path);

        if ($dry_run) {
            $command .= ' --dry-run';
        }

        if ($verbose) {
            $command .= ' --verbose';
        }

        // Run in background
        $command .= ' > /dev/null 2>&1 &';

        // Execute
        $start_time = microtime(true);
        exec($command, $output, $return_code);
        $execution_time = microtime(true) - $start_time;

        // Response data
        $response = array(
            'success' => true,
            'message' => 'Maintenance script started successfully',
            'started_at' => current_time('mysql'),
            'dry_run' => $dry_run,
            'verbose' => $verbose,
            'execution_time' => round($execution_time, 3),
            'command' => str_replace(' > /dev/null 2>&1 &', '', $command),
            'log_file' => WP_CONTENT_DIR . '/uploads/membercore-logs/daily-maintenance-' . date('Y-m-d') . '.log'
        );

        return rest_ensure_response($response);
    }

    /**
     * Get maintenance status
     */
    public function get_maintenance_status($request)
    {
        $lock_file = '/tmp/membercore-maintenance.lock';
        $log_dir = WP_CONTENT_DIR . '/uploads/membercore-logs';
        $today_log = $log_dir . '/daily-maintenance-' . date('Y-m-d') . '.log';

        $is_running = file_exists($lock_file);
        $pid = $is_running ? file_get_contents($lock_file) : null;

        // Get last run info from today's log
        $last_run = null;
        $last_status = null;
        if (file_exists($today_log)) {
            $lines = file($today_log, FILE_IGNORE_NEW_LINES);
            foreach (array_reverse($lines) as $line) {
                if (strpos($line, 'Starting MemberCore Daily Maintenance') !== false) {
                    preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $matches);
                    $last_run = $matches[1] ?? null;
                    break;
                }
            }

            // Check for completion status
            foreach (array_reverse($lines) as $line) {
                if (strpos($line, 'All maintenance tasks completed successfully') !== false) {
                    $last_status = 'success';
                    break;
                } elseif (strpos($line, 'Maintenance completed with') !== false && strpos($line, 'failures') !== false) {
                    $last_status = 'partial_failure';
                    break;
                }
            }
        }

        return rest_ensure_response(array(
            'is_running' => $is_running,
            'pid' => $pid,
            'last_run' => $last_run,
            'last_status' => $last_status,
            'log_file' => $today_log,
            'log_exists' => file_exists($today_log),
            'timestamp' => current_time('mysql')
        ));
    }

    /**
     * Get maintenance logs
     */
    public function get_maintenance_logs($request)
    {
        $date = $request->get_param('date');

        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new WP_Error('invalid_date', 'Invalid date format. Use Y-m-d format.', array('status' => 400));
        }

        $log_file = WP_CONTENT_DIR . '/uploads/membercore-logs/daily-maintenance-' . $date . '.log';

        if (!file_exists($log_file)) {
            return new WP_Error('log_not_found', 'Log file not found for date: ' . $date, array('status' => 404));
        }

        $logs = file_get_contents($log_file);
        $lines = explode("\n", $logs);

        // Parse logs into structured data
        $parsed_logs = array();
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;

            preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) - (.*)$/', $line, $matches);
            if ($matches) {
                $parsed_logs[] = array(
                    'timestamp' => $matches[1],
                    'message' => $matches[2],
                    'type' => strpos($matches[2], 'ERROR:') === 0 ? 'error' : (strpos($matches[2], '✓') === 0 ? 'success' : (strpos($matches[2], '✗') === 0 ? 'failure' : 'info'))
                );
            }
        }

        return rest_ensure_response(array(
            'date' => $date,
            'log_file' => $log_file,
            'total_lines' => count($parsed_logs),
            'logs' => $parsed_logs
        ));
    }
}

// Initialize the API
new MemberCore_Maintenance_API();

/**
 * Add this to your wp-config.php for security:
 * define('CUSTOM_MEMBERCORE_API_KEY', 'your-secure-random-key-here');
 * 
 * If not defined, it will use wp_salt('auth') as fallback
 */
