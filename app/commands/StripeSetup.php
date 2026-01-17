<?php

namespace membercore\cli\commands;

use WP_CLI;
use MecoOptions;
use MecoStripeGateway;

/**
 * Stripe configuration command
 */
class StripeSetup
{
    /**
     * Configure Stripe settings from constants
     *
     * ## EXAMPLES
     *
     *     wp meco stripe-setup
     *
     * @when after_wp_load
     */
    public function __invoke($args, $assoc_args)
    {
        if (!defined('STRIPE_TEST_PUBLISHABLE_KEY') || !defined('STRIPE_TEST_SECRET_KEY')) {
            WP_CLI::error('STRIPE_TEST_PUBLISHABLE_KEY and STRIPE_TEST_SECRET_KEY constants must be defined in wp-config.php');
            return;
        }

        $meco_options = MecoOptions::fetch();
        $stripe_gateway_id = null;
        $stripe_integration_index = null;
        $existing_api_keys = [];

        // Find existing Stripe integration
        if (isset($meco_options->integrations) && is_array($meco_options->integrations)) {
            foreach ($meco_options->integrations as $index => $integration) {
                if (isset($integration['gateway']) && $integration['gateway'] === 'MecoStripeGateway') {
                    $stripe_gateway_id = $integration['id'];
                    $existing_api_keys = isset($integration['api_keys']) ? $integration['api_keys'] : [];
                    // Remove the existing entry so we can re-add it with the correct key (ID)
                    unset($meco_options->integrations[$index]);
                    break;
                }
            }
        } else {
            $meco_options->integrations = [];
        }

        if (empty($stripe_gateway_id)) {
            $stripe_gateway_id = uniqid();
        }

        $test_pk = constant('STRIPE_TEST_PUBLISHABLE_KEY');
        $test_sk = constant('STRIPE_TEST_SECRET_KEY');

        $new_api_keys = [
            'test' => [
                'public' => $test_pk,
                'secret' => $test_sk,
            ],
            'live' => [
                'public' => isset($existing_api_keys['live']['public']) ? $existing_api_keys['live']['public'] : '',
                'secret' => isset($existing_api_keys['live']['secret']) ? $existing_api_keys['live']['secret'] : '',
            ]
        ];

        $new_settings = [
            'gateway'                 => 'MecoStripeGateway',
            'id'                      => $stripe_gateway_id,
            'label'                   => 'Stripe',
            'use_label'               => true,
            'use_icon'                => true,
            'use_desc'                => true,
            'sort'                    => 0,
            'test_mode'               => true,
            'force_ssl'               => true,
            'stripe_checkout_enabled' => false,
            'api_keys'                => $new_api_keys,
            'payment_methods'         => ['card'],
        ];

        // Ensure the integration is keyed by its ID, which is required for keys_are_set() to work
        $meco_options->integrations[$stripe_gateway_id] = $new_settings;

        WP_CLI::log("Updated Stripe integration (ID: {$stripe_gateway_id}).");

        // Save options without validation (CLI context lacks $_POST)
        $meco_options->store(false);
        
        WP_CLI::success('Stripe settings updated successfully.');
    }
}
