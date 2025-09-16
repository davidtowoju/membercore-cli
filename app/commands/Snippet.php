<?php

namespace membercore\cli\commands;

/**
 * Code Snippets management commands
 */
class Snippet extends Base
{
    /**
     * Create a new code snippet when Code Snippets plugin is active
     *
     * ## OPTIONS
     *
     * <title>
     * : Title/name of the snippet
     *
     * --code=<code>
     * : The PHP code for the snippet (without opening <?php tags)
     *
     * [--scope=<scope>]
     * : Where the snippet should run. Options: global, admin, front-end, single-use. Default: global
     *
     * [--active=<active>]
     * : Whether the snippet should be active. Options: 0, 1. Default: 1
     *
     * [--description=<description>]
     * : Description for the snippet
     *
     * [--priority=<priority>]
     * : Execution priority for the snippet. Default: 10
     *
     * [--tags=<tags>]
     * : Comma-separated tags for the snippet
     *
     * ## EXAMPLES
     *
     *     wp snippet create "My Snippet" --code="echo 'Hello World';" --scope=front-end --active=1
     *     wp snippet create "Admin Notice" --code="add_action('admin_notices', function() { echo '<div class=\"notice notice-info\"><p>Custom notice</p></div>'; });" --scope=admin
     *     wp snippet create "Custom Function" --code="function my_custom_function() { return 'Hello'; }" --description="A custom function" --tags="custom,function"
     *
     * @when after_wp_load
     */
    public function create($args, $assoc_args)
    {
        // Check if Code Snippets plugin is active
        if (!$this->is_code_snippets_active()) {
            $this->error('The Code Snippets plugin is not active. Please install and activate the Code Snippets plugin first.');
            return;
        }

        $title = $args[0];

        // Validate required arguments
        if (!isset($assoc_args['code']) || empty($assoc_args['code'])) {
            $this->error('The --code parameter is required and cannot be empty.');
            return;
        }

        // Parse arguments with defaults
        $defaults = [
            'scope'       => 'global',
            'active'      => 1,
            'description' => '',
            'priority'    => 10,
            'tags'        => '',
        ];
        $assoc_args = wp_parse_args($assoc_args, $defaults);

        // Validate scope
        $valid_scopes = ['global', 'admin', 'front-end', 'single-use'];
        if (!in_array($assoc_args['scope'], $valid_scopes)) {
            $this->error("Invalid scope '{$assoc_args['scope']}'. Valid options: " . implode(', ', $valid_scopes));
            return;
        }

        // Validate active
        $active = intval($assoc_args['active']);
        if ($active !== 0 && $active !== 1) {
            $this->error("Invalid active value '{$assoc_args['active']}'. Must be 0 or 1.");
            return;
        }

        // Validate priority
        $priority = intval($assoc_args['priority']);
        if ($priority < 0) {
            $this->error("Priority must be a non-negative integer.");
            return;
        }

        // Prepare snippet data
        $snippet_data = [
            'name'        => sanitize_text_field($title),
            'code'        => $this->sanitize_code($assoc_args['code']),
            'scope'       => sanitize_text_field($assoc_args['scope']),
            'active'      => $active,
            'description' => sanitize_textarea_field($assoc_args['description']),
            'priority'    => $priority,
        ];

        // Add tags if provided
        if (!empty($assoc_args['tags'])) {
            $tags = array_map('trim', explode(',', $assoc_args['tags']));
            $tags = array_map('sanitize_text_field', $tags);
            $snippet_data['tags'] = $tags;
        }

        try {
            // Create the snippet using Code Snippets plugin functionality
            $snippet_id = $this->create_code_snippet($snippet_data);

            if ($snippet_id) {
                $status = $active ? 'active' : 'inactive';
                $this->success("Snippet '{$title}' created successfully with ID {$snippet_id} ({$status}).");
                
                // Show snippet details
                $this->log("Details:");
                $this->log("  ID: {$snippet_id}");
                $this->log("  Title: {$title}");
                $this->log("  Scope: {$assoc_args['scope']}");
                $this->log("  Priority: {$priority}");
                if (!empty($assoc_args['description'])) {
                    $this->log("  Description: {$assoc_args['description']}");
                }
                if (!empty($assoc_args['tags'])) {
                    $this->log("  Tags: {$assoc_args['tags']}");
                }
            } else {
                $this->error('Failed to create snippet. Check that the Code Snippets plugin is properly installed.');
            }
        } catch (\Exception $e) {
            $this->error('Failed to create snippet: ' . $e->getMessage());
        }
    }

