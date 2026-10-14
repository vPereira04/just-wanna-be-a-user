<?php
/**
 * Plugin Name:       Just Wanna Be a User
 * Description:       Lets admins log in as any user from the Users screen, then jump straight back to their own account.
 * Version:           1.0.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            Victor Pereira
 * License:         GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       just-wanna-be-a-user
 * Domain Path:       /languages
 *
 * @package JustWannaBeAUser
 */

defined( 'ABSPATH' ) || exit;

/**
 * Switches an admin into another user's session and back again.
 */
final class JWBAU_User_Switcher {

	const SWITCH_ACTION = 'jwbau_switch_to';
	const BACK_ACTION   = 'jwbau_switch_back';

	// The HMAC salt I sign the admin's stashed session with.
	const COOKIE_SCHEME = 'secure_auth';

	/**
	 * Whether the button already went out on this request.
	 *
	 * @var bool
	 */
	private static $rendered = false;

	/**
	 * Wires up the hooks.
	 */
	public static function init(): void {
		add_filter( 'user_row_actions', array( __CLASS__, 'add_row_action' ), 10, 2 );
		add_action( 'admin_post_' . self::SWITCH_ACTION, array( __CLASS__, 'switch_to_user' ) );
		// Early, so maintenance-mode plugins blocking on init can't trap the admin.
		add_action( 'init', array( __CLASS__, 'maybe_switch_back' ), 0 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_back_button' ) );
		add_action( 'admin_footer', array( __CLASS__, 'render_back_button' ) );
		add_action( 'shutdown', array( __CLASS__, 'render_fallback_button' ) );
		add_action( 'wp_logout', array( __CLASS__, 'clear_origin_cookie' ) );
	}

	/**
	 * Adds "Log in as this user" under each user on the Users screen.
	 *
	 * @param array<string, string> $actions Row action links.
	 * @param WP_User               $user    The user in this row.
	 * @return array<string, string>
	 */
	public static function add_row_action( array $actions, WP_User $user ): array {
		if ( get_current_user_id() === $user->ID || ! current_user_can( 'edit_user', $user->ID ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => self::SWITCH_ACTION,
					'user_id' => $user->ID,
				),
				admin_url( 'admin-post.php' )
			),
			self::SWITCH_ACTION . '_' . $user->ID
		);

		$actions['jwbau_switch'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $url ),
			esc_html__( 'Log in as this user', 'just-wanna-be-a-user' )
		);

