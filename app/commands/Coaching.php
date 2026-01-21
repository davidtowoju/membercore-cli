<?php

namespace membercore\cli\commands;

use membercore\coachkit\models as models;
use membercore\coachkit\lib as lib;
use Tightenco\Collect\Support\Collection;

class Coaching {




	protected $faker;

	public function __construct() {
		 $this->faker = \Faker\Factory::create();
	}

	/**
	 * Reset
	 *
	 * @alias fresh
	 *
	 * @param array $args The command arguments.
	 * @param array $assoc_args The command associative arguments.
	 */
	public function reset( $args, $assoc_args ) {
		$this->{'reset_' . $args[0]}( $args, $assoc_args );
	}

	/**
	 * Prints a greeting.
	 * 
	 * ## OPTIONS
	 *
	 * [<model>...]
	 * : The models to truncate.
	 *
	 * @when after_wp_load
	 */
	public function reset_progress( $args, $assoc_args ) {
		global $wpdb;
		$db      = \membercore\coachkit\lib\Db::fetch();
		$meco_db = \MecoDb::fetch();

		$tables = [
			$meco_db->transactions,
			$meco_db->subscriptions,
			$meco_db->events,
			$db->student_progress,
			$db->enrollments,
			$db->messages,
			$db->message_attachments,
			$db->notes,
			$db->rooms,
			$db->room_participants,
		];

		// Prompt for confirmation
		$confirmation_message = "This will truncate the following tables:\n- " . implode( "\n- ", $tables ) . "\nAre you sure you want to reset progress?";
		\WP_CLI::confirm( $confirmation_message, true );

		foreach ( $tables as $key => $table ) {
			$wpdb->query( "TRUNCATE TABLE {$table}" );
		}
		\WP_CLI::success( 'Tables truncated' );
	}