    /**
     * List existing code snippets
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Options: table, csv, json, yaml. Default: table
     *
     * [--fields=<fields>]
     * : Fields to display. Default: id,name,scope,active,priority
     *
     * [--limit=<limit>]
     * : Limit number of snippets returned. Default: 50
     *
     * [--scope=<scope>]
     * : Filter by scope
     *
     * [--active=<active>]
     * : Filter by active status. Options: 0, 1
     *
     * ## EXAMPLES
     *
     *     wp snippet list
     *     wp snippet list --scope=front-end
     *     wp snippet list --active=1 --format=json
     *
     * @when after_wp_load
     */
    public function list($args, $assoc_args)
    {
        // Check if Code Snippets plugin is active
        if (!$this->is_code_snippets_active()) {
            $this->error('The Code Snippets plugin is not active.');
            return;
        }

        try {
            $snippets = $this->get_code_snippets($assoc_args);

            if (empty($snippets)) {
                \WP_CLI::warning('No snippets found.');
                return;
            }

            $format = $assoc_args['format'] ?? 'table';
            $fields = $assoc_args['fields'] ?? 'id,name,scope,active,priority';

            \WP_CLI\Utils\format_items($format, $snippets, explode(',', $fields));
        } catch (\Exception $e) {
            $this->error('Failed to list snippets: ' . $e->getMessage());
        }
    }

    /**
     * Delete a code snippet
     *
     * ## OPTIONS
     *
     * <snippet_id>
     * : ID of the snippet to delete
     *
     * [--yes]
     * : Skip confirmation prompt
     *
     * ## EXAMPLES
     *
     *     wp snippet delete 123
     *     wp snippet delete 456 --yes
     *
     * @when after_wp_load
     */
    public function delete($args, $assoc_args)
    {
        // Check if Code Snippets plugin is active
        if (!$this->is_code_snippets_active()) {
            $this->error('The Code Snippets plugin is not active.');
            return;
        }

        $snippet_id = intval($args[0]);

        if ($snippet_id <= 0) {
            $this->error('Invalid snippet ID. Must be a positive integer.');
            return;
        }

        try {
            // Get snippet details before deletion
            $snippet = $this->get_snippet_by_id($snippet_id);
            
            if (!$snippet) {
                $this->error("Snippet with ID {$snippet_id} not found.");
                return;
            }

            // Confirm deletion
            if (!isset($assoc_args['yes'])) {
                \WP_CLI::confirm("Are you sure you want to delete snippet '{$snippet['name']}'?");
            }

            // Delete the snippet
            $result = $this->delete_code_snippet($snippet_id);

            if ($result) {
                $this->success("Snippet '{$snippet['name']}' (ID: {$snippet_id}) deleted successfully.");
            } else {
                $this->error("Failed to delete snippet with ID {$snippet_id}.");
            }
        } catch (\Exception $e) {
            $this->error('Failed to delete snippet: ' . $e->getMessage());
        }
    }

    /**
     * Check if Code Snippets plugin is active
     *
     * @return bool
     */
    protected function is_code_snippets_active(): bool
    {
        // Check if the main Code Snippets function exists
        if (function_exists('code_snippets')) {
            return true;
        }

        // Alternative check using plugin activation
        if (function_exists('is_plugin_active')) {
            return is_plugin_active('code-snippets/code-snippets.php');
        }

        // Fallback check using class existence
        return class_exists('Code_Snippets');
    }

    /**
     * Create a code snippet using the Code Snippets plugin
     *
     * @param array $snippet_data
     * @return int|false
     */
    protected function create_code_snippet(array $snippet_data)
    {
        // Use Code Snippets plugin's database functions if available
        if (function_exists('code_snippets') && method_exists(code_snippets()->db, 'insert')) {
            return code_snippets()->db->insert($snippet_data);
        }

        // Fallback: direct database insertion (less preferred)
        return $this->direct_database_insert($snippet_data);
    }

