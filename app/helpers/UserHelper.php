<?php

namespace membercore\cli\helpers;

/**
 * Helper class for user operations
 */
class UserHelper
{
    /**
     * Get user by ID, email, or username
     *
     * @param string|int $identifier
     * @return \WP_User|null
     */
    public static function get_user($identifier): ?\WP_User
    {
        if (is_numeric($identifier)) {
            $user = get_user_by('ID', intval($identifier));
        } elseif (is_email($identifier)) {
            $user = get_user_by('email', $identifier);
        } else {
            $user = get_user_by('login', $identifier);
        }

        return $user ?: null;
    }

    /**
     * Get all users with specific role
     *
     * @param string $role
     * @return array
     */
    public static function get_users_by_role(string $role): array
    {
        return get_users(['role' => $role]);
    }

    /**
     * Get all users with specific capability
     *
     * @param string $capability
     * @return array
     */
    public static function get_users_by_capability(string $capability): array
    {
        return get_users(['capability' => $capability]);
    }

    /**
     * Get user meta in a formatted way
     *
     * @param int $user_id
     * @param string $meta_key
     * @param bool $single
     * @return mixed
     */
    public static function get_user_meta(int $user_id, string $meta_key, bool $single = true)
    {
        return get_user_meta($user_id, $meta_key, $single);
    }

    /**
     * Update user meta
     *
     * @param int $user_id
     * @param string $meta_key
     * @param mixed $meta_value
     * @return bool
     */
    public static function update_user_meta(int $user_id, string $meta_key, $meta_value): bool
    {
        return update_user_meta($user_id, $meta_key, $meta_value) !== false;
    }

    /**
     * Validate user exists
     *
     * @param int $user_id
     * @return bool
     */
    public static function user_exists(int $user_id): bool
    {
        return get_user_by('ID', $user_id) !== false;
    }

    /**
     * Get user's display name
     *
     * @param int $user_id
     * @return string
     */
    public static function get_user_display_name(int $user_id): string
    {
        $user = get_user_by('ID', $user_id);
        return $user ? $user->display_name : '';
    }

    /**
     * Get user's email
     *
     * @param int $user_id
     * @return string
     */
    public static function get_user_email(int $user_id): string
    {
        $user = get_user_by('ID', $user_id);
        return $user ? $user->user_email : '';
    }

    /**
     * Get user's roles
     *
     * @param int $user_id
     * @return array
     */
    public static function get_user_roles(int $user_id): array
    {
        $user = get_user_by('ID', $user_id);
        return $user ? $user->roles : [];
    }

    /**
     * Add role to user
     *
     * @param int $user_id
     * @param string $role
     * @return bool
     */
    public static function add_user_role(int $user_id, string $role): bool
    {
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            return false;
        }

        $user->add_role($role);
        return true;
    }

    /**
     * Remove role from user
     *
     * @param int $user_id
     * @param string $role
     * @return bool
     */
    public static function remove_user_role(int $user_id, string $role): bool
    {
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            return false;
        }

        $user->remove_role($role);
        return true;
    }

    /**
     * Set user role (removes all other roles)
     *
     * @param int $user_id
     * @param string $role
     * @return bool
     */
    public static function set_user_role(int $user_id, string $role): bool
    {
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            return false;
        }

        $user->set_role($role);
        return true;
    }

    /**
     * Create a new user
     *
     * @param array $user_data
     * @return int|\WP_Error
     */
    public static function create_user(array $user_data)
    {
        $defaults = [
            'user_login' => '',
            'user_email' => '',
            'user_pass' => wp_generate_password(),
            'role' => 'subscriber',
        ];

        $user_data = array_merge($defaults, $user_data);

        return wp_insert_user($user_data);
    }

    /**
     * Delete a user
     *
     * @param int $user_id
     * @param int $reassign Optional. User ID to reassign posts to.
     * @return bool
     */
    public static function delete_user(int $user_id, int $reassign = null): bool
    {
        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        return wp_delete_user($user_id, $reassign);
    }
} 