<?php

namespace membercore\cli\commands;

use membercore\cli\helpers\UserHelper;

/**
 * WP-CLI commands for MemberCore Connect (Messaging)
 */
class Connect extends Base
{
    /**
     * Seed the Connect messaging system with sample data
     *
     * Creates rooms, messages, and participants for testing and development.
     *
     * ## OPTIONS
     *
     * [--rooms=<number>]
     * : Number of rooms to create. Default: 5
     *
     * [--messages-per-room=<number>]
     * : Number of messages to create per room. Default: 10-30 (random)
     *
     * [--participants-per-room=<number>]
     * : Number of participants per room. Default: 2-5 (random)
     *
     * [--source-type=<type>]
     * : Source type for rooms (coachkit_group, circle, direct). Default: mixed
     *
     * [--user-id=<id>]
     * : Specific user ID to add to all rooms. If not provided, uses random users.
     *
     * [--with-coachkit]
     * : Link rooms to existing CoachKit groups (requires CoachKit plugin)
     *
     * ## EXAMPLES
     *
     *     # Create 5 rooms with default settings
     *     wp mccon seed
     *
     *     # Create 10 rooms with 20 messages each
     *     wp mccon seed --rooms=10 --messages-per-room=20
     *
     *     # Create rooms for a specific user
     *     wp mccon seed --user-id=1 --rooms=3
     *
     *     # Create rooms linked to CoachKit groups
     *     wp mccon seed --with-coachkit --rooms=5
     *
     *     # Create direct message rooms only
     *     wp mccon seed --source-type=direct --rooms=10 --participants-per-room=2
     *
     * @when after_wp_load
     *
     * @param array $args The command arguments.
     * @param array $assoc_args The command associative arguments.
     */
    public function seed($args, $assoc_args)
    {
        // Check if Connect plugin is active
        if (!class_exists('membercore\\connect\\Models\\Room')) {
            \WP_CLI::error('MemberCore Connect plugin is not active.');
            return;
        }

        $rooms_count = isset($assoc_args['rooms']) ? absint($assoc_args['rooms']) : 5;
        $messages_per_room = isset($assoc_args['messages-per-room']) 
            ? absint($assoc_args['messages-per-room']) 
            : null;
        $participants_per_room = isset($assoc_args['participants-per-room']) 
            ? absint($assoc_args['participants-per-room']) 
            : null;
        $source_type = $assoc_args['source-type'] ?? 'mixed';
        $user_id = isset($assoc_args['user-id']) ? absint($assoc_args['user-id']) : null;
        $with_coachkit = isset($assoc_args['with-coachkit']);

        // Validate user if provided
        if ($user_id && !get_userdata($user_id)) {
            \WP_CLI::error("User with ID {$user_id} does not exist.");
            return;
        }

        // Get CoachKit groups if requested
        $coachkit_groups = [];
        if ($with_coachkit) {
            if (!class_exists('membercore\\coachkit\\models\\Group')) {
                \WP_CLI::warning('CoachKit plugin not found. Creating rooms without CoachKit groups.');
                $with_coachkit = false;
            } else {
                global $wpdb;
                $coachkit_groups = $wpdb->get_results(
                    "SELECT id, title FROM {$wpdb->prefix}mcch_groups WHERE status IN ('open', 'closed') LIMIT 50",
                    ARRAY_A
                );
                
                if (empty($coachkit_groups)) {
                    \WP_CLI::warning('No CoachKit groups found. Run "wp mpch seed" first or create rooms without --with-coachkit.');
                    return;
                }
                
                \WP_CLI::line(sprintf('Found %d CoachKit group(s) to link rooms to.', count($coachkit_groups)));
            }
        }

        \WP_CLI::line('Starting Connect messaging seeder...');
        \WP_CLI::line('');

        $created_rooms = 0;
        $created_messages = 0;
        $created_participants = 0;

        $progress = \WP_CLI\Utils\make_progress_bar('Creating rooms and messages', $rooms_count);

        for ($i = 0; $i < $rooms_count; $i++) {
            // Determine source type for this room
            if ($source_type === 'mixed') {
                $room_source_type = $this->faker->randomElement(['coachkit_group', 'circle', 'direct']);
            } else {
                $room_source_type = $source_type;
            }

            // Handle CoachKit integration
            if ($with_coachkit && $room_source_type === 'coachkit_group' && !empty($coachkit_groups)) {
                $group = $this->faker->randomElement($coachkit_groups);
                $source_id = (int) $group['id'];
                $display_name = $group['title']; // We have this, but MessagingService will fetch it
            } else {
                $source_id = $this->faker->numberBetween(100, 9999);
                $display_name = $this->generateRoomName($room_source_type);
            }

            // Determine participants
            $num_participants = $participants_per_room 
                ?? $this->faker->numberBetween(2, 5);
            
            // For CoachKit groups, get actual participants from the group
            if ($with_coachkit && $room_source_type === 'coachkit_group' && !empty($coachkit_groups)) {
                $participant_ids = $this->getCoachKitParticipants($source_id, $num_participants, $user_id);
            } else {
                $participant_ids = $this->getRandomParticipants($num_participants, $user_id);
            }

            // Create room using MessagingService (which handles display names properly)
            $room = $this->createRoomWithService($room_source_type, $source_id, $participant_ids);
            
            if (!$room) {
                \WP_CLI::warning("Failed to create room #{$i}");
                continue;
            }

            $created_rooms++;
            $created_participants += count($participant_ids);

            // Create messages
            $num_messages = $messages_per_room 
                ?? $this->faker->numberBetween(10, 30);
            
            $messages_created = $this->createMessages($room, $participant_ids, $num_messages);
            $created_messages += $messages_created;

            $progress->tick();
        }

        $progress->finish();

        \WP_CLI::success('Seeding complete!');
        \WP_CLI::line('');
        \WP_CLI::line("Created {$created_rooms} room(s)");
        \WP_CLI::line("Created {$created_participants} participant(s)");
        \WP_CLI::line("Created {$created_messages} message(s)");
        \WP_CLI::line('');
        \WP_CLI::line('View the messaging UI by:');
        \WP_CLI::line('1. Adding [membercore_messages source_type="coachkit_group"] shortcode to a page');
        \WP_CLI::line('2. Or calling do_action("mccon_messages_init", "coachkit_group") in your code');
    }