    /**
     * Get code snippets with optional filtering
     *
     * @param array $filters
     * @return array
     */
    protected function get_code_snippets(array $filters = []): array
    {
        if (function_exists('code_snippets') && method_exists(code_snippets()->db, 'get_snippets')) {
            $snippets = code_snippets()->db->get_snippets();
        } else {
            $snippets = $this->direct_database_select($filters);
        }

        // Apply filters
        if (!empty($filters['scope'])) {
            $snippets = array_filter($snippets, function($snippet) use ($filters) {
                return $snippet['scope'] === $filters['scope'];
            });
        }

        if (isset($filters['active'])) {
            $active = intval($filters['active']);
            $snippets = array_filter($snippets, function($snippet) use ($active) {
                return intval($snippet['active']) === $active;
            });
        }

        // Apply limit
        $limit = intval($filters['limit'] ?? 50);
        if ($limit > 0) {
            $snippets = array_slice($snippets, 0, $limit);
        }

        return $snippets;
    }

    /**
     * Get a single snippet by ID
     *
     * @param int $snippet_id
     * @return array|null
     */
    protected function get_snippet_by_id(int $snippet_id): ?array
    {
        if (function_exists('code_snippets') && method_exists(code_snippets()->db, 'get_snippet')) {
            return code_snippets()->db->get_snippet($snippet_id);
        }

        return $this->direct_database_select_one($snippet_id);
    }

    /**
     * Delete a code snippet
     *
     * @param int $snippet_id
     * @return bool
     */
    protected function delete_code_snippet(int $snippet_id): bool
    {
        if (function_exists('code_snippets') && method_exists(code_snippets()->db, 'delete')) {
            return code_snippets()->db->delete($snippet_id);
        }

        return $this->direct_database_delete($snippet_id);
    }

    /**
     * Sanitize code input
     *
     * @param string $code
     * @return string
     */
    protected function sanitize_code(string $code): string
    {
        // Remove opening PHP tags if present
        $code = ltrim($code);
        if (strpos($code, '<?php') === 0) {
            $code = substr($code, 5);
        } elseif (strpos($code, '<?') === 0) {
            $code = substr($code, 2);
        }

        return trim($code);
    }

    /**
     * Fallback: Direct database insertion
     *
     * @param array $snippet_data
     * @return int|false
     */
    protected function direct_database_insert(array $snippet_data)
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'snippets';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            throw new \Exception("Code Snippets table '{$table_name}' does not exist.");
        }

        $insert_data = [
            'name' => $snippet_data['name'],
            'code' => $snippet_data['code'],
            'scope' => $snippet_data['scope'],
            'active' => $snippet_data['active'],
            'description' => $snippet_data['description'],
            'priority' => $snippet_data['priority'],
            'modified' => current_time('mysql'),
        ];

        if (isset($snippet_data['tags'])) {
            $insert_data['tags'] = maybe_serialize($snippet_data['tags']);
        }

        $result = $wpdb->insert($table_name, $insert_data);

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Fallback: Direct database select
     *
     * @param array $filters
     * @return array
     */
    protected function direct_database_select(array $filters = []): array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'snippets';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return [];
        }

        $query = "SELECT * FROM {$table_name}";
        $query .= " ORDER BY id DESC";

        $results = $wpdb->get_results($query, ARRAY_A);

        return $results ?: [];
    }

    /**
     * Fallback: Direct database select one
     *
     * @param int $snippet_id
     * @return array|null
     */
    protected function direct_database_select_one(int $snippet_id): ?array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'snippets';

        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $snippet_id),
            ARRAY_A
        );

        return $result ?: null;
    }

    /**
     * Fallback: Direct database delete
     *
     * @param int $snippet_id
     * @return bool
     */
    protected function direct_database_delete(int $snippet_id): bool
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'snippets';

        $result = $wpdb->delete($table_name, ['id' => $snippet_id], ['%d']);

        return $result !== false;
    }
}
