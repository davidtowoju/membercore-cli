# MemberCore Circles CLI Commands

CLI commands for managing circles and testing the Connect messaging integration.

## Quick Start

```bash
# Create 5 circles with random members
wp mccirc seed --count=5

# List all circles
wp mccirc list

# Clear everything
wp mccirc truncate --confirm
```

## Available Commands

### Create a Circle
```bash
# Create with auto-generated data
wp mccirc create

# Create with specific details
wp mccirc create --title="Developer Circle" --description="For developers only"

# Create with members
wp mccirc create --title="VIP Circle" --members=2,3,4

# Create with specific creator
wp mccirc create --title="My Circle" --creator=5
```

### Add Members
```bash
# Add a member to a circle
wp mccirc add-member 123 456

# Add as moderator
wp mccirc add-member 123 456 --role=mccirc-moderator

# Add as admin
wp mccirc add-member 123 456 --role=mccirc-admin
```

**Available Roles:**
- `mccirc-member` (default)
- `mccirc-moderator`
- `mccirc-admin`

### List Circles
```bash
# Table view (default)
wp mccirc list

# JSON output
wp mccirc list --format=json

# CSV output
wp mccirc list --format=csv

# Specific fields
wp mccirc list --fields=ID,Title,Members
```

### List Circle Members
```bash
# List all members of a circle
wp mccirc list-members 123

# JSON format
wp mccirc list-members 123 --format=json
```

### Delete a Circle
```bash
# Move to trash
wp mccirc delete 123

# Permanently delete
wp mccirc delete 123 --force
```

### Truncate All Circles
```bash
# Delete ALL circles and members (with confirmation)
wp mccirc truncate

# Skip confirmation
wp mccirc truncate --confirm
```

### Seed Fake Data
```bash
# Create 5 circles with random members
wp mccirc seed

# Create 10 circles
wp mccirc seed --count=10

# Create circles with specific member count
wp mccirc seed --count=5 --members=20
```

## Testing Connect Integration

### 1. Set up test data
```bash
# Clear existing circles
wp mccirc truncate --confirm

# Create test circles with members
wp mccirc seed --count=5

# Verify circles were created
wp mccirc list
```

### 2. Test Circle Adapter
The CirclesAdapter is registered in Connect and will automatically create rooms for circles when the Circles plugin calls:
```php
do_action('mccon_messages_init', 'circle');
```

### 3. Check circle members
```bash
# Get circle ID from list
wp mccirc list

# List members of a specific circle
wp mccirc list-members 123
```

### 4. Clean up
```bash
# Remove all test data
wp mccirc truncate --confirm
```

## Examples

### Full Test Workflow
```bash
# 1. Clear everything
wp mccirc truncate --confirm

# 2. Create a specific test circle
wp mccirc create --title="Test Circle" --members=2,3,4,5

# 3. Add a moderator
wp mccirc add-member 123 6 --role=mccirc-moderator

# 4. List to verify
wp mccirc list
wp mccirc list-members 123

# 5. Create bulk test data
wp mccirc seed --count=10

# 6. View all circles
wp mccirc list --format=table
```

### Check Integration
```bash
# See how many circles a user has access to
# This would be used by CirclesAdapter
wp user get 2 --fields=ID,user_login

# List all circles to see what rooms should appear
wp mccirc list --format=json
```

## Notes

- All commands require MemberCore Circles plugin to be active
- Circle members are stored in `wp_mccirc_circle_members` table
- Circles use the `mc-circle` post type
- `truncate` deletes both circles AND their members
- `seed` creates realistic test data with random members and roles