		return $actions;
	}

	/**
	 * Logs the admin in as the chosen user and drops them on the homepage.
	 */
	public static function switch_to_user(): void {
		$target_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		check_admin_referer( self::SWITCH_ACTION . '_' . $target_id );

		$origin_id = get_current_user_id();
		$target    = get_userdata( $target_id );

		if ( ! $target || $target->ID === $origin_id || ! current_user_can( 'edit_user', $target->ID ) ) {
			wp_die(
				esc_html__( 'You are not allowed to log in as this user.', 'just-wanna-be-a-user' ),
				'',
				array( 'response' => 403 )
			);
		}

		// I leave the admin's own session alive and stash a signed pointer to it, so going back needs no password.
		$origin_cookie = wp_generate_auth_cookie(
			$origin_id,
			time() + 2 * DAY_IN_SECONDS,
			self::COOKIE_SCHEME,
			wp_get_session_token()
		);

		wp_clear_auth_cookie();
		wp_set_current_user( $target->ID );
		wp_set_auth_cookie( $target->ID, false );
		self::set_origin_cookie( $origin_cookie );

		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	/**
	 * Handles the "Go back to admin" link.
	 */
	public static function maybe_switch_back(): void {
		if ( ! isset( $_GET[ self::BACK_ACTION ] ) ) {
			return;
		}

		$nonce     = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		$origin_id = self::origin_user_id();

		if ( ! $origin_id || ! wp_verify_nonce( $nonce, self::BACK_ACTION ) ) {
			wp_die(
				esc_html__( 'This link has expired. Please log in to your admin account again.', 'just-wanna-be-a-user' ),
				'',
				array(
					'response'  => 403,
					'link_url'  => esc_url( wp_login_url( admin_url( 'users.php' ) ) ),
					'link_text' => esc_html__( 'Log in', 'just-wanna-be-a-user' ),
				)
			);
		}

		$origin = wp_parse_auth_cookie( self::raw_origin_cookie(), self::COOKIE_SCHEME );
		$token  = $origin ? $origin['token'] : '';

		// The borrowed session shouldn't linger on the user's account.
		wp_destroy_current_session();
		wp_clear_auth_cookie();
		self::clear_origin_cookie();

		wp_set_current_user( $origin_id );
		wp_set_auth_cookie( $origin_id, false, '', $token );

		wp_safe_redirect( admin_url( 'users.php' ) );
		exit;
	}

	/**
	 * Adds the floating button's styles while an admin is switched.
	 */
	public static function enqueue_styles(): void {
		if ( ! self::origin_user_id() ) {
			return;
		}

		self::register_styles();
		wp_enqueue_style( 'jwbau-return' );
	}

	/**
	 * Prints the "Go back to admin" button at the top center of the page.
	 */
	public static function render_back_button(): void {
		if ( self::$rendered || ! self::origin_user_id() ) {
			return;
		}
		self::$rendered = true;

		$back_url = add_query_arg(
			array(
				self::BACK_ACTION => 1,
				'_wpnonce'        => wp_create_nonce( self::BACK_ACTION ),
			),
			home_url( '/' )
		);
		?>
		<div class="jwbau-return" role="region" aria-label="<?php esc_attr_e( 'Logged in as another user', 'just-wanna-be-a-user' ); ?>">
			<span class="jwbau-return__label">
				<?php
				printf(
					/* translators: %s: display name of the user being viewed as. */
					esc_html__( 'Viewing as %s', 'just-wanna-be-a-user' ),
					'<strong>' . esc_html( wp_get_current_user()->display_name ) . '</strong>'
				);
				?>
			</span>
			<a class="jwbau-return__link" href="<?php echo esc_url( $back_url ); ?>">
				<?php esc_html_e( 'Go back to admin', 'just-wanna-be-a-user' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Catches pages that exit before wp_footer, like maintenance screens or wp_die().
	 */
	public static function render_fallback_button(): void {
		if ( self::$rendered || wp_doing_ajax() || wp_doing_cron() || ! self::is_html_response() ) {
			return;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return;
		}
		if ( ! self::origin_user_id() ) {
			return;
		}

		// The page already ended, so I print the styles alongside the button.
		self::register_styles();
		wp_print_styles( 'jwbau-return' );
		self::render_back_button();
	}

	/**
	 * Forgets the stashed admin session.
	 */
	public static function clear_origin_cookie(): void {
		self::set_origin_cookie( '', time() - YEAR_IN_SECONDS );
	}

	/**
	 * Returns the admin's ID while they're switched into someone else, 0 otherwise.
	 */
	private static function origin_user_id(): int {
		$cookie = self::raw_origin_cookie();
		if ( '' === $cookie || ! is_user_logged_in() ) {
			return 0;
		}

		$origin_id = (int) wp_validate_auth_cookie( $cookie, self::COOKIE_SCHEME );

		return get_current_user_id() !== $origin_id ? $origin_id : 0;
	}

	/**
	 * Reads the stashed cookie; wp_validate_auth_cookie() checks its signature.
	 */
	private static function raw_origin_cookie(): string {
		$name = self::cookie_name();

		return isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';
	}

	/**
	 * Writes the stashed cookie, readable on both the site and wp-admin.
	 *
	 * @param string $value   Signed cookie value.
	 * @param int    $expires Unix timestamp; 0 keeps it for the browser session.
	 */
	private static function set_origin_cookie( string $value, int $expires = 0 ): void {
		setcookie(
			self::cookie_name(),
			$value,
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH,
				'domain'   => (string) COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Whether this response is an HTML page I can safely append to.
	 */
	private static function is_html_response(): bool {
		$status = (int) http_response_code();
		if ( $status >= 300 && $status < 400 ) {
			return false;
		}

		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'content-type:' ) ) {
				return false !== stripos( $header, 'text/html' );
			}
		}

		// No Content-Type sent means PHP's default, text/html. CLI never sends headers.
		return 'cli' !== PHP_SAPI;
	}

	/**
	 * Registers the button's inline stylesheet.
	 */
	private static function register_styles(): void {
		if ( wp_style_is( 'jwbau-return', 'registered' ) ) {
			return;
		}

		wp_register_style( 'jwbau-return', false, array(), '1.0.0' );
		wp_add_inline_style( 'jwbau-return', self::styles() );
	}

	/**
	 * Cookie name, scoped to this site like WP's own auth cookies.
	 */
	private static function cookie_name(): string {
		return 'jwbau_origin_' . COOKIEHASH;
	}

	/**
	 * Styles for the floating button. I pin colors and fonts so themes can't restyle it.
	 */
	private static function styles(): string {
		return '
.jwbau-return {
	position: fixed;
	top: calc(var(--wp-admin--admin-bar--height, 0px) + 12px);
	left: 50%;
	z-index: 100000;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: center;
	gap: 8px 12px;
	box-sizing: border-box;
	max-width: calc(100% - 32px);
	padding: 6px 6px 6px 16px;
	transform: translateX(-50%);
	border-radius: 24px;
	background: #1d2327;
	color: #f0f0f1;
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25);
	text-align: center;
	font: 400 14px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
}
.jwbau-return__label strong {
	color: #fff;
	font-weight: 600;
}
.jwbau-return a.jwbau-return__link {
	display: inline-block;
	padding: 6px 14px;
	border-radius: 999px;
	background: #2271b1;
	color: #fff;
	font: inherit;
	font-weight: 600;
	text-decoration: none;
	white-space: nowrap;
}
.jwbau-return a.jwbau-return__link:hover {
	background: #135e96;
	color: #fff;
}
.jwbau-return a.jwbau-return__link:focus-visible {
	outline: 2px solid #72aee6;
	outline-offset: 2px;
}
@media (max-width: 600px) {
	.jwbau-return { padding: 8px; }
}
@media print {
	.jwbau-return { display: none; }
}';
	}
}

JWBAU_User_Switcher::init();
