<?php
/**
 * Tools > Just Wanna Be a User: where the admin lands when switching in and back.
 *
 * @package JustWannaBeAUser
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores and resolves the two landing pages.
 */
final class JWBAU_Settings {

	const OPTION    = 'jwbau_settings';
	const PAGE_SLUG = 'just-wanna-be-a-user';

	/**
	 * Wires up the hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( JWBAU_FILE ), array( __CLASS__, 'add_plugin_link' ) );
	}

	/**
	 * Adds the page under Tools.
	 */
	public static function add_page(): void {
		$hook = add_management_page(
			__( 'Just Wanna Be a User', 'just-wanna-be-a-user' ),
			__( 'Just Wanna Be a User', 'just-wanna-be-a-user' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);

		if ( $hook ) {
			add_action( 'admin_print_footer_scripts-' . $hook, array( __CLASS__, 'print_page_script' ) );
		}
	}

	/**
	 * Registers the option and its two fields.
	 */
	public static function register(): void {
		register_setting(
			self::PAGE_SLUG,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section( 'jwbau_landing', __( 'Where you land', 'just-wanna-be-a-user' ), '__return_null', self::PAGE_SLUG );

		add_settings_field(
			'jwbau_switch_to',
			__( 'After logging in as a user', 'just-wanna-be-a-user' ),
			array( __CLASS__, 'render_field' ),
			self::PAGE_SLUG,
			'jwbau_landing',
			array(
				'key'         => 'switch_to',
				'description' => __( 'Where you are taken right after clicking "Log in as this user".', 'just-wanna-be-a-user' ),
			)
		);

		add_settings_field(
			'jwbau_switch_back',
			__( 'After going back to admin', 'just-wanna-be-a-user' ),
			array( __CLASS__, 'render_field' ),
			self::PAGE_SLUG,
			'jwbau_landing',
			array(
				'key'         => 'switch_back',
				'description' => __( 'Where you are taken when you click "Go back to admin".', 'just-wanna-be-a-user' ),
			)
		);
	}

	/**
	 * Adds a "Settings" link on the Plugins screen.
	 *
	 * @param array<string, string> $links Plugin action links.
	 * @return array<string, string>
	 */
	public static function add_plugin_link( array $links ): array {
		$link = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Settings', 'just-wanna-be-a-user' )
		);

		return array( 'settings' => $link ) + $links;
	}

	/**
	 * Prints the settings screen.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::PAGE_SLUG );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Prints one radio group: the presets, then a custom path.
	 *
	 * @param array{key: string, description: string} $args Field args.
	 */
	public static function render_field( array $args ): void {
		$key      = $args['key'];
		$settings = self::get();
		$name     = self::OPTION . '[' . $key . ']';
		$custom   = self::OPTION . '[' . $key . '_custom]';
		?>
		<fieldset>
			<?php foreach ( self::destinations()[ $key ] as $value => $destination ) : ?>
				<label>
					<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $settings[ $key ], $value ); ?> />
					<?php echo esc_html( $destination['label'] ); ?>
				</label>
				<code><?php echo esc_html( $destination['url'] ); ?></code>
				<?php if ( 'custom' === $value ) : ?>
					<input
						type="text"
						class="regular-text code jwbau-custom-path"
						name="<?php echo esc_attr( $custom ); ?>"
						value="<?php echo esc_attr( $settings[ $key . '_custom' ] ); ?>"
						aria-label="<?php esc_attr_e( 'Custom path', 'just-wanna-be-a-user' ); ?>"
					/>
				<?php endif; ?>
				<br />
			<?php endforeach; ?>
			<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
		</fieldset>
		<?php
	}

