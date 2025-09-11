<?php

namespace membercore\cli\helpers;

/**
 * Helper class for membership operations
 */
class MembershipHelper
{
    /**
     * Get all available memberships/products
     *
     * @return array
     */
    public static function get_all_memberships()
    {
        if (!class_exists('\MecoCptModel')) {
            return [];
        }

        $products = \MecoCptModel::all('MecoProduct');
        return array_map(function ($product) {
            return [
                'id' => $product->ID,
                'title' => $product->post_title,
                'slug' => $product->post_name,
                'status' => $product->post_status,
            ];
        }, $products);
    }

    /**
     * Get membership by ID
     *
     * @param int $membership_id
     * @return array|null
     */
    public static function get_membership_by_id($membership_id)
    {
        if (!class_exists('\MecoProduct')) {
            return null;
        }

        $product = new \MecoProduct($membership_id);
        
        if (!$product->ID) {
            return null;
        }

        return [
            'id' => $product->ID,
            'title' => $product->post_title,
            'slug' => $product->post_name,
            'status' => $product->post_status,
        ];
    }

    /**
     * Check if user has active membership
     *
     * @param int $user_id
     * @param int $membership_id
     * @return bool
     */
    public static function user_has_active_membership($user_id, $membership_id)
    {
        if (!class_exists('\MecoUser')) {
            return false;
        }

        $user = new \MecoUser($user_id);
        $active_products = $user->active_product_subscriptions('products');

        foreach ($active_products as $product) {
            if ($product->ID == $membership_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get user's active memberships
     *
     * @param int $user_id
     * @return array
     */
    public static function get_user_active_memberships($user_id)
    {
        if (!class_exists('\MecoUser')) {
            return [];
        }

        $user = new \MecoUser($user_id);
        $active_products = $user->active_product_subscriptions('products');

        return array_map(function ($product) {
            return [
                'id' => $product->ID,
                'title' => $product->post_title,
                'slug' => $product->post_name,
            ];
        }, $active_products);
    }

    /**
     * Assign membership to user
     *
     * @param int $user_id
     * @param int $membership_id
     * @param array $args Additional arguments for the transaction
     * @return bool|\MecoTransaction
     */
    public static function assign_membership_to_user($user_id, $membership_id, $args = [])
    {
        if (!class_exists('\MecoTransaction') || !class_exists('\MecoProduct')) {
            return false;
        }

        $user = get_user_by('ID', $user_id);
        if (!$user) {
            return false;
        }

        $product = new \MecoProduct($membership_id);
        if (!$product->ID) {
            return false;
        }

        // Get actual product price if not provided
        $product_price = isset($args['amount']) ? floatval($args['amount']) : floatval($product->price);
        
        // Default transaction arguments
        $defaults = [
            'amount' => $product_price,
            'total' => $product_price,
            'tax_amount' => 0.00,
            'tax_rate' => 0.00,
            'trans_num' => 'CLI_' . uniqid(),
            'status' => \MecoTransaction::$complete_str,
            'gateway' => 'manual',
            'created_at' => current_time('mysql'),
            'expires_at' => self::calculate_expiry_date($product, $args),
        ];

        $transaction_args = array_merge($defaults, $args);

        // Create transaction
        $transaction = new \MecoTransaction();
        $transaction->user_id = $user_id;
        $transaction->product_id = $membership_id;
        $transaction->amount = $transaction_args['amount'];
        $transaction->total = $transaction_args['total'];
        $transaction->tax_amount = $transaction_args['tax_amount'];
        $transaction->tax_rate = $transaction_args['tax_rate'];
        $transaction->trans_num = $transaction_args['trans_num'];
        $transaction->status = $transaction_args['status'];
        $transaction->gateway = $transaction_args['gateway'];
        $transaction->created_at = $transaction_args['created_at'];
        $transaction->expires_at = $transaction_args['expires_at'];
        $transaction->txn_type = \MecoTransaction::$payment_str;

        $result = $transaction->store();

        if ($result) {
            // Check if this is a subscription product and create subscription
            if (self::is_subscription_product($product)) {
                $subscription = self::create_subscription($transaction, $product);
                if ($subscription) {
                    $transaction->subscription_id = $subscription->id;
                    $transaction->store();
                }
            }

            // Fire hooks for membership assignment
            do_action('mepr-transaction-completed', $transaction);
            do_action('mepr-event-transaction-completed', $transaction);
            
            return $transaction;
        }

        return false;
    }

    /**
     * Check if product is a subscription product
     *
     * @param \MecoProduct $product
     * @return bool
     */
    private static function is_subscription_product($product)
    {
        return !empty($product->period) && in_array($product->period, ['days', 'weeks', 'months', 'years']);
    }

    /**
     * Create subscription for transaction
     *
     * @param \MecoTransaction $transaction
     * @param \MecoProduct $product
     * @return \MecoSubscription|false
     */
    private static function create_subscription($transaction, $product)
    {
        if (!class_exists('\MecoSubscription')) {
            return false;
        }

        $subscription = new \MecoSubscription();
        $subscription->user_id = $transaction->user_id;
        $subscription->product_id = $transaction->product_id;
        $subscription->price = $transaction->amount;
        $subscription->period = 1; // Default to 1 unit
        $subscription->period_type = $product->period; // Use the period as period_type
        $subscription->limit_cycles = false;
        $subscription->limit_cycles_num = 0;
        $subscription->limit_cycles_action = 'expire';
        $subscription->prorated_trial = false;
        $subscription->trial = false;
        $subscription->trial_days = 0;
        $subscription->trial_amount = 0.00;
        $subscription->status = \MecoSubscription::$active_str;
        $subscription->gateway = $transaction->gateway;
        $subscription->token = '';
        $subscription->created_at = $transaction->created_at;
        
        $result = $subscription->store();
        
        if ($result) {
            return $subscription;
        }

        return false;
    }

    /**
     * Remove membership from user
     *
     * @param int $user_id
     * @param int $membership_id
     * @return bool
     */
    public static function remove_membership_from_user($user_id, $membership_id)
    {
        if (!class_exists('\MecoTransaction')) {
            return false;
        }

        global $wpdb;
        $meco_db = new \MecoDb();

        // Find active transactions for this user/product combination
        $transactions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$meco_db->transactions} 
                WHERE user_id = %d 
                AND product_id = %d 
                AND status IN ('%s', '%s')
                AND (expires_at >= %s OR expires_at = '0000-00-00 00:00:00')",
                $user_id,
                $membership_id,
                \MecoTransaction::$complete_str,
                \MecoTransaction::$confirmed_str,
                current_time('mysql')
            )
        );

        $success = true;
        foreach ($transactions as $txn_data) {
            $transaction = new \MecoTransaction($txn_data->id);
            $transaction->expires_at = current_time('mysql', true);
            $transaction->status = \MecoTransaction::$refunded_str;
            
            if (!$transaction->store()) {
                $success = false;
            } else {
                // Also cancel any associated subscriptions
                if (!empty($transaction->subscription_id)) {
                    $subscription = new \MecoSubscription($transaction->subscription_id);
                    $subscription->status = \MecoSubscription::$cancelled_str;
                    $subscription->store();
                }

                // Fire hooks for membership removal
                do_action('mepr-transaction-expired', $transaction);
                do_action('mepr-event-transaction-expired', $transaction);
            }
        }

        return $success;
    }

