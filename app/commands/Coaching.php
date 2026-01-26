<?php

namespace membercore\cli\commands;

use membercore\coachkit\models as models;
use membercore\coachkit\lib as lib;
use Tightenco\Collect\Support\Collection;

class Coaching
{




	protected $faker;

	public function __construct()
	{
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
	public function reset($args, $assoc_args)
	{
		$this->{'reset_' . $args[0]}($args, $assoc_args);
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
	public function reset_progress($args, $assoc_args)
	{
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
		$confirmation_message = "This will truncate the following tables:\n- " . implode("\n- ", $tables) . "\nAre you sure you want to reset progress?";
		\WP_CLI::confirm($confirmation_message, true);

		foreach ($tables as $key => $table) {
			$wpdb->query("TRUNCATE TABLE {$table}");
		}
		\WP_CLI::success('Tables truncated');
	}

	/**
	 * Reset options
	 *
	 * @when after_wp_load
	 *
	 * @param array $args The command arguments.
	 * @param array $assoc_args The command associative arguments.
	 */
	public function reset_options($args, $assoc_args)
	{
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

		\WP_CLI::success('MemberCore Option rows deleted');
	}

	/**
	 * Reset programs
	 *
	 * @when after_wp_load
	 *
	 * @param array $args The command arguments.
	 * @param array $assoc_args The command associative arguments.
	 */
	public function reset_programs($args, $assoc_args)
	{
		global $wpdb;
		$db = lib\Db::fetch();

		if (isset($assoc_args['id'])) {
			$program_id = intval($assoc_args['id']);

			// Delete associated records for the specified program
			$wpdb->query($wpdb->prepare("DELETE FROM {$db->checkins} WHERE milestone_id IN (SELECT id FROM {$db->milestones} WHERE program_id = %d) OR habit_id IN (SELECT id FROM {$db->habits} WHERE program_id = %d)", $program_id, $program_id));
			$wpdb->query($wpdb->prepare("DELETE FROM {$db->milestones} WHERE program_id = %d", $program_id));
			$wpdb->query($wpdb->prepare("DELETE FROM {$db->habits} WHERE program_id = %d", $program_id));
			$wpdb->query($wpdb->prepare("DELETE FROM {$db->groups} WHERE program_id = %d", $program_id));
			$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}posts WHERE ID = %d", $program_id));

			\WP_CLI::success('Program and associated records have been deleted.');
		} else {
			// Confirm before deleting all records related to programs
			\WP_CLI::confirm('This will delete all programs and their associated records. Are you sure you want to proceed?');

			// Delete all records related to programs
			$wpdb->query($wpdb->prepare("TRUNCATE TABLE {$db->checkins};"));
			$wpdb->query($wpdb->prepare("TRUNCATE TABLE {$db->milestones};"));
			$wpdb->query($wpdb->prepare("TRUNCATE TABLE {$db->habits};"));
			$wpdb->query($wpdb->prepare("TRUNCATE TABLE {$db->groups};"));

			$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}posts WHERE post_type = %s;", models\Program::CPT));

			\WP_CLI::success('All programs and associated records have been deleted.');
		}
	}


