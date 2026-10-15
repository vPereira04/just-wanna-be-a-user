<?php
/**
 * Removes the plugin's settings.
 *
 * @package JustWannaBeAUser
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'jwbau_settings' );
