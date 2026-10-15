<?php
/**
 * Plugin Name:       Just Wanna Be a User
 * Description:       Lets admins log in as any user from the Users screen, then jump straight back to their own account.
 * Version:           1.0.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            Victor Pereira
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       just-wanna-be-a-user
 * Domain Path:       /languages
 *
 * @package JustWannaBeAUser
 */

defined( 'ABSPATH' ) || exit;

define( 'JWBAU_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-jwbau-settings.php';
require_once __DIR__ . '/includes/class-jwbau-user-switcher.php';

JWBAU_Settings::init();
JWBAU_User_Switcher::init();