    /**
     * Create a room using MessagingService (proper way that handles display names)
     *
     * @param string $source_type Source type
     * @param int    $source_id   Source ID
     * @param array  $participant_ids Array of user IDs
     * @return int|false Room ID or false on failure
     */
    private function createRoomWithService(string $source_type, int $source_id, array $participant_ids)
    {
        try {
            $app = \membercore\connect\app(MCCON_PLUGIN_FILE);
            $container = $app->container();
            $messagingService = $container->get(\membercore\connect\Services\MessagingService::class);
            
            $result = $messagingService->createRoom($source_type, $source_id, $participant_ids);
            
            if ($result && !empty($result['room_id'])) {
                return (int) $result['room_id'];
            }
            
            return false;
        } catch (\Exception $e) {
            \WP_CLI::warning("Error creating room: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get participants for a CoachKit group
     *
     * @param int      $group_id         Group ID
     * @param int      $num_participants Desired number of participants
     * @param int|null $specific_user_id Specific user to include
     * @return array Array of user IDs
     */
    private function getCoachKitParticipants(int $group_id, int $num_participants, ?int $specific_user_id = null): array
    {
        try {
            $app = \membercore\connect\app(MCCON_PLUGIN_FILE);
            $container = $app->container();
            $registry = $container->get(\membercore\connect\Services\AdapterRegistry::class);
            $adapter = $registry->get('coachkit_group');
            
            if (!$adapter) {
                return $this->getRandomParticipants($num_participants, $specific_user_id);
            }
            
            $participants = $adapter->getRoomParticipants($group_id);
            
            if (empty($participants)) {
                return $this->getRandomParticipants($num_participants, $specific_user_id);
            }
            
            // Add specific user if provided and not already in list
            if ($specific_user_id && !in_array($specific_user_id, $participants, true)) {
                array_unshift($participants, $specific_user_id);
            }
            
            return $participants;
        } catch (\Exception $e) {
            return $this->getRandomParticipants($num_participants, $specific_user_id);
        }
    }

    /**
     * Get random participants from available users
     *
     * @param int      $num_participants Number of participants
     * @param int|null $specific_user_id Specific user to include
     * @return array Array of user IDs
     */
    private function getRandomParticipants(int $num_participants, ?int $specific_user_id = null): array
    {
        $user_ids = [];
        
        // Add specific user if provided
        if ($specific_user_id) {
            $user_ids[] = $specific_user_id;
            $num_participants--; // Reduce count since we've added one
        }

        // Get random users
        $available_users = get_users([
            'fields' => 'ID',
            'number' => 50,
        ]);

        if (empty($available_users)) {
            return $user_ids;
        }

        // Add random users (avoiding duplicates)
        while (count($user_ids) < $num_participants && !empty($available_users)) {
            $random_user = $this->faker->randomElement($available_users);
            
            if (!in_array($random_user, $user_ids, true)) {
                $user_ids[] = $random_user;
            }
        }

        return $user_ids;
    }

    /**
     * Create messages for a room
     *
     * @param int   $room_id        Room ID
     * @param array $participant_ids Participant user IDs
     * @param int   $num_messages   Number of messages to create
     * @return int Number of messages created
     */
    private function createMessages(int $room_id, array $participant_ids, int $num_messages): int
    {
        if (empty($participant_ids)) {
            return 0;
        }

        $created = 0;
        $now = current_time('timestamp');

        // Create messages spread over the last 30 days
        for ($i = 0; $i < $num_messages; $i++) {
            $sender_id = $this->faker->randomElement($participant_ids);
            $message_text = $this->generateMessageText();
            
            // Generate timestamp - recent messages more likely
            $days_ago = $this->faker->biasedNumberBetween(0, 30, 'sqrt');
            $hours_ago = $this->faker->numberBetween(0, 23);
            $minutes_ago = $this->faker->numberBetween(0, 59);
            
            $timestamp = $now - ($days_ago * DAY_IN_SECONDS) - ($hours_ago * HOUR_IN_SECONDS) - ($minutes_ago * MINUTE_IN_SECONDS);
            $created_date = gmdate('Y-m-d H:i:s', $timestamp);

            try {
                global $wpdb;
                $result = $wpdb->insert(
                    $wpdb->prefix . 'mccon_messages',
                    [
                        'room_id' => $room_id,
                        'sender_id' => $sender_id,
                        'message' => $message_text,
                        'created' => $created_date,
                        'updated' => $created_date,
                    ],
                    ['%d', '%d', '%s', '%s', '%s']
                );
                
                if ($result === false) {
                    \WP_CLI::debug("Error creating message for room {$room_id}: " . $wpdb->last_error);
                } elseif ($wpdb->insert_id) {
                    $created++;
                }
            } catch (\Exception $e) {
                \WP_CLI::debug("Exception creating message: " . $e->getMessage());
            }
        }

        return $created;
    }

    /**
     * Generate a realistic room name based on source type
     *
     * @param string $source_type Source type
     * @return string Room name
     */
    private function generateRoomName(string $source_type): string
    {
        switch ($source_type) {
            case 'coachkit_group':
                $prefixes = ['Elite', 'Advanced', 'Pro', 'Master', 'Premier'];
                $topics = ['Coaching', 'Fitness', 'Nutrition', 'Mindset', 'Business', 'Leadership'];
                $suffixes = ['Group', 'Program', 'Community', 'Circle', 'Academy'];
                
                return sprintf(
                    '%s %s %s',
                    $this->faker->randomElement($prefixes),
                    $this->faker->randomElement($topics),
                    $this->faker->randomElement($suffixes)
                );

            case 'circle':
                $adjectives = ['Private', 'Inner', 'Elite', 'Exclusive', 'VIP'];
                $nouns = ['Circle', 'Community', 'Collective', 'Network', 'Society'];
                
                return sprintf(
                    '%s %s',
                    $this->faker->randomElement($adjectives),
                    $this->faker->randomElement($nouns)
                );

            case 'direct':
                return 'Direct Message';

            default:
                return $this->faker->words(3, true);
        }
    }

    /**
     * Generate realistic message text
     *
     * @return string Message text
     */
    private function generateMessageText(): string
    {
        $message_types = [
            'greeting' => [
                'Hey everyone! 👋',
                'Good morning team!',
                'Hello! Hope you\'re all doing well.',
                'Hi there! 😊',
            ],
            'question' => [
                'Does anyone have experience with this?',
                'What do you all think about the new approach?',
                'Can someone help me understand this better?',
                'Has anyone tried this before?',
                'Quick question - how do you handle this scenario?',
            ],
            'answer' => [
                'Yes, I\'ve had success with that approach.',
                'I think the best way is to start with the basics.',
                'From my experience, this works really well.',
                'I\'d recommend trying the alternative method first.',
                'That\'s a great question! Here\'s what I found...',
            ],
            'update' => [
                'Just wanted to share an update on the progress.',
                'Quick update: Things are moving along nicely!',
                'Making great progress on this!',
                'Here\'s where we\'re at so far...',
                'Completed the first milestone! 🎉',
            ],
            'encouragement' => [
                'Great work everyone! Keep it up! 💪',
                'You\'re doing amazing!',
                'This is fantastic progress!',
                'Really impressive work here!',
                'Love seeing the progress! Keep going! 🚀',
            ],
            'thanks' => [
                'Thanks for sharing!',
                'Really appreciate this!',
                'Thank you so much! This is helpful.',
                'Thanks everyone for the great discussion.',
                'Grateful for all the support! 🙏',
            ],
        ];

        $type = $this->faker->randomElement(array_keys($message_types));
        return $this->faker->randomElement($message_types[$type]);
    }

    /**
     * Clear all Connect messaging data
     *
     * ## OPTIONS
     *
     * [--confirm]
     * : Skip confirmation prompt
     *
     * ## EXAMPLES
     *
     *     wp mccon clear
     *     wp mccon clear --confirm
     *
     * @when after_wp_load
     *
     * @param array $args The command arguments.
     * @param array $assoc_args The command associative arguments.
     */
    public function clear($args, $assoc_args)
    {
        global $wpdb;

        $confirm = isset($assoc_args['confirm']);

        if (!$confirm) {
            \WP_CLI::confirm('This will permanently delete all Connect messaging data (rooms, messages, participants). Are you sure?');
        }

        $tables = [
            $wpdb->prefix . 'mccon_messages',
            $wpdb->prefix . 'mccon_room_participants',
            $wpdb->prefix . 'mccon_rooms',
        ];

        \WP_CLI::line('Clearing Connect messaging data...');

        foreach ($tables as $table) {
            $result = $wpdb->query("TRUNCATE TABLE {$table}");
            
            if ($result === false) {
                \WP_CLI::warning("Failed to clear table: {$table}");
            } else {
                \WP_CLI::line("Cleared table: {$table}");
            }
        }

        \WP_CLI::success('All Connect messaging data cleared!');
    }

    /**
     * Sync display names for all rooms from their sources
     *
     * ## OPTIONS
     *
     * [--source-type=<type>]
     * : Only sync rooms of this source type (e.g., coachkit_group)
     *
     * ## EXAMPLES
     *
     *     # Sync all rooms
     *     wp mccon sync-names
     *
     *     # Sync only CoachKit group rooms
     *     wp mccon sync-names --source-type=coachkit_group
     *
     * @when after_wp_load
     *
     * @param array $args The command arguments.
     * @param array $assoc_args The command associative arguments.
     */
    public function sync_names($args, $assoc_args)
    {
        // Check if Connect plugin is active
        if (!class_exists('membercore\\connect\\Models\\Room')) {
            \WP_CLI::error('MemberCore Connect plugin is not active.');
            return;
        }

        $sourceType = $assoc_args['source-type'] ?? null;

        global $wpdb;
        $prefix = $wpdb->prefix . 'mccon_';

        // Build query
        $query = "SELECT id, source_type, source_id, display_name FROM {$prefix}rooms";
        $params = [];

        if ($sourceType) {
            $query .= " WHERE source_type = %s";
            $params[] = $sourceType;
        }

        $rooms = $wpdb->get_results(
            $params ? $wpdb->prepare($query, ...$params) : $query,
            ARRAY_A
        );

        if (empty($rooms)) {
            \WP_CLI::warning('No rooms found to sync.');
            return;
        }

        \WP_CLI::line(sprintf('Found %d room(s) to sync...', count($rooms)));
        \WP_CLI::line('');

        $synced = 0;
        $skipped = 0;
        $errors = 0;

        $progress = \WP_CLI\Utils\make_progress_bar('Syncing room names', count($rooms));

        foreach ($rooms as $room) {
            $roomId = (int) $room['id'];
            $roomSourceType = $room['source_type'];
            $sourceId = (int) $room['source_id'];
            $currentName = $room['display_name'];

            // Get adapter
            try {
                $container = \membercore\connect\Bootstrap::getContainer();
                $registry = $container->get(\membercore\connect\Services\AdapterRegistry::class);
                $adapter = $registry->get($roomSourceType);

                if (!$adapter) {
                    \WP_CLI::debug("No adapter for source type: {$roomSourceType}");
                    $skipped++;
                    $progress->tick();
                    continue;
                }

                // Get display name from adapter
                $newName = $adapter->getRoomDisplayName($sourceId);

                // Update if changed or was null
                if ($newName && ($currentName !== $newName)) {
                    $result = $wpdb->update(
                        "{$prefix}rooms",
                        ['display_name' => $newName],
                        ['id' => $roomId],
                        ['%s'],
                        ['%d']
                    );

                    if ($result !== false) {
                        $synced++;
                    } else {
                        $errors++;
                    }
                } else {
                    $skipped++;
                }
            } catch (\Exception $e) {
                \WP_CLI::debug("Error syncing room {$roomId}: " . $e->getMessage());
                $errors++;
            }

            $progress->tick();
        }

        $progress->finish();

        \WP_CLI::success('Sync complete!');
        \WP_CLI::line('');
        \WP_CLI::line("Synced: {$synced}");
        \WP_CLI::line("Skipped: {$skipped}");
        if ($errors > 0) {
            \WP_CLI::line("Errors: {$errors}");
        }
    }

    /**
     * Populate messages in existing rooms
     *
     * Deletes existing messages and creates new test messages from all participants.
     * Does NOT delete rooms - only clears and repopulates messages.
     *
     * ## OPTIONS
     *
     * [--messages-per-user=<number>]
     * : Number of messages per participant (default: 3-8 random)
     *
     * [--source-type=<type>]
     * : Source type for rooms to populate (default: coachkit_group)
     *
     * ## EXAMPLES
     *
     *     wp mccon populate-messages
     *     wp mccon populate-messages --messages-per-user=5
     *     wp mccon populate-messages --source-type=coachkit_group
     *
     * @when after_wp_load
     *
     * @param array $args The command arguments.
     * @param array $assoc_args The command associative arguments.
     */
    public function populate_messages($args, $assoc_args)
    {
        // Check if Connect plugin is active
        if (!class_exists('membercore\\connect\\Models\\Room')) {
            \WP_CLI::error('MemberCore Connect plugin is not active.');
            return;
        }

        $messagesPerUser = isset($assoc_args['messages-per-user']) ? (int) $assoc_args['messages-per-user'] : null;
        $sourceType = $assoc_args['source-type'] ?? 'coachkit_group';

        // Get all rooms for the source type
        $rooms = \membercore\connect\Models\Room::where('source_type', '=', $sourceType);

        if (empty($rooms)) {
            \WP_CLI::error("No rooms found with source type: {$sourceType}");
            return;
        }

        \WP_CLI::line("Found " . count($rooms) . " rooms with source type: {$sourceType}");
        \WP_CLI::confirm('This will delete all existing messages in these rooms and create new test messages. Continue?', $assoc_args);

        $progress = \WP_CLI\Utils\make_progress_bar('Processing rooms', count($rooms));
        $totalMessagesCreated = 0;
        $totalParticipants = 0;

        foreach ($rooms as $room) {
            $roomId = $room->getId();

            // Delete existing messages
            \membercore\connect\Models\Message::deleteRoomMessages($roomId);

            // Get all participants in this room
            $participants = \membercore\connect\Models\RoomParticipant::getParticipants($roomId);

            if (empty($participants)) {
                $progress->tick();
                continue;
            }

            $totalParticipants += count($participants);

            // Create messages from each participant
            foreach ($participants as $participant) {
                $userId = (int) $participant['user_id'];
                $messageCount = $messagesPerUser ?? rand(3, 8);

                for ($i = 0; $i < $messageCount; $i++) {
                    $messageText = $this->generateMessageText();
                    
                    // Random time in the past 30 days
                    $days_ago = $this->faker->numberBetween(0, 30);
                    $hours_ago = $this->faker->numberBetween(0, 23);
                    $minutes_ago = $this->faker->numberBetween(0, 59);

                    $now = current_time('timestamp');
                    $timestamp = $now - ($days_ago * DAY_IN_SECONDS) - ($hours_ago * HOUR_IN_SECONDS) - ($minutes_ago * MINUTE_IN_SECONDS);
                    $createdTime = gmdate('Y-m-d H:i:s', $timestamp);

                    \membercore\connect\Models\Message::init([
                        'room_id' => $roomId,
                        'sender_id' => $userId,
                        'message' => $messageText,
                        'created' => $createdTime,
                        'updated' => $createdTime,
                    ])->save();

                    $totalMessagesCreated++;
                }
            }

            // Update room's updated timestamp to reflect latest activity
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'mccon_rooms',
                ['updated' => current_time('mysql', true)],
                ['id' => $roomId],
                ['%s'],
                ['%d']
            );

            $progress->tick();
        }

        $progress->finish();

        \WP_CLI::success(
            sprintf(
                'Populated %d messages across %d rooms (%d participants total)!',
                $totalMessagesCreated,
                count($rooms),
                $totalParticipants
            )
        );
    }
}