	/**
	 * Seed program titles as new posts.
	 *
	 * ## OPTIONS
	 *
	 * [--programs=<number>]
	 * : Number of programs to create (1-7). Default: random between 1-7.
	 *
	 * [--assign-memberships]
	 * : Automatically assign created programs to random memberships.
	 *
	 * [--memberships-per-program=<number>]
	 * : Number of random memberships to assign per program. Default: 1-3 (random).
	 *
	 * ## EXAMPLES
	 *
	 *     # Seed 3 programs
	 *     wp mcch seed --programs=3
	 *
	 *     # Seed 5 programs and assign them to random memberships
	 *     wp mcch seed --programs=5 --assign-memberships
	 *
	 *     # Seed programs and assign each to 2 memberships
	 *     wp mcch seed --programs=3 --assign-memberships --memberships-per-program=2
	 *
	 * @alias seed
	 *
	 * @when after_wp_load
	 *
	 * @param array $args The command arguments.
	 * @param array $assoc_args The command associative arguments.
	 */
	public function seed($args, $assoc_args)
	{
		$json_file    = plugin_dir_path(__DIR__) . 'assets/coaching-programs.json'; // Adjust the filename if needed
		$program_json = file_get_contents($json_file);

		$number = isset($assoc_args['programs']) ? intval($assoc_args['programs']) : $this->faker->numberBetween(1, 7);
		if ($number > 7 || absint($number) < 1) {
			\WP_CLI::error('Select number from 1 - 7');
		}

		$assign_memberships = isset($assoc_args['assign-memberships']);
		$memberships_per_program = isset($assoc_args['memberships-per-program'])
			? intval($assoc_args['memberships-per-program'])
			: $this->faker->numberBetween(1, 3);

		// Get available memberships if needed
		$available_memberships = [];
		if ($assign_memberships) {
			$available_memberships = get_posts([
				'post_type' => 'membercoreproduct',
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields' => 'ids'
			]);

			if (empty($available_memberships)) {
				\WP_CLI::warning('No memberships found. Programs will be created but not assigned to any memberships.');
				$assign_memberships = false;
			} else {
				\WP_CLI::line(sprintf('Found %d membership(s) to assign programs to.', count($available_memberships)));
			}
		}

		// Use Collect to create a Collection from the JSON
		$programs = Collection::make(json_decode($program_json, true));

		if ($programs->isEmpty()) {
			\WP_CLI::error('No valid programs found in the JSON.');
		}

		$created_program_ids = [];

		// Use Collect's each method for iteration
		$programs->random($number)->each(
			function ($program) use (&$created_program_ids) {

				// Adjust the post type and any other parameters as needed
				$post_id = wp_insert_post(
					[
						'post_title'  => $program['program_title'],
						'post_type'   => models\Program::CPT,
						'post_status' => 'publish',
					]
				);

				// Add Milestones
				foreach ($program['milestones'] as $data) {
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
				foreach ($program['habits'] as $data) {
					$habit                  = new models\Habit();
					$habit->uuid            = $this->faker->uuid();
					$habit->program_id      = $post_id;
					$habit->title           = $data['title'];
					$habit->timing          = $data['timing'];
					$habit->repeat_length   = $data['repeat_length'];
					$habit->repeat_interval = $data['repeat_interval'] ?? null;
					$habit->repeat_days     = $data['repeat_days'] ?? null;
					$habit->enable_checkin  = false;
					$habit->store();
				}

				// Add Groups
				foreach ($program['groups'] as $data) {
					$group                       = new models\Group();
					$group->coach_id             = $this->get_or_create_coach_id($this->faker->randomNumber(3, false));
					$group->program_id           = $post_id;
					$group->title                = $data['title'];
					$group->type                 = $data['type'];
					$group->allow_enrollment_cap = $data['allow_enrollment_cap'];
					$group->enrollment_cap       = $data['enrollment_cap'] ?? null;
					// Only set start_date and end_date if they exist in the data
					if (isset($data['start_date'])) {
						$group->start_date = $data['start_date'];
					}
					if (isset($data['end_date'])) {
						$group->end_date = $data['end_date'];
					}
					$group->status               = $data['status'];
					$group->store();
				}

				if ($post_id) {
					$created_program_ids[] = $post_id;
					\WP_CLI::success("Program '" . $program['program_title'] . "' inserted with ID: $post_id");
				} else {
					\WP_CLI::warning("Failed to insert program '" . $program['program_title'] . "'");
				}
			}
		);

		// Assign programs to memberships if requested
		if ($assign_memberships && !empty($created_program_ids) && !empty($available_memberships)) {
			\WP_CLI::line('');
			\WP_CLI::line('Assigning programs to memberships...');

			$this->assign_programs_to_memberships(
				$created_program_ids,
				$available_memberships,
				$memberships_per_program
			);
		}
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
	public function toggle_rl($args, $assoc_args)
	{
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
	public function sync_enrollments($args, $assoc_args)
	{
		$dry_run = isset($assoc_args['dry-run']);
		$membership_id = isset($assoc_args['membership']) ? intval($assoc_args['membership']) : null;

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

		if ($membership_id) {
			$query_args['p'] = $membership_id;
		}

		$products = get_posts($query_args);

		if (empty($products)) {
			\WP_CLI::error("No memberships with assigned programs found.");
		}

		foreach ($products as $product) {
			$assigned_programs_raw = get_post_meta($product->ID, models\Program::PRODUCT_META, true);
			if (empty($assigned_programs_raw)) {
				continue;
			}

			$assigned_programs = Collection::make(maybe_unserialize($assigned_programs_raw));

			if ($assigned_programs->isEmpty()) {
				continue;
			}

			\WP_CLI::log("Processing membership: {$product->post_title} (ID: {$product->ID})");

			// 2. Find all users with active transactions for this membership
			$transactions = $wpdb->get_results($wpdb->prepare(
				"SELECT * FROM {$meco_db->transactions} WHERE product_id = %d AND status IN ('complete', 'confirmed') AND (expires_at >= %s OR expires_at = '0000-00-00 00:00:00')",
				$product->ID,
				current_time('mysql')
			));

			if (empty($transactions)) {
				\WP_CLI::log("  No active transactions found for this membership.");
				continue;
			}

			\WP_CLI::log("  Found " . count($transactions) . " active transactions.");

			// 3. For each transaction, ensure the user is enrolled in all assigned programs
			foreach ($transactions as $txn) {
				$assigned_programs->each(function ($item) use ($txn, $product, $dry_run) {
					if (! isset($item['program_id'])) {
						return;
					}

					$program = new models\Program($item['program_id']);

					// Check if user is already enrolled in this program
					$existing_enrollment = models\Enrollment::get_one([
						'student_id' => $txn->user_id,
						'program_id' => $program->id
					]);

					if ($existing_enrollment) {
						return; // Already enrolled
					}

					if ($dry_run) {
						\WP_CLI::log("  [DRY RUN] Would enroll user {$txn->user_id} in program {$program->title}");
						return;
					}

					// Find next available group
					$group = $program->next_available_group($program->groups(), $txn->user_id);

					if (! $group) {
						\WP_CLI::warning("  No available group for program {$program->title} (User ID: {$txn->user_id})");
						return;
					}

					$enrollment             = new models\Enrollment();
					$enrollment->student_id = $txn->user_id;
					$enrollment->group_id   = $group->id;
					$enrollment->start_date = $group->get_start_date();
					$enrollment->program_id = $group->program_id;
					$enrollment->txn_id     = $txn->id;
					$enrollment->created_at = lib\Utils::ts_to_mysql_date(time());
					$enrollment->features   = 'messaging';
					$enrollment_id          = $enrollment->store();

					if (is_wp_error($enrollment_id)) {
						\WP_CLI::error("  Failed to enroll user {$txn->user_id} in program {$program->title}: " . $enrollment_id->get_error_message());
					} else {
						\WP_CLI::success("  Enrolled user {$txn->user_id} in program {$program->title} (Group ID: {$group->id})");
						lib\Utils::send_program_started_notice($program, $product, $enrollment);
					}
				});
			}
		}
	}

	/**
	 * Assign programs to random memberships
	 * 
	 * ## OPTIONS
	 * 
	 * [--programs=<number>]
	 * : Number of programs to create (1-7). Default: random between 1-7.
	 * 
	 *
	 * @param array $program_ids Array of program IDs to assign.
	 * @param array $membership_ids Array of available membership IDs.
	 * @param int $memberships_per_program Number of memberships to assign per program.
	 */
	private function assign_programs_to_memberships($program_ids, $membership_ids, $memberships_per_program)
	{
		foreach ($program_ids as $program_id) {
			// Get random memberships for this program
			$selected_count = min($memberships_per_program, count($membership_ids));
			$selected_memberships = (array) array_rand(array_flip($membership_ids), $selected_count);

			foreach ($selected_memberships as $membership_id) {
				// Get existing programs for this membership
				$existing_programs = maybe_unserialize(get_post_meta($membership_id, '_mcch-programs', true));
				if (! is_array($existing_programs)) {
					$existing_programs = [];
				}

				// Add program if not already assigned
				if (! in_array($program_id, $existing_programs)) {
					$existing_programs[] = ['program_id' => $program_id];
					update_post_meta($membership_id, '_mcch-programs', maybe_serialize($existing_programs));

					$membership = get_post($membership_id);
					$program = get_post($program_id);
					\WP_CLI::log("  ✓ Assigned '{$program->post_title}' to membership '{$membership->post_title}' (ID: {$membership_id})");
				}
			}
		}

		\WP_CLI::success(sprintf('Assigned %d program(s) to memberships.', count($program_ids)));
	}

	/**
	 * Function to get or create a coach and return the coach ID.
	 *
	 * @param string $coach_email Email of the coach.
	 *
	 * @return int Coach ID
	 */
	private function get_or_create_coach_id($coach_id = 0)
	{
		// Check if coach with this email exists
		$coach = new models\Coach($coach_id);

		// If coach doesn't exist, create a new user and assign the Coach role
		if (! $coach->ID) {
			$user_id = wp_insert_user(
				[
					'user_login' => $this->faker->userName(),
					'user_pass'  => wp_generate_password(12, false),
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

	/**
	 * Backup CoachKit messaging data (rooms, messages, attachments)
	 *
	 * ## OPTIONS
	 *
	 * [--file=<file>]
	 * : Backup file path. Defaults to wp-content/uploads/coachkit-messages-backup.json
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcch backup_messages
	 *     wp mcch backup_messages --file=/path/to/backup.json
	 *
	 * @when after_wp_load
	 *
	 * @param array $args The command arguments.
	 * @param array $assoc_args The command associative arguments.
	 */
	public function backup_messages($args, $assoc_args)
	{
		global $wpdb;
		$db = \membercore\coachkit\lib\Db::fetch();

		// Default backup file location
		$upload_dir = wp_upload_dir();
		$default_file = $upload_dir['basedir'] . '/coachkit-messages-backup.json';
		$backup_file = isset($assoc_args['file']) ? $assoc_args['file'] : $default_file;

		\WP_CLI::log('Starting backup of CoachKit messaging data...');

		// Get all data
		$data = [
			'timestamp' => current_time('mysql'),
			'rooms' => $wpdb->get_results("SELECT * FROM {$db->rooms}", ARRAY_A),
			'room_participants' => $wpdb->get_results("SELECT * FROM {$db->room_participants}", ARRAY_A),
			'messages' => $wpdb->get_results("SELECT * FROM {$db->messages}", ARRAY_A),
			'message_attachments' => $wpdb->get_results("SELECT * FROM {$db->message_attachments}", ARRAY_A),
		];

		// Save to file
		$json = wp_json_encode($data, JSON_PRETTY_PRINT);
		file_put_contents($backup_file, $json);

		\WP_CLI::success(sprintf(
			'Backed up %d rooms, %d participants, %d messages, %d attachments to: %s',
			count($data['rooms']),
			count($data['room_participants']),
			count($data['messages']),
			count($data['message_attachments']),
			$backup_file
		));
	}

	/**
	 * Restore CoachKit messaging data from backup
	 *
	 * ## OPTIONS
	 *
	 * [--file=<file>]
	 * : Backup file path. Defaults to plugin's assets folder, then wp-content/uploads
	 *
	 * [--truncate]
	 * : Truncate existing data before restoring
	 *
	 * ## EXAMPLES
	 *
	 *     # Restore from default location (checks plugin assets first)
	 *     wp mcch restore_messages
	 *
	 *     # Restore with truncation
	 *     wp mcch restore_messages --truncate
	 *
	 *     # Restore from custom file
	 *     wp mcch restore_messages --file=/path/to/backup.json --truncate
	 *
	 * @when after_wp_load
	 *
	 * @param array $args The command arguments.
	 * @param array $assoc_args The command associative arguments.
	 */
	public function restore_messages($args, $assoc_args)
	{
		global $wpdb;
		$db = \membercore\coachkit\lib\Db::fetch();

		// Default backup file location - check plugin assets first, then uploads
		if (isset($assoc_args['file'])) {
			$backup_file = $assoc_args['file'];
		} else {
			$plugin_file = plugin_dir_path(__DIR__) . 'assets/coachkit-messages-backup.json';
			$upload_dir = wp_upload_dir();
			$uploads_file = $upload_dir['basedir'] . '/coachkit-messages-backup.json';
			
			// Use plugin file if it exists, otherwise fall back to uploads
			$backup_file = file_exists($plugin_file) ? $plugin_file : $uploads_file;
		}

		// Check if file exists
		if (!file_exists($backup_file)) {
			\WP_CLI::error("Backup file not found: {$backup_file}");
			return;
		}

		\WP_CLI::log("Reading backup from: {$backup_file}");

		// Read backup file
		$json = file_get_contents($backup_file);
		$data = json_decode($json, true);

		if (!$data) {
			\WP_CLI::error('Failed to parse backup file');
			return;
		}

		\WP_CLI::log(sprintf(
			'Backup contains: %d rooms, %d participants, %d messages, %d attachments',
			count($data['rooms']),
			count($data['room_participants']),
			count($data['messages']),
			count($data['message_attachments'])
		));

		// Truncate if requested
		if (isset($assoc_args['truncate'])) {
			\WP_CLI::confirm('This will delete all existing CoachKit messaging data. Continue?');
			\WP_CLI::log('Truncating existing data...');
			$wpdb->query("TRUNCATE TABLE {$db->message_attachments}");
			$wpdb->query("TRUNCATE TABLE {$db->messages}");
			$wpdb->query("TRUNCATE TABLE {$db->room_participants}");
			$wpdb->query("TRUNCATE TABLE {$db->rooms}");
			\WP_CLI::success('Existing data truncated');
		}

		// Restore rooms
		\WP_CLI::log('Restoring rooms...');
		foreach ($data['rooms'] as $room) {
			$wpdb->insert($db->rooms, $room);
		}

		// Restore room participants
		\WP_CLI::log('Restoring room participants...');
		foreach ($data['room_participants'] as $participant) {
			$wpdb->insert($db->room_participants, $participant);
		}

		// Restore messages
		\WP_CLI::log('Restoring messages...');
		foreach ($data['messages'] as $message) {
			$wpdb->insert($db->messages, $message);
		}

		// Restore message attachments
		\WP_CLI::log('Restoring message attachments...');
		foreach ($data['message_attachments'] as $attachment) {
			$wpdb->insert($db->message_attachments, $attachment);
		}

		\WP_CLI::success('CoachKit messaging data restored successfully!');
	}
}
