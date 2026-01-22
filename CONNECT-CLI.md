# MemberCore Connect CLI Commands

WP-CLI commands for managing the MemberCore Connect messaging system.

## Installation

The Connect commands are available through the `membercore-cli` plugin. Ensure both plugins are active:

```bash
wp plugin activate membercore-connect membercore-cli
```

## Available Commands

### `wp mccon seed`

Seed the Connect messaging system with sample data for testing and development.

**Options:**

- `--rooms=<number>` - Number of rooms to create (default: 5)
- `--messages-per-room=<number>` - Messages per room (default: 10-30, random)
- `--participants-per-room=<number>` - Participants per room (default: 2-5, random)
- `--source-type=<type>` - Room source type: `coachkit_group`, `circle`, `direct`, or `mixed` (default: mixed)
- `--user-id=<id>` - Add specific user to all rooms
- `--with-coachkit` - Link rooms to existing CoachKit groups

**Examples:**

```bash
# Create 5 rooms with default settings
wp mccon seed

# Create 10 rooms with 20 messages each
wp mccon seed --rooms=10 --messages-per-room=20

# Create rooms for a specific user
wp mccon seed --user-id=1 --rooms=3

# Create rooms linked to CoachKit groups
wp mccon seed --with-coachkit --rooms=5

# Create direct message rooms only
wp mccon seed --source-type=direct --rooms=10 --participants-per-room=2
```

**What it creates:**

- ✅ Rooms with realistic names based on source type
- ✅ Messages with varied timestamps (spread over last 30 days)
- ✅ Room participants (users assigned to rooms)
- ✅ Realistic message conversations with questions, answers, greetings, etc.
- ✅ Optional CoachKit integration

### `wp mccon clear`

Clear all Connect messaging data (rooms, messages, participants).

**Options:**

- `--confirm` - Skip confirmation prompt

**Examples:**

```bash
# Clear all data (with confirmation)
wp mccon clear

# Clear without confirmation
wp mccon clear --confirm
```

**Warning:** This permanently deletes all messaging data. Use with caution!

## Workflow Examples

### Quick Setup for Development

```bash
# 1. Clear existing data
wp mccon clear --confirm

# 2. Seed with sample data
wp mccon seed --rooms=10 --messages-per-room=15

# 3. View in browser
# Add shortcode to page: [membercore_messages source_type="coachkit_group"]
```

### Testing with CoachKit

```bash
# 1. Create CoachKit programs and groups
wp mpch seed --programs=5

# 2. Create messaging rooms linked to those groups
wp mccon seed --with-coachkit --rooms=10 --messages-per-room=20

# 3. Test the messaging UI with real group data
```

### Testing with Specific User

```bash
# 1. Get your user ID
wp user get admin --field=ID

# 2. Create rooms with your user included
wp mccon seed --user-id=1 --rooms=5 --messages-per-room=30

# 3. Log in and view your conversations
```

### Production-Like Data

```bash
# Create realistic messaging data with varied activity
wp mccon seed \
  --rooms=25 \
  --source-type=mixed \
  --with-coachkit \
  --participants-per-room=4
```

## Integration with Other Seeders

### Complete MemberCore Stack

```bash
# 1. Seed coaching programs
wp mpch seed --programs=7 --assign-memberships

# 2. Seed connect messaging (linked to coaching groups)
wp mccon seed --with-coachkit --rooms=15

# 3. You now have fully integrated test data
```

## Troubleshooting

### Command not found

```bash
# Check if plugins are active
wp plugin list --status=active

# Activate if needed
wp plugin activate membercore-cli membercore-connect
```

### No CoachKit groups found

```bash
# Create CoachKit groups first
wp mpch seed --programs=5

# Then seed Connect with CoachKit integration
wp mccon seed --with-coachkit
```

### No users found

```bash
# Create some test users first
wp user create testuser1 test1@example.com --role=subscriber
wp user create testuser2 test2@example.com --role=subscriber

# Then seed messaging
wp mccon seed --rooms=5
```

## Tips

1. **Start Fresh:** Use `wp mccon clear --confirm && wp mccon seed` for clean test data
2. **Varied Data:** Use `--source-type=mixed` for diverse room types
3. **CoachKit Integration:** Always seed CoachKit first when using `--with-coachkit`
4. **Realistic Timestamps:** Messages are distributed over the last 30 days with recent bias
5. **Progressive Loading:** Messages use realistic patterns - not all rooms equally active

## Future Enhancements

Planned features:

- Mark messages as read/unread
- Add attachments to messages
- Create message reactions
- Set room archive status
- Bulk operations (archive, delete specific rooms)
- Import from JSON file

## See Also

- [Connect Architecture Documentation](../membercore-connect/docs/architecture.md)
- [CoachKit CLI Commands](../membercore-cli/README.md)
- [WP-CLI Documentation](https://wp-cli.org/)