	/**
	 * Reset options
	 *
	 * @when after_wp_load
	 *
	 * @param array $args The command arguments.
	 * @param array $assoc_args The command associative arguments.
	 */
	public function reset_options( $args, $assoc_args ) {
		global $wpdb;
		
		// Delete rows starting with 'meco' or 'mcch'
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}options WHERE option_name LIKE %s OR option_name LIKE %s",
				'meco%',
				'mcch%'
			)
		);

		$meco_options = \MecoOptions::fetch();
		$meco_options->set_defaults();
		$meco_options->store(false); // store will convert this back into an array

		\WP_CLI::success( 'MemberCore Option rows deleted' );
	}

	/**
	 * Reset programs
	 *
	 * @when after_wp_load
	 *
	 * @param array $args The command arguments.
	 * @param array $assoc_args The command associative arguments.
	 */
	public function reset_programs( $args, $assoc_args ) {
		global $wpdb;
		$db = lib\Db::fetch();

		if ( isset( $assoc_args['id'] ) ) {
			$program_id = intval( $assoc_args['id'] );

			// Delete associated records for the specified program
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$db->checkins} WHERE milestone_id IN (SELECT id FROM {$db->milestones} WHERE program_id = %d) OR habit_id IN (SELECT id FROM {$db->habits} WHERE program_id = %d)", $program_id, $program_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$db->milestones} WHERE program_id = %d", $program_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$db->habits} WHERE program_id = %d", $program_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$db->groups} WHERE program_id = %d", $program_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}posts WHERE ID = %d", $program_id ) );

			\WP_CLI::success( 'Program and associated records have been deleted.' );
		} else {
			// Confirm before deleting all records related to programs
			\WP_CLI::confirm( 'This will delete all programs and their associated records. Are you sure you want to proceed?' );

			// Delete all records related to programs
			$wpdb->query( $wpdb->prepare( "TRUNCATE TABLE {$db->checkins};" ) );
			$wpdb->query( $wpdb->prepare( "TRUNCATE TABLE {$db->milestones};" ) );
			$wpdb->query( $wpdb->prepare( "TRUNCATE TABLE {$db->habits};" ) );
			$wpdb->query( $wpdb->prepare( "TRUNCATE TABLE {$db->groups};" ) );

			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}posts WHERE post_type = %s;", models\Program::CPT ) );

			\WP_CLI::success( 'All programs and associated records have been deleted.' );
		}
	}


	/**
	 * Seed program titles as new posts.
	 *
	 * @alias seed
	 *
	 * @when after_wp_load
	 *
	 * @param array $args The command arguments.
	 * @param array $assoc_args The command associative arguments.
	 */
	public function seed( $args, $assoc_args ) {
		$json_file    = plugin_dir_url( __DIR__ ) . 'assets/coaching-programs.json'; // Adjust the filename if needed
		$program_json = file_get_contents( $json_file );

		$number = isset( $assoc_args['programs'] ) ? intval( $assoc_args['programs'] ) : $this->faker->numberBetween( 1, 7 );
		if ( $number > 7 || absint( $number ) < 1 ) {
			\WP_CLI::error( 'Select number from 1 - 7' );
		}

		// Use Collect to create a Collection from the JSON
		$programs = Collection::make( json_decode( $program_json, true ) );

		if ( $programs->isEmpty() ) {
			\WP_CLI::error( 'No valid programs found in the JSON.' );
		}

		// Use Collect's each method for iteration
		$programs->random( $number )->each(
			function ( $program ) {

				// Adjust the post type and any other parameters as needed
				$post_id = wp_insert_post(
					[
						'post_title'  => $program['program_title'],
						'post_type'   => models\Program::CPT,
						'post_status' => 'publish',
					]
				);

				// Add Milestones
				foreach ( $program['milestones'] as $data ) {
					$milestone                 = new models\Milestone();
					$milestone->uuid           = $this->faker->uuid();
					$milestone->program_id     = $post_id;
					$milestone->title          = $data['title'];
					$milestone->timing         = $data['timing'];
					$milestone->due_length     = $data['due_length'];
					$milestone->due_unit       = $data['due_unit'];
					$milestone->enable_checkin = false;
					$milestone->store();
				}

				// Add Habits
				foreach ( $program['habits'] as $data ) {
					$habit                  = new models\Habit();
					$habit->uuid            = $this->faker->uuid();
					$habit->program_id      = $post_id;
					$habit->title           = $data['title'];
					$habit->timing          = $data['timing'];
					$habit->repeat_length   = $data['repeat_length'];
					$habit->repeat_unit     = $data['repeat_unit'];
					$habit->repeat_interval = $data['repeat_interval'];
					$habit->repeat_days     = $data['repeat_days'];
					$habit->enable_checkin  = false;
					$habit->store();
				}

				// Add Groups
				foreach ( $program['groups'] as $data ) {
					$group                       = new models\Group();
					$group->coach_id             = $this->get_or_create_coach_id( $this->faker->randomNumber( 3, false ) );
					$group->program_id           = $post_id;
					$group->title                = $data['title'];
					$group->type                 = $data['type'];
					$group->allow_enrollment_cap = $data['allow_enrollment_cap'];
					$group->enrollment_cap       = $data['enrollment_cap'];
					$group->start_date           = $data['start_date'];
					$group->end_date             = $data['end_date'];
					$group->status               = $data['status'];
					$group->store();
				}

				if ( $post_id ) {
					\WP_CLI::success( "Program". $program['program_title']." inserted with ID: $post_id" );
				} else {
					\WP_CLI::warning( "Failed to insert program ". $program['program_title'] );
				}
			}
		);
	}

	/**
	 * Toggle ReadyLaunch.
	 *
	 * @alias toggle-rl
	 *
	 * @when after_wp_load
	 *
	 * @param array $args The command arguments.
	 * @param array $assoc_args The command associative arguments.
	 */
	public function toggle_rl( $args, $assoc_args ) {
		$options = \MecoOptions::fetch();
		$options->rl_enable_coaching_template = !$options->rl_enable_coaching_template;
		$options->store(false);
	}

	/**
	 * Sync enrollments for memberships that have assigned programs.
	 * 
	 * ## OPTIONS
	 * 
	 * [--membership=<membership_id>]
	 * : The membership ID to sync. If not provided, syncs all memberships with assigned programs.
	 * 
	 * [--dry-run]
	 * : Show what would be synced without actually creating enrollments.
	 * 
	 * @alias sync-enrollments
	 * @when after_wp_load
	 */
	public function sync_enrollments( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] );
		$membership_id = isset( $assoc_args['membership'] ) ? intval( $assoc_args['membership'] ) : null;

		global $wpdb;
		$meco_db = \MecoDb::fetch();

		// 1. Get memberships with assigned programs
		$query_args = [
			'post_type' => 'membercoreproduct',
			'post_status' => 'publish',
			'meta_query' => [
				[
					'key' => models\Program::PRODUCT_META,
					'compare' => 'EXISTS',
				],
			],
			'posts_per_page' => -1,
		];

		if ( $membership_id ) {
			$query_args['p'] = $membership_id;
		}

		$products = get_posts( $query_args );

		if ( empty( $products ) ) {
			\WP_CLI::error( "No memberships with assigned programs found." );
		}

		foreach ( $products as $product ) {
			$assigned_programs_raw = get_post_meta( $product->ID, models\Program::PRODUCT_META, true );
			if ( empty( $assigned_programs_raw ) ) {
				continue;
			}

			$assigned_programs = Collection::make( maybe_unserialize( $assigned_programs_raw ) );

			if ( $assigned_programs->isEmpty() ) {
				continue;
			}

			\WP_CLI::log( "Processing membership: {$product->post_title} (ID: {$product->ID})" );

			// 2. Find all users with active transactions for this membership
			$transactions = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$meco_db->transactions} WHERE product_id = %d AND status IN ('complete', 'confirmed') AND (expires_at >= %s OR expires_at = '0000-00-00 00:00:00')",
				$product->ID,
				current_time( 'mysql' )
			) );

			if ( empty( $transactions ) ) {
				\WP_CLI::log( "  No active transactions found for this membership." );
				continue;
			}

			\WP_CLI::log( "  Found " . count( $transactions ) . " active transactions." );

			// 3. For each transaction, ensure the user is enrolled in all assigned programs
			foreach ( $transactions as $txn ) {
				$assigned_programs->each( function ( $item ) use ( $txn, $product, $dry_run ) {
					if ( ! isset( $item['program_id'] ) ) {
						return;
					}

					$program = new models\Program( $item['program_id'] );
					
					// Check if user is already enrolled in this program
					$existing_enrollment = models\Enrollment::get_one( [ 
						'student_id' => $txn->user_id,
						'program_id' => $program->id
					] );

					if ( $existing_enrollment ) {
						return; // Already enrolled
					}

					if ( $dry_run ) {
						\WP_CLI::log( "  [DRY RUN] Would enroll user {$txn->user_id} in program {$program->title}" );
						return;
					}

					// Find next available group
					$group = $program->next_available_group( $program->groups(), $txn->user_id );

					if ( ! $group ) {
						\WP_CLI::warning( "  No available group for program {$program->title} (User ID: {$txn->user_id})" );
						return;
					}

					$enrollment             = new models\Enrollment();
					$enrollment->student_id = $txn->user_id;
					$enrollment->group_id   = $group->id;
					$enrollment->start_date = $group->get_start_date();
					$enrollment->program_id = $group->program_id;
					$enrollment->txn_id     = $txn->id;
					$enrollment->created_at = lib\Utils::ts_to_mysql_date( time() );
					$enrollment->features   = 'messaging';
					$enrollment_id          = $enrollment->store();

					if ( is_wp_error( $enrollment_id ) ) {
						\WP_CLI::error( "  Failed to enroll user {$txn->user_id} in program {$program->title}: " . $enrollment_id->get_error_message() );
					} else {
						\WP_CLI::success( "  Enrolled user {$txn->user_id} in program {$program->title} (Group ID: {$group->id})" );
						lib\Utils::send_program_started_notice( $program, $product, $enrollment );
					}
				} );
			}
		}
	}

	/**
	 * Function to get or create a coach and return the coach ID.
	 *
	 * @param string $coach_email Email of the coach.
	 *
	 * @return int Coach ID
	 */
	private function get_or_create_coach_id( $coach_id = 0 ) {
		// Check if coach with this email exists
		$coach = new models\Coach( $coach_id );

		// If coach doesn't exist, create a new user and assign the Coach role
		if ( ! $coach->ID ) {
			$user_id = wp_insert_user(
				[
					'user_login' => $this->faker->userName(),
					'first_name' => $this->faker->firstName(),
					'last_name'  => $this->faker->lastName(),
					'user_email' => $this->faker->email(),
					'role'       => models\Coach::ROLE,
				]
			);

			return $user_id;
		}

		return $coach->ID; // Return the existing coach's ID
	}
}
