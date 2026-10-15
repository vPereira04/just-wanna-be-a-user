<?php
/**
 * Switching into another user's session and back again.
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
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
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
	 * Logs the admin in as the chosen user and drops them on the page picked in Tools.
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

		wp_safe_redirect( JWBAU_Settings::url( 'switch_to' ) );
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
					'link_url'  => esc_url( wp_login_url( JWBAU_Settings::url( 'switch_back' ) ) ),
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

		wp_safe_redirect( JWBAU_Settings::url( 'switch_back' ) );
		exit;
	}

	/**
	 * Adds the floating bar's styles and script while an admin is switched.
	 */
	public static function enqueue_assets(): void {
		if ( ! self::origin_user_id() ) {
			return;
		}

		self::register_assets();
		wp_enqueue_style( 'jwbau-return' );
		wp_enqueue_script( 'jwbau-return' );
	}

	/**
	 * Prints the "Go back to admin" bar at the top center of the page.
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

		$minimize = __( 'Minimize', 'just-wanna-be-a-user' );
		$expand   = __( 'Expand', 'just-wanna-be-a-user' );
		$back     = __( 'Go back to admin', 'just-wanna-be-a-user' );
		?>
		<section id="jwbau-return" class="jwbau-return" aria-label="<?php esc_attr_e( 'Logged in as another user', 'just-wanna-be-a-user' ); ?>">
			<span class="jwbau-return__grip" aria-hidden="true" title="<?php esc_attr_e( 'Drag to move. Double-click to put it back.', 'just-wanna-be-a-user' ); ?>">
				<svg viewBox="0 0 16 16"><circle cx="5.5" cy="3.5" r="1.25"/><circle cx="10.5" cy="3.5" r="1.25"/><circle cx="5.5" cy="8" r="1.25"/><circle cx="10.5" cy="8" r="1.25"/><circle cx="5.5" cy="12.5" r="1.25"/><circle cx="10.5" cy="12.5" r="1.25"/></svg>
			</span>
			<span class="jwbau-return__label">
				<?php
				printf(
					/* translators: %s: display name of the user being viewed as. */
					esc_html__( 'Viewing as %s', 'just-wanna-be-a-user' ),
					'<strong>' . esc_html( wp_get_current_user()->display_name ) . '</strong>'
				);
				?>
			</span>
			<a class="jwbau-return__link" href="<?php echo esc_url( $back_url ); ?>" title="<?php echo esc_attr( $back ); ?>">
				<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M6.5 4 2.5 8l4 4M3 8h7.5a3 3 0 0 1 0 6H9"/></svg>
				<span class="jwbau-return__text"><?php echo esc_html( $back ); ?></span>
			</a>
			<button type="button" class="jwbau-return__toggle" aria-expanded="true" aria-label="<?php echo esc_attr( $minimize ); ?>" title="<?php echo esc_attr( $minimize ); ?>" data-minimize="<?php echo esc_attr( $minimize ); ?>" data-expand="<?php echo esc_attr( $expand ); ?>">
				<svg class="jwbau-return__icon-min" viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 8h9"/></svg>
				<svg class="jwbau-return__icon-max" viewBox="0 0 16 16" aria-hidden="true"><path d="M9.5 2.5h4v4M6.5 13.5h-4v-4M13.5 2.5 9 7M2.5 13.5 7 9"/></svg>
			</button>
		</section>
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

		// The page already ended, so I print the styles and script alongside the bar.
		self::register_assets();
		wp_print_styles( 'jwbau-return' );
		self::render_back_button();
		wp_print_scripts( 'jwbau-return' );
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
		// Strictly necessary: it's part of the admin's login, set only when they click to switch, so it needs no consent.
		setcookie(
			self::cookie_name(),
			$value,
			array(
				'expires'  => $expires,
				'path'     => defined( 'COOKIEPATH' ) ? (string) COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? (string) COOKIE_DOMAIN : '',
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
	 * Registers the bar's inline stylesheet and script.
	 */
	private static function register_assets(): void {
		if ( wp_style_is( 'jwbau-return', 'registered' ) ) {
			return;
		}

		wp_register_style( 'jwbau-return', false, array(), '1.0.0' );
		wp_add_inline_style( 'jwbau-return', self::styles() );

		wp_register_script( 'jwbau-return', false, array(), '1.0.0', true );
		wp_add_inline_script( 'jwbau-return', self::script() );
	}

	/**
	 * Cookie name, scoped to this site like WP's own auth cookies.
	 */
	private static function cookie_name(): string {
		// WP always defines it before plugins load; the fallback is core's own formula.
		return 'jwbau_origin_' . ( defined( 'COOKIEHASH' ) ? (string) COOKIEHASH : md5( site_url() ) );
	}

	/**
	 * Styles for the floating bar. I pin colors and fonts so themes can't restyle it.
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
	margin: 0;
	padding: 6px 6px 6px 16px;
	transform: translateX(-50%);
	border-radius: 24px;
	background: #1d2327;
	color: #f0f0f1;
	box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25);
	text-align: center;
	font: 400 14px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
}
.jwbau-return svg {
	display: block;
	flex: none;
	width: 16px;
	height: 16px;
	fill: none;
	stroke: currentColor;
	stroke-width: 1.75;
	stroke-linecap: round;
	stroke-linejoin: round;
}
.jwbau-return__label strong {
	color: #fff;
	font-weight: 600;
}
.jwbau-return a.jwbau-return__link {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 6px 14px 6px 10px;
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
.jwbau-return button.jwbau-return__toggle {
	display: none;
	align-items: center;
	justify-content: center;
	box-sizing: border-box;
	width: 28px;
	height: 28px;
	min-width: 0;
	min-height: 0;
	margin: 0;
	padding: 0;
	border: 0;
	border-radius: 50%;
	background: transparent;
	box-shadow: none;
	color: #c3c4c7;
	line-height: 0;
	cursor: pointer;
}
.jwbau-return button.jwbau-return__toggle:hover {
	background: rgba(255, 255, 255, 0.12);
	color: #fff;
}
.jwbau-return a.jwbau-return__link:focus-visible,
.jwbau-return button.jwbau-return__toggle:focus-visible {
	outline: 2px solid #72aee6;
	outline-offset: 2px;
}
.jwbau-return__grip {
	display: none;
	margin-right: -4px;
	color: #8c8f94;
}
.jwbau-return__grip svg {
	fill: currentColor;
	stroke: none;
}
.jwbau-return .jwbau-return__icon-max {
	display: none;
}

/* Moving and minimizing need the script, so I only offer them once it runs. */
.jwbau-return--js {
	padding-left: 10px;
	cursor: grab;
	touch-action: none;
	-webkit-user-select: none;
	user-select: none;
}
.jwbau-return--js .jwbau-return__grip {
	display: block;
}
.jwbau-return.jwbau-return--js button.jwbau-return__toggle {
	display: inline-flex;
}
/* Once placed, I size it to its content so the right edge can not squash it. */
.jwbau-return--placed {
	width: max-content;
	transform: none;
}
.jwbau-return--dragging {
	cursor: grabbing;
	box-shadow: 0 8px 28px rgba(0, 0, 0, 0.35);
}

.jwbau-return--min {
	gap: 4px;
	padding: 4px 4px 4px 8px;
}
.jwbau-return--min .jwbau-return__label {
	display: none;
}
.jwbau-return--min .jwbau-return__text {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	white-space: nowrap;
}
.jwbau-return--min a.jwbau-return__link,
.jwbau-return--min button.jwbau-return__toggle {
	justify-content: center;
	width: 24px;
	height: 24px;
	padding: 0;
}
.jwbau-return--min .jwbau-return__icon-min {
	display: none;
}
.jwbau-return--min .jwbau-return__icon-max {
	display: block;
}

@media (max-width: 600px) {
	.jwbau-return { padding: 8px; }
	.jwbau-return--min { padding: 4px 4px 4px 8px; }
}
@media print {
	.jwbau-return { display: none; }
}';
	}

	/**
	 * Minimize/expand and drag-to-move. I keep the state in localStorage so it sticks across pages.
	 */
	private static function script(): string {
		return <<<'JS'
( function () {
	var bar = document.getElementById( 'jwbau-return' );
	if ( ! bar ) {
		return;
	}

	var toggle = bar.querySelector( '.jwbau-return__toggle' );
	var KEY = 'jwbau_bar';
	var state = {};
	var drag = null;

	try {
		state = JSON.parse( window.localStorage.getItem( KEY ) ) || {};
	} catch ( e ) {}

	function save() {
		try {
			window.localStorage.setItem( KEY, JSON.stringify( state ) );
		} catch ( e ) {}
	}

	function isPlaced() {
		return typeof state.x === 'number' && typeof state.y === 'number';
	}

	function setMinimized( min ) {
		var label = toggle.getAttribute( min ? 'data-expand' : 'data-minimize' );
		bar.classList.toggle( 'jwbau-return--min', min );
		toggle.setAttribute( 'aria-expanded', min ? 'false' : 'true' );
		toggle.setAttribute( 'aria-label', label );
		toggle.title = label;
	}

	// Keeps the bar fully on screen.
	function place( x, y ) {
		bar.classList.add( 'jwbau-return--placed' );
		var rect = bar.getBoundingClientRect();
		var maxX = document.documentElement.clientWidth - Math.ceil( rect.width );
		var maxY = document.documentElement.clientHeight - Math.ceil( rect.height );
		x = Math.round( Math.min( Math.max( 0, x ), Math.max( 0, maxX ) ) );
		y = Math.round( Math.min( Math.max( 0, y ), Math.max( 0, maxY ) ) );
		bar.style.left = x + 'px';
		bar.style.top = y + 'px';
		return { x: x, y: y };
	}

	function unplace() {
		delete state.x;
		delete state.y;
		bar.classList.remove( 'jwbau-return--placed' );
		bar.style.left = '';
		bar.style.top = '';
	}

	bar.classList.add( 'jwbau-return--js' );
	setMinimized( !! state.min );
	if ( isPlaced() ) {
		place( state.x, state.y );
	}

	toggle.addEventListener( 'click', function () {
		state.min = ! bar.classList.contains( 'jwbau-return--min' );
		setMinimized( state.min );
		// Expanding near the right edge would push it off screen.
		if ( isPlaced() ) {
			place( state.x, state.y );
		}
		save();
	} );

	bar.addEventListener( 'pointerdown', function ( event ) {
		if ( event.button !== 0 || event.target.closest( 'a, button' ) ) {
			return;
		}
		var rect = bar.getBoundingClientRect();
		drag = { dx: event.clientX - rect.left, dy: event.clientY - rect.top };
		bar.setPointerCapture( event.pointerId );
		bar.classList.add( 'jwbau-return--dragging' );
		event.preventDefault();
	} );

	bar.addEventListener( 'pointermove', function ( event ) {
		if ( ! drag ) {
			return;
		}
		var spot = place( event.clientX - drag.dx, event.clientY - drag.dy );
		state.x = spot.x;
		state.y = spot.y;
	} );

	function endDrag() {
		if ( ! drag ) {
			return;
		}
		drag = null;
		bar.classList.remove( 'jwbau-return--dragging' );
		save();
	}
	bar.addEventListener( 'pointerup', endDrag );
	bar.addEventListener( 'pointercancel', endDrag );

	bar.addEventListener( 'dblclick', function ( event ) {
		if ( event.target.closest( 'a, button' ) ) {
			return;
		}
		unplace();
		save();
	} );

	window.addEventListener( 'resize', function () {
		if ( isPlaced() ) {
			place( state.x, state.y );
		}
	} );
} )();
JS;
	}
}