	/**
	 * Picks "Custom" when the admin starts typing a path, like the Permalinks screen does.
	 */
	public static function print_page_script(): void {
		wp_print_inline_script_tag(
			"document.querySelectorAll( '.jwbau-custom-path' ).forEach( function ( input ) {
				input.addEventListener( 'focus', function () {
					input.closest( 'fieldset' ).querySelector( 'input[value=\"custom\"]' ).checked = true;
				} );
			} );"
		);
	}

	/**
	 * Keeps only known choices and same-site paths.
	 *
	 * @param mixed $input Raw form values.
	 * @return array<string, string>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$clean = self::defaults();

		foreach ( self::destinations() as $key => $choices ) {
			$choice = isset( $input[ $key ] ) ? sanitize_key( $input[ $key ] ) : '';
			if ( isset( $choices[ $choice ] ) ) {
				$clean[ $key ] = $choice;
			}

			$path = isset( $input[ $key . '_custom' ] ) ? (string) $input[ $key . '_custom' ] : '';

			$clean[ $key . '_custom' ] = self::sanitize_path( $path, $choices['custom']['url'] );
		}

		return $clean;
	}

	/**
	 * Full URL for "switch_to" or "switch_back", falling back to the default if it's off-site.
	 *
	 * @param string $key "switch_to" or "switch_back".
	 */
	public static function url( string $key ): string {
		$settings     = self::get();
		$destinations = self::destinations()[ $key ];
		$choice       = $settings[ $key ];
		$fallback     = reset( $destinations )['url'];

		$url = $destinations[ $choice ]['url'];
		if ( 'custom' === $choice ) {
			$url .= $settings[ $key . '_custom' ];
		}

		return wp_validate_redirect( $url, $fallback );
	}

	/**
	 * The presets for each landing. The first one is the default; "custom" is the base the path is added to.
	 *
	 * @return array<string, array<string, array{label: string, url: string}>>
	 */
	private static function destinations(): array {
		return array(
			'switch_to'   => array(
				'home'      => array(
					'label' => __( 'Site homepage', 'just-wanna-be-a-user' ),
					'url'   => home_url( '/' ),
				),
				'dashboard' => array(
					'label' => __( 'Dashboard', 'just-wanna-be-a-user' ),
					'url'   => admin_url(),
				),
				'profile'   => array(
					'label' => __( 'Profile', 'just-wanna-be-a-user' ),
					'url'   => admin_url( 'profile.php' ),
				),
				'custom'    => array(
					'label' => __( 'Custom page', 'just-wanna-be-a-user' ),
					'url'   => home_url( '/' ),
				),
			),
			'switch_back' => array(
				'users'     => array(
					'label' => __( 'Users screen', 'just-wanna-be-a-user' ),
					'url'   => admin_url( 'users.php' ),
				),
				'dashboard' => array(
					'label' => __( 'Dashboard', 'just-wanna-be-a-user' ),
					'url'   => admin_url(),
				),
				'home'      => array(
					'label' => __( 'Site homepage', 'just-wanna-be-a-user' ),
					'url'   => home_url( '/' ),
				),
				'custom'    => array(
					'label' => __( 'Custom admin page', 'just-wanna-be-a-user' ),
					'url'   => admin_url(),
				),
			),
		);
	}

	/**
	 * Saved settings merged over the defaults.
	 *
	 * @return array<string, string>
	 */
	private static function get(): array {
		$saved = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Homepage on the way in, Users screen on the way back.
	 *
	 * @return array<string, string>
	 */
	private static function defaults(): array {
		return array(
			'switch_to'          => 'home',
			'switch_to_custom'   => '',
			'switch_back'        => 'users',
			'switch_back_custom' => '',
		);
	}

	/**
	 * Turns what the admin typed into a path relative to $base. A pasted full URL on this site is fine too.
	 *
	 * @param string $path What was typed.
	 * @param string $base The URL the path gets appended to.
	 */
	private static function sanitize_path( string $path, string $base ): string {
		$path = trim( wp_strip_all_tags( $path ) );

		if ( 0 === stripos( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}

		// Anything still carrying a scheme points somewhere else.
		if ( false !== strpos( $path, '://' ) || false !== strpos( $path, '\\' ) ) {
			return '';
		}

		// I clean it as a full URL so encoded characters like %20 survive.
		$url = esc_url_raw( $base . ltrim( $path, '/' ) );

		return 0 === strpos( $url, $base ) ? substr( $url, strlen( $base ) ) : '';
	}
}