    /**
     * Calculate expiry date for membership
     *
     * @param \MecoProduct $product
     * @param array $args
     * @return string
     */
    private static function calculate_expiry_date($product, $args = [])
    {
        // If expiry is explicitly set in args, use it
        if (isset($args['expires_at'])) {
            return $args['expires_at'];
        }

        // For subscription products, calculate next billing date
        if (self::is_subscription_product($product)) {
            return self::calculate_subscription_expiry($product);
        }

        // For one-time products, use product settings or set to lifetime
        if (!empty($product->expire_type) && $product->expire_type !== 'none') {
            return self::calculate_one_time_expiry($product);
        }

        // Default to lifetime (never expires)
        return '0000-00-00 00:00:00';
    }

    /**
     * Calculate subscription expiry date
     *
     * @param \MecoProduct $product
     * @return string
     */
    private static function calculate_subscription_expiry($product)
    {
        $period = 1; // Default to 1 unit
        $period_type = $product->period; // Use period as the unit type
        $current_time = current_time('timestamp');

        switch ($period_type) {
            case 'days':
                $expiry_time = $current_time + ($period * DAY_IN_SECONDS);
                break;
            case 'weeks':
                $expiry_time = $current_time + ($period * WEEK_IN_SECONDS);
                break;
            case 'months':
                $expiry_time = $current_time + ($period * MONTH_IN_SECONDS);
                break;
            case 'years':
                $expiry_time = $current_time + ($period * YEAR_IN_SECONDS);
                break;
            default:
                return '0000-00-00 00:00:00'; // Lifetime
        }

        return date('Y-m-d H:i:s', $expiry_time);
    }

    /**
     * Calculate one-time product expiry date
     *
     * @param \MecoProduct $product
     * @return string
     */
    private static function calculate_one_time_expiry($product)
    {
        if (empty($product->expire_type) || $product->expire_type === 'none') {
            return '0000-00-00 00:00:00'; // Lifetime
        }

        $expire_after = intval($product->expire_after);
        $expire_unit = $product->expire_unit;
        $current_time = current_time('timestamp');

        switch ($expire_unit) {
            case 'days':
                $expiry_time = $current_time + ($expire_after * DAY_IN_SECONDS);
                break;
            case 'weeks':
                $expiry_time = $current_time + ($expire_after * WEEK_IN_SECONDS);
                break;
            case 'months':
                $expiry_time = $current_time + ($expire_after * MONTH_IN_SECONDS);
                break;
            case 'years':
                $expiry_time = $current_time + ($expire_after * YEAR_IN_SECONDS);
                break;
            default:
                return '0000-00-00 00:00:00'; // Lifetime
        }

        return date('Y-m-d H:i:s', $expiry_time);
    }
} 