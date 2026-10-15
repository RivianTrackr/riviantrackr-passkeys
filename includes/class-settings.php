<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin options with defaults, validation and derived values.
 */
final class Settings {

	public const OPTION = RTPK_OPTION_NAME;

	/** @var array<string,mixed>|null */
	private ?array $cache = null;

	/**
	 * @return array<string,mixed>
	 */
	public function defaults(): array {
		return [
			'rp_name'           => (string) get_bloginfo( 'name' ),
			'rp_id'             => '',
			'user_verification' => 'preferred',
			'login_button'      => true,
			'conditional_ui'    => true,
			'timeout'           => 60,
			'allowed_origins'   => '',
			'preserve_data'     => false,
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, [] );
			$this->cache = array_merge( $this->defaults(), is_array( $stored ) ? $stored : [] );
		}
		return $this->cache;
	}

	/**
	 * @return mixed
	 */
	public function get( string $key ) {
		$all = $this->all();
		return $all[ $key ] ?? null;
	}

	public function flush(): void {
		$this->cache = null;
	}

	public function rp_name(): string {
		$name = trim( (string) $this->get( 'rp_name' ) );
		return '' !== $name ? $name : (string) get_bloginfo( 'name' );
	}

	public function rp_id(): string {
		$rp_id = strtolower( trim( (string) $this->get( 'rp_id' ) ) );
		return '' !== $rp_id ? $rp_id : Helpers::default_rp_id();
	}

	public function timeout(): int {
		return (int) $this->get( 'timeout' );
	}

	/**
	 * "required" or "preferred".
	 */
	public function user_verification(): string {
		return 'required' === $this->get( 'user_verification' ) ? 'required' : 'preferred';
	}

	public function requires_user_verification(): bool {
		return 'required' === $this->user_verification();
	}

	/**
	 * Exact origins allowed in clientDataJSON.origin.
	 *
	 * @return string[]
	 */
	public function allowed_origins(): array {
		$origins = [ Helpers::site_origin() ];
		foreach ( preg_split( '/[\r\n,\s]+/', (string) $this->get( 'allowed_origins' ) ) ?: [] as $line ) {
			$normalized = Helpers::normalize_origin( $line );
			if ( $normalized ) {
				$origins[] = $normalized;
			}
		}
		/**
		 * Filter the list of origins accepted during passkey ceremonies.
		 *
		 * @param string[] $origins Normalised "scheme://host[:port]" strings.
		 */
		$origins = (array) apply_filters( 'riviantrackr_passkeys_allowed_origins', $origins );
		return array_values( array_unique( array_filter( array_map( 'strval', $origins ) ) ) );
	}

	/**
	 * Settings API sanitizer.
	 *
	 * @param mixed $input Raw submitted values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : [];
		$defaults = $this->defaults();
		$out      = [];

		$out['rp_name'] = sanitize_text_field( (string) ( $input['rp_name'] ?? '' ) );
		if ( '' === $out['rp_name'] ) {
			$out['rp_name'] = $defaults['rp_name'];
		}

		$rp_id = strtolower( sanitize_text_field( (string) ( $input['rp_id'] ?? '' ) ) );
		if ( '' !== $rp_id && ! Helpers::is_valid_rp_id( $rp_id, Helpers::default_rp_id() ) ) {
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error(
					self::OPTION,
					'rtpk_rp_id',
					__( 'The relying party ID must be this site\'s domain or one of its parent domains. It was reset to the default.', 'riviantrackr-passkeys' )
				);
			}
			$rp_id = '';
		}
		$out['rp_id'] = $rp_id;

		$uv                       = (string) ( $input['user_verification'] ?? 'preferred' );
		$out['user_verification'] = in_array( $uv, [ 'required', 'preferred' ], true ) ? $uv : 'preferred';

		$out['login_button']   = ! empty( $input['login_button'] );
		$out['conditional_ui'] = ! empty( $input['conditional_ui'] );
		$out['preserve_data']  = ! empty( $input['preserve_data'] );

		$timeout        = (int) ( $input['timeout'] ?? $defaults['timeout'] );
		$out['timeout'] = max( 30, min( 300, $timeout ) );

		$origins = [];
		foreach ( preg_split( '/[\r\n,\s]+/', (string) ( $input['allowed_origins'] ?? '' ) ) ?: [] as $line ) {
			$normalized = Helpers::normalize_origin( sanitize_text_field( $line ) );
			if ( $normalized ) {
				$origins[] = $normalized;
			}
		}
		$out['allowed_origins'] = implode( "\n", array_unique( $origins ) );

		$this->flush();
		return $out;
	}
}
