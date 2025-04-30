# Vibe Payment Gateway Tracking System

This document explains the plugin activation tracking system implemented in the WooCommerce Vibe Payment Gateway.

## Overview

The tracking system allows you to monitor which websites have installed and activated your plugin. It uses a combination of two methods:

1. **Activation/Deactivation Tracking**: Sends data when a site activates or deactivates the plugin
2. **Heartbeat Pings**: Regularly sends "still alive" signals to confirm the plugin remains active

## How It Works

### Data Collection

The system collects only essential information:
- Site URL
- Site name
- Admin email
- WordPress version
- WooCommerce version
- PHP version
- Plugin version
- Activation status

### Tracking Events

The system sends data to the Vibe tracking API on these events:
- **Activation**: When the plugin is activated
- **Deactivation**: When the plugin is deactivated
- **Heartbeat**: Weekly check to confirm the plugin is still active
- **Uninstall**: When the plugin is completely removed (if possible)

### Performance Considerations

The tracking system is designed to be lightweight:

- Uses non-blocking HTTP requests (0.01 second timeout)
- Caches site data for 6 hours to avoid repeated processing
- Schedules heartbeats weekly using WordPress cron
- Cleans up all data on uninstall

## API Implementation

The tracking system sends data to the Vibe API at `https://credit.vibe.ir/api/v1/tracking/`.

### API Endpoints

The system uses these endpoints:
- `/activation` - Receives activation events
- `/deactivation` - Receives deactivation events
- `/heartbeat` - Receives weekly heartbeat pings
- `/uninstall` - Receives uninstall events

### API Authentication

The system uses the Vibe API key for authentication, which is:
1. Read from the `vibe_api_key` option OR
2. Fetched from the WooCommerce Vibe payment gateway settings

### API Request Format

Example API request:

```json
{
  "url": "https://example.com",
  "name": "Example Store",
  "email": "admin@example.com",
  "wp_version": "6.2.3",
  "wc_version": "7.8.0",
  "php_version": "8.1.12",
  "plugin_version": "1.0.1",
  "is_active": true,
  "event": "activation"
}
```

## Customizing the Tracking System

To modify what data is collected or how often heartbeats are sent:

1. Edit `includes/class-wc-vibe-tracker.php`
2. Modify the `get_tracking_data()` method to change what data is collected
3. Change the schedule in `register_heartbeat()` if you want more/less frequent pings

## Privacy Considerations

The current implementation collects identifiable information (site URL, admin email). Consider:

1. Creating a privacy policy explaining what data is collected
2. Making tracking optional via a settings checkbox
3. Adding data anonymization options if needed for GDPR compliance

## Troubleshooting

If tracking data is not being received:

1. Check that a valid API key exists in either the `vibe_api_key` option or WooCommerce Vibe payment settings
2. Verify the tracking server is accessible from the client site
3. Enable WordPress debug logging to see if HTTP requests are failing
4. Confirm WordPress cron is functioning properly for heartbeat events 