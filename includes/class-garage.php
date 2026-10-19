<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * My Garage: the Rivians a member drives, kept on their account so every
 * RivianTrackr plugin can show what applies to them.
 *
 * Each vehicle is a model, model year and generation from the shared
 * vocabulary (`Vehicles`), an optional nickname, and up to four wheel sets
 * (summer and winter, factory and aftermarket): a wheel from the Tire
 * Guide's list with one of its sizes, or any wheel with the tire size typed
 * in. One vehicle is the default. Stored in one user meta entry.
 *
 * Sibling plugins read it without depending on this class:
 *
 *   $garage = apply_filters( 'riviantrackr_garage', null, get_current_user_id() );
 *   // null when this plugin is not active, else [ 'vehicles' => [...], 'default_id' => '…', 'default' => [...]|null ]
 *
 * and in the browser from `window.riviantrackrGarage` (script handle
 * `riviantrackr-garage`, printed in the head of every front-end page).
 * `riviantrackr_garage_updated` fires after every change.
 */
final class Garage {

	public const META         = 'riviantrackr_passkeys_garage';
	public const FILTER       = 'riviantrackr_garage';
	public const UPDATED      = 'riviantrackr_garage_updated';
	public const SCRIPT       = 'riviantrackr-garage';
	public const SHORTCODE    = 'riviantrackr_garage_cta';
	public const MAX_VEHICLES = 5;
	public const MAX_WHEELS   = 4;
	public const NAME_MAX     = 40;
	public const WHEEL_MAX    = 60;

	private bool $style_registered = false;

	public function __construct( private Settings $settings ) {}

	public function register_hooks(): void {
		add_filter( self::FILTER, [ $this, 'filter_garage' ], 10, 2 );
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_bootstrap' ], 1 );
		add_shortcode( self::SHORTCODE, [ $this, 'shortcode' ] );
	}

	// ---- reading ---------------------------------------------------------

	/**
	 * The member's garage as every consumer sees it: vehicles in the order
	 * they were added (each described by `describe()`), the default's id and
	 * the default vehicle itself.
	 *
	 * @param int                                 $user_id The member.
	 * @param array<int,array<string,mixed>>|null $wheels  The Tire Guide's wheels; null reads them.
	 * @return array{vehicles:array<int,array<string,mixed>>,default_id:string,default:array<string,mixed>|null}
	 */
	public static function for_user( int $user_id, ?array $wheels = null ): array {
		$stored = self::stored( $user_id );
		$wheels = $wheels ?? ( [] !== $stored['vehicles'] ? TireGuide::wheels() : [] );
		$models = Vehicles::models();
		$out    = [];
		$found  = null;
		foreach ( $stored['vehicles'] as $vehicle ) {
			$described = self::describe( $vehicle, $models, $wheels, $stored['default'] );
			$out[]     = $described;
			if ( $described['is_default'] ) {
				$found = $described;
			}
		}
		return [
			'vehicles'   => $out,
			'default_id' => null !== $found ? $found['id'] : '',
			'default'    => $found,
		];
	}

	/**
	 * The garage as stored, tidied: unknown keys and malformed rows dropped,
	 * and a default that names an existing vehicle (the first one when the
	 * stored default is gone).
	 *
	 * @return array{vehicles:array<int,array<string,mixed>>,default:string}
	 */
	public static function stored( int $user_id ): array {
		$raw      = get_user_meta( $user_id, self::META, true );
		$raw      = is_array( $raw ) ? $raw : [];
		$vehicles = [];
		foreach ( (array) ( $raw['vehicles'] ?? [] ) as $vehicle ) {
			if ( ! is_array( $vehicle ) || empty( $vehicle['id'] ) || empty( $vehicle['model'] ) ) {
				continue;
			}
			$wheels = [];
			foreach ( (array) ( $vehicle['wheels'] ?? [] ) as $wheel ) {
				if ( is_array( $wheel ) && '' !== (string) ( $wheel['size'] ?? '' ) ) {
					$wheels[] = [
						'wheel_id' => (int) ( $wheel['wheel_id'] ?? 0 ),
						'name'     => (string) ( $wheel['name'] ?? '' ),
						'size'     => (string) $wheel['size'],
						'label'    => (string) ( $wheel['label'] ?? '' ),
					];
				}
			}
			$vehicles[] = [
				'id'         => (string) $vehicle['id'],
				'model'      => (string) $vehicle['model'],
				'year'       => (int) ( $vehicle['year'] ?? 0 ),
				'generation' => (string) ( $vehicle['generation'] ?? '' ),
				'nickname'   => (string) ( $vehicle['nickname'] ?? '' ),
				'wheels'     => $wheels,
				'added'      => (string) ( $vehicle['added'] ?? '' ),
			];
		}
		$ids     = array_column( $vehicles, 'id' );
		$default = (string) ( $raw['default'] ?? '' );
		if ( ! in_array( $default, $ids, true ) ) {
			$default = (string) ( $ids[0] ?? '' );
		}
		return [
			'vehicles' => $vehicles,
			'default'  => $default,
		];
	}

	/**
	 * A stored vehicle with everything the sibling plugins need: labels, the
	 * Software Updates platform and generation, the Tire Guide group, and the
	 * wheel sets with every tire size the vehicle wears. A wheel still in
	 * the guide takes the guide's current name (and its stock size if the
	 * saved one was dropped). Pure.
	 *
	 * @param array<string,mixed>               $vehicle    A row from stored().
	 * @param array<string,array<string,mixed>> $models  Vehicles::models().
	 * @param array<int,array<string,mixed>>    $wheels     TireGuide::wheels().
	 * @param string                            $default_id The garage's default.
	 * @return array{id:string,model:string,model_label:string,year:int,generation:string,generation_label:string,nickname:string,label:string,platform:string,group:string,wheels:array<int,array{wheel_id:int,name:string,size:string,label:string,third_party:bool}>,sizes:string[],is_default:bool}
	 */
	public static function describe( array $vehicle, array $models, array $wheels, string $default_id ): array {
		$slug  = (string) $vehicle['model'];
		$model = $models[ $slug ] ?? null;
		$label = null !== $model ? $model['label'] : strtoupper( $slug );
		$byid  = array_column( $wheels, null, 'id' );
		$sets  = [];
		$sizes = [];
		$year  = (int) $vehicle['year'];
		$gen   = (string) $vehicle['generation'];
		foreach ( (array) $vehicle['wheels'] as $set ) {
			$known = $set['wheel_id'] > 0 ? ( $byid[ $set['wheel_id'] ] ?? null ) : null;
			$size  = (string) $set['size'];
			if ( null !== $known && ! in_array( $size, $known['sizes'], true ) ) {
				$size = $known['sizes'][0];
			}
			$sets[] = [
				'wheel_id'    => null !== $known ? (int) $known['id'] : 0,
				'name'        => null !== $known ? (string) $known['name'] : (string) $set['name'],
				'size'        => $size,
				'label'       => (string) $set['label'],
				'third_party' => null !== $known ? (bool) $known['third_party'] : false,
			];
			if ( ! in_array( $size, $sizes, true ) ) {
				$sizes[] = $size;
			}
		}
		return [
			'id'               => (string) $vehicle['id'],
			'model'            => $slug,
			'model_label'      => $label,
			'year'             => $year,
			'generation'       => $gen,
			'generation_label' => Vehicles::generation_label( $gen ),
			'nickname'         => (string) $vehicle['nickname'],
			'label'            => trim( ( $year > 0 ? $year . ' ' : '' ) . $label ),
			'platform'         => null !== $model ? $model['platform'] : '',
			'group'            => null !== $model ? $model['group'] : '',
			'wheels'           => $sets,
			'sizes'            => $sizes,
			'is_default'       => (string) $vehicle['id'] === $default_id,
		];
	}

	// ---- validation ------------------------------------------------------

	/**
	 * Check a vehicle from the garage form. A missing generation is taken
	 * from the model year; a wheel picked from the guide must be listed for
	 * the model and takes its stock size unless one of its other sizes was
	 * chosen; any other wheel needs a tire size. Blank wheel rows are
	 * skipped. Pure.
	 *
	 * @param array<string,mixed>               $input  model, year, generation, nickname, wheels[] (wheel_id, size, name, label).
	 * @param array<string,array<string,mixed>> $models Vehicles::models().
	 * @param array<int,array<string,mixed>>    $wheels TireGuide::wheels().
	 * @param int|null                          $this_year Override the current year (tests).
	 * @return array{data:array<string,mixed>,errors:array<string,string>}
	 */
	public static function validate_vehicle( array $input, array $models, array $wheels, ?int $this_year = null ): array {
		$errors = [];
		$slug   = sanitize_key( (string) ( $input['model'] ?? '' ) );
		$model  = $models[ $slug ] ?? null;
		$year   = (int) ( $input['year'] ?? 0 );
		$gen    = sanitize_key( (string) ( $input['generation'] ?? '' ) );

		if ( null === $model ) {
			$errors['model'] = __( 'Choose which Rivian this is.', 'riviantrackr-passkeys' );
		} else {
			if ( ! in_array( $year, Vehicles::years( $model, $this_year ), true ) ) {
				$errors['year'] = __( 'Choose a model year.', 'riviantrackr-passkeys' );
			} elseif ( '' === $gen ) {
				$gen = Vehicles::generation_for_year( $model, $year );
			}
			if ( ! isset( $errors['year'] ) && ! isset( $model['generations'][ $gen ] ) ) {
				$errors['generation'] = __( 'Choose a generation.', 'riviantrackr-passkeys' );
			}
		}

		$nickname = sanitize_text_field( (string) ( $input['nickname'] ?? '' ) );
		if ( mb_strlen( $nickname ) > self::NAME_MAX ) {
			/* translators: %d: character limit */
			$errors['nickname'] = sprintf( __( 'Keep the nickname under %d characters.', 'riviantrackr-passkeys' ), self::NAME_MAX );
		}

		$byid = array_column( $wheels, null, 'id' );
		$sets = [];
		$rows = array_values( array_filter( (array) ( $input['wheels'] ?? [] ), 'is_array' ) );
		foreach ( $rows as $i => $row ) {
			$wheel_id = (int) ( $row['wheel_id'] ?? 0 );
			$size     = self::normalize_size( (string) ( $row['size'] ?? '' ) );
			$name     = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
			$label    = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			$key      = 'wheels.' . $i . '.';

			if ( $wheel_id <= 0 && '' === $size && '' === $name && '' === $label ) {
				continue;
			}
			if ( mb_strlen( $label ) > self::NAME_MAX ) {
				/* translators: %d: character limit */
				$errors[ $key . 'label' ] = sprintf( __( 'Keep the label under %d characters.', 'riviantrackr-passkeys' ), self::NAME_MAX );
			}

			if ( $wheel_id > 0 ) {
				$known = $byid[ $wheel_id ] ?? null;
				if ( null === $known || ( null !== $model && ! self::wheel_fits( $known, $model ) ) ) {
					$errors[ $key . 'wheel' ] = __( 'Choose a wheel from the list.', 'riviantrackr-passkeys' );
					continue;
				}
				if ( '' === $size ) {
					$size = $known['sizes'][0];
				} elseif ( ! in_array( $size, $known['sizes'], true ) ) {
					$errors[ $key . 'size' ] = __( 'Pick one of the sizes this wheel takes.', 'riviantrackr-passkeys' );
					continue;
				}
				$name = (string) $known['name'];
			} else {
				if ( ! self::is_tire_size( $size ) ) {
					$errors[ $key . 'size' ] = __( 'Enter the size printed on the tire, like 275/65R18.', 'riviantrackr-passkeys' );
					continue;
				}
				if ( mb_strlen( $name ) > self::WHEEL_MAX ) {
					/* translators: %d: character limit */
					$errors[ $key . 'name' ] = sprintf( __( 'Keep the wheel name under %d characters.', 'riviantrackr-passkeys' ), self::WHEEL_MAX );
				}
			}

			$sets[] = [
				'wheel_id' => max( 0, $wheel_id ),
				'name'     => $name,
				'size'     => $size,
				'label'    => $label,
			];
		}
		if ( count( $sets ) > self::MAX_WHEELS ) {
			/* translators: %d: number of wheel sets */
			$errors['wheels'] = sprintf( __( 'Up to %d wheel sets per vehicle.', 'riviantrackr-passkeys' ), self::MAX_WHEELS );
		}

		return [
			'data'   => [
				'model'      => $slug,
				'year'       => $year,
				'generation' => $gen,
				'nickname'   => $nickname,
				'wheels'     => $sets,
			],
			'errors' => $errors,
		];
	}

	/**
	 * Whether the guide lists a wheel for a model: by its label (`R1S`), by
	 * its group (`R1`, as older guide entries may say), or for every
	 * vehicle when it lists none. Pure.
	 *
	 * @param array<string,mixed> $wheel A TireGuide::wheels() entry.
	 * @param array<string,mixed> $model A Vehicles::models() entry.
	 */
	public static function wheel_fits( array $wheel, array $model ): bool {
		$listed = (array) $wheel['vehicles'];
		return [] === $listed
			|| in_array( strtoupper( (string) $model['label'] ), $listed, true )
			|| in_array( strtoupper( (string) $model['group'] ), $listed, true );
	}

	/**
	 * A tire size as typed, tidied: upper case without spaces, and the "x"
	 * of a flotation size in lower case (275/65R18, LT285/70R17,
	 * 35x12.50R17). Pure.
	 */
	public static function normalize_size( string $size ): string {
		$size = strtoupper( (string) preg_replace( '/\s+/', '', $size ) );
		return (string) preg_replace( '/^(\d{2}(?:\.\d{1,2})?)X/', '$1x', $size );
	}

	/**
	 * Whether a normalized size reads as a tire size: metric (P/LT prefix,
	 * Z speed rating and LT suffix allowed) or flotation. Pure.
	 */
	public static function is_tire_size( string $size ): bool {
		return 1 === preg_match( '#^(?:(?:P|LT)?\d{3}/\d{2}Z?R\d{2}(?:\.5)?|\d{2}(?:\.\d{1,2})?x\d{1,2}(?:\.\d{1,2})?R\d{2}(?:\.5)?)(?:LT)?$#', $size );
	}

	// ---- changing --------------------------------------------------------

	/**
	 * Add a validated vehicle. The first one, or one added with
	 * `$make_default`, becomes the default.
	 *
	 * @param int                 $user_id      The member.
	 * @param array<string,mixed> $data         The vehicle, as validate_vehicle() returns it.
	 * @param bool                $make_default Make it the default vehicle.
	 * @return string|\WP_Error The new vehicle's id.
	 */
	public static function add( int $user_id, array $data, bool $make_default = false ) {
		$stored = self::stored( $user_id );
		if ( count( $stored['vehicles'] ) >= self::MAX_VEHICLES ) {
			return new \WP_Error(
				'rtpk_garage_full',
				/* translators: %d: number of vehicles */
				sprintf( __( 'Your garage holds up to %d vehicles. Remove one to add another.', 'riviantrackr-passkeys' ), self::MAX_VEHICLES )
			);
		}
		$id                   = bin2hex( random_bytes( 4 ) );
		$stored['vehicles'][] = self::row( $id, $data, gmdate( 'Y-m-d H:i:s' ) );
		if ( $make_default || '' === $stored['default'] ) {
			$stored['default'] = $id;
		}
		self::save( $user_id, $stored );
		return $id;
	}

	/**
	 * Replace a vehicle's details, keeping its id and when it was added.
	 *
	 * @param int                 $user_id      The member.
	 * @param string              $id           The vehicle.
	 * @param array<string,mixed> $data         The vehicle, as validate_vehicle() returns it.
	 * @param bool                $make_default Make it the default vehicle.
	 */
	public static function update( int $user_id, string $id, array $data, bool $make_default = false ): bool {
		$stored = self::stored( $user_id );
		foreach ( $stored['vehicles'] as $i => $vehicle ) {
			if ( $vehicle['id'] === $id ) {
				$stored['vehicles'][ $i ] = self::row( $id, $data, $vehicle['added'] );
				if ( $make_default ) {
					$stored['default'] = $id;
				}
				self::save( $user_id, $stored );
				return true;
			}
		}
		return false;
	}

	/**
	 * Remove a vehicle; when it was the default, the first one left takes over.
	 */
	public static function remove( int $user_id, string $id ): bool {
		$stored = self::stored( $user_id );
		$kept   = array_values( array_filter( $stored['vehicles'], static fn( array $v ): bool => $v['id'] !== $id ) );
		if ( count( $kept ) === count( $stored['vehicles'] ) ) {
			return false;
		}
		self::save(
			$user_id,
			[
				'vehicles' => $kept,
				'default'  => $stored['default'] === $id ? (string) ( $kept[0]['id'] ?? '' ) : $stored['default'],
			]
		);
		return true;
	}

	public static function set_default( int $user_id, string $id ): bool {
		$stored = self::stored( $user_id );
		if ( ! in_array( $id, array_column( $stored['vehicles'], 'id' ), true ) ) {
			return false;
		}
		$stored['default'] = $id;
		self::save( $user_id, $stored );
		return true;
	}

	/**
	 * @param string              $id    The vehicle.
	 * @param array<string,mixed> $data  The vehicle, as validate_vehicle() returns it.
	 * @param string              $added When it was first added (UTC).
	 * @return array<string,mixed>
	 */
	private static function row( string $id, array $data, string $added ): array {
		return [
			'id'         => $id,
			'model'      => (string) $data['model'],
			'year'       => (int) $data['year'],
			'generation' => (string) $data['generation'],
			'nickname'   => (string) $data['nickname'],
			'wheels'     => array_values( (array) $data['wheels'] ),
			'added'      => $added,
		];
	}

	/**
	 * @param int                                                           $user_id The member.
	 * @param array{vehicles:array<int,array<string,mixed>>,default:string} $stored  The whole garage.
	 */
	private static function save( int $user_id, array $stored ): void {
		if ( [] === $stored['vehicles'] ) {
			delete_user_meta( $user_id, self::META );
		} else {
			update_user_meta(
				$user_id,
				self::META,
				[
					'vehicles' => array_values( $stored['vehicles'] ),
					'default'  => $stored['default'],
				]
			);
		}

		/**
		 * Fires after a member's garage changed (vehicle added, edited,
		 * removed or a new default picked).
		 *
		 * @param int                 $user_id The member.
		 * @param array<string,mixed> $garage  The garage as Garage::for_user() returns it.
		 */
		do_action( 'riviantrackr_garage_updated', $user_id, self::for_user( $user_id ) );
	}

	// ---- hooks -----------------------------------------------------------

	/**
	 * `riviantrackr_garage` filter: the garage of the given member (the
	 * signed-in one when 0 or omitted). Signed-out visitors get an empty
	 * garage.
	 *
	 * @param mixed $value   Ignored; null when no plugin answers.
	 * @param mixed $user_id Member ID, 0 for the current one.
	 * @return array{vehicles:array<int,array<string,mixed>>,default_id:string,default:array<string,mixed>|null}
	 */
	public function filter_garage( $value = null, $user_id = 0 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Filter signature.
		$user_id = (int) $user_id > 0 ? (int) $user_id : get_current_user_id();
		if ( $user_id <= 0 ) {
			return [
				'vehicles'   => [],
				'default_id' => '',
				'default'    => null,
			];
		}
		return self::for_user( $user_id );
	}

	/**
	 * What `window.riviantrackrGarage` holds. Signed-out visitors get the
	 * links that bring them in (join, sign in) and no vehicles. Pure.
	 *
	 * @param array<string,mixed>|null $garage for_user(), or null when signed out.
	 * @param array<string,string>     $urls   account, join, signIn.
	 * @return array<string,mixed>
	 */
	public static function bootstrap_data( ?array $garage, array $urls ): array {
		$account = (string) ( $urls['account'] ?? '' );
		return [
			'signedIn'    => null !== $garage,
			'vehicles'    => null !== $garage ? $garage['vehicles'] : [],
			'defaultId'   => null !== $garage ? $garage['default_id'] : '',
			'garageUrl'   => '' !== $account ? $account . '#garage' : '',
			'joinUrl'     => (string) ( $urls['join'] ?? '' ),
			'signInUrl'   => (string) ( $urls['signIn'] ?? '' ),
			'maxVehicles' => self::MAX_VEHICLES,
		];
	}

	/**
	 * Print `window.riviantrackrGarage` in the head of every front-end page,
	 * on a script handle sibling plugins can list as a dependency. Members
	 * are not served from page caches, and every visitor who is signed out
	 * gets the same data, so cached pages stay correct.
	 */
	public function enqueue_bootstrap(): void {
		/**
		 * Whether to print the garage for front-end scripts.
		 *
		 * @param bool $print Default true.
		 */
		if ( ! apply_filters( 'riviantrackr_passkeys_garage_script', true ) ) {
			return;
		}
		$user_id = get_current_user_id();
		$data    = self::bootstrap_data( $user_id > 0 ? self::for_user( $user_id ) : null, $this->urls() );

		wp_register_script( self::SCRIPT, false, [], RTPK_VERSION, false );
		wp_add_inline_script( self::SCRIPT, 'window.riviantrackrGarage = ' . wp_json_encode( $data ) . ';', 'before' );
		wp_enqueue_script( self::SCRIPT );
	}

	/**
	 * The garage, join and sign-in links.
	 *
	 * @return array{account:string,join:string,signIn:string}
	 */
	public function urls(): array {
		$join = $this->settings->register_page_url();
		if ( '' === $join && get_option( 'users_can_register' ) ) {
			$join = wp_registration_url();
		}
		return [
			'account' => $this->settings->account_page_url(),
			'join'    => $join,
			'signIn'  => wp_login_url(),
		];
	}

	// ---- call to action --------------------------------------------------

	/**
	 * [riviantrackr_garage_cta theme="dark|light"]: for visitors who are
	 * signed out, a card that asks them to join and save their Rivian; for
	 * members with an empty garage, one that sends them to add it. Members
	 * whose garage has a vehicle see nothing.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public function shortcode( $atts = [] ): string {
		$atts    = shortcode_atts( [ 'theme' => 'dark' ], is_array( $atts ) ? $atts : [], self::SHORTCODE );
		$urls    = $this->urls();
		$user_id = get_current_user_id();
		$here    = (string) ( get_permalink() ?: home_url( '/' ) );
		$card    = self::cta( $user_id > 0, $user_id > 0 ? count( self::stored( $user_id )['vehicles'] ) : 0, $urls['account'], $urls['join'], wp_login_url( $here ), 'light' === $atts['theme'] );
		if ( '' !== $card ) {
			$this->enqueue_style();
		}
		return $card;
	}

	/**
	 * The call-to-action card's markup, or '' when there is nothing to ask. Pure.
	 */
	public static function cta( bool $signed_in, int $vehicles, string $account_url, string $join_url, string $sign_in_url, bool $light ): string {
		if ( $signed_in && ( $vehicles > 0 || '' === $account_url ) ) {
			return '';
		}
		$buttons = '';
		if ( $signed_in ) {
			$title    = __( 'Add your Rivian', 'riviantrackr-passkeys' );
			$text     = __( 'Tell us what you drive and which wheels it wears, and we will show the tires, software updates and news that apply to it.', 'riviantrackr-passkeys' );
			$buttons .= '<a class="rtpk-garage-cta__btn is-primary" href="' . esc_url( $account_url . '#garage' ) . '">' . esc_html__( 'Add a vehicle', 'riviantrackr-passkeys' ) . '</a>';
		} else {
			$title = __( 'Save your Rivian', 'riviantrackr-passkeys' );
			$text  = '' !== $join_url
				? __( 'Create a free account, add the Rivians you drive and the wheels they wear, and we will show the tires, software updates and news that apply to you.', 'riviantrackr-passkeys' )
				: __( 'Sign in, add the Rivians you drive and the wheels they wear, and we will show the tires, software updates and news that apply to you.', 'riviantrackr-passkeys' );
			if ( '' !== $join_url ) {
				$buttons .= '<a class="rtpk-garage-cta__btn is-primary" href="' . esc_url( $join_url ) . '">' . esc_html__( 'Create a free account', 'riviantrackr-passkeys' ) . '</a>';
			}
			$buttons .= '<a class="rtpk-garage-cta__btn' . ( '' === $join_url ? ' is-primary' : '' ) . '" href="' . esc_url( $sign_in_url ) . '">' . esc_html__( 'Sign in', 'riviantrackr-passkeys' ) . '</a>';
		}
		return '<aside class="rtpk-garage-cta' . ( $light ? ' is-light' : '' ) . '">'
			. '<span class="rtpk-garage-cta__icon" aria-hidden="true">' . AccountPage::icon( 'car' ) . '</span>'
			. '<div class="rtpk-garage-cta__text"><p class="rtpk-garage-cta__title">' . esc_html( $title ) . '</p><p class="rtpk-garage-cta__desc">' . esc_html( $text ) . '</p></div>'
			. '<div class="rtpk-garage-cta__actions">' . $buttons . '</div>'
			. '</aside>';
	}

	private function enqueue_style(): void {
		if ( ! $this->style_registered ) {
			$this->style_registered = true;
			$file                   = 'assets/passkeys-garage' . RTPK_ASSET_SUFFIX . '.css';
			wp_register_style( 'rtpk-garage', RTPK_URL . $file, [], Helpers::asset_version( $file ) );
		}
		wp_enqueue_style( 'rtpk-garage' );
	}

	// ---- privacy ---------------------------------------------------------

	/**
	 * @param array<string,array<string,mixed>> $exporters Registered exporters.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_exporter( $exporters ): array {
		$exporters                        = (array) $exporters;
		$exporters['riviantrackr-garage'] = [
			'exporter_friendly_name' => __( 'RivianTrackr garage', 'riviantrackr-passkeys' ),
			'callback'               => [ self::class, 'export' ],
		];
		return $exporters;
	}

	/**
	 * @param array<string,array<string,mixed>> $erasers Registered erasers.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_eraser( $erasers ): array {
		$erasers                        = (array) $erasers;
		$erasers['riviantrackr-garage'] = [
			'eraser_friendly_name' => __( 'RivianTrackr garage', 'riviantrackr-passkeys' ),
			'callback'             => [ self::class, 'erase' ],
		];
		return $erasers;
	}

	/**
	 * Personal data exporter: one item per vehicle.
	 *
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public static function export( string $email, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Exporter signature.
		$user = get_user_by( 'email', $email );
		$data = [];
		if ( $user instanceof \WP_User ) {
			foreach ( self::for_user( $user->ID, [] )['vehicles'] as $vehicle ) {
				$wheels = array_map(
					static fn( array $w ): string => trim( implode( ' · ', array_filter( [ $w['label'], $w['name'], $w['size'] ] ) ) ),
					$vehicle['wheels']
				);
				$data[] = [
					'group_id'    => 'riviantrackr-garage',
					'group_label' => __( 'Garage', 'riviantrackr-passkeys' ),
					'item_id'     => 'garage-' . $vehicle['id'],
					'data'        => [
						[
							'name'  => __( 'Vehicle', 'riviantrackr-passkeys' ),
							'value' => $vehicle['label'] . ' ' . $vehicle['generation_label'],
						],
						[
							'name'  => __( 'Nickname', 'riviantrackr-passkeys' ),
							'value' => $vehicle['nickname'],
						],
						[
							'name'  => __( 'Wheels', 'riviantrackr-passkeys' ),
							'value' => implode( '; ', $wheels ),
						],
						[
							'name'  => __( 'Default vehicle', 'riviantrackr-passkeys' ),
							'value' => $vehicle['is_default'] ? __( 'Yes', 'riviantrackr-passkeys' ) : __( 'No', 'riviantrackr-passkeys' ),
						],
					],
				];
			}
		}
		return [
			'data' => $data,
			'done' => true,
		];
	}

	/**
	 * Personal data eraser: drops the whole garage.
	 *
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public static function erase( string $email, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Eraser signature.
		$user    = get_user_by( 'email', $email );
		$removed = false;
		if ( $user instanceof \WP_User && [] !== self::stored( $user->ID )['vehicles'] ) {
			delete_user_meta( $user->ID, self::META );
			$removed = true;
		}
		return [
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}
}
