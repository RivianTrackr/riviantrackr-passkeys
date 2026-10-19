<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composition root: wires the components together.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private Settings $settings;
	private CredentialStore $store;
	private ChallengeStore $challenges;
	private RateLimiter $limiter;
	private WebAuthnService $service;
	private RestController $rest;
	private LoginForm $login_form;
	private ProfileManager $profile;
	private Admin $admin;
	private InviteService $invites;
	private InviteAdmin $invite_admin;
	private SetupPage $setup_page;
	private PendingSignupStore $pending;
	private SignupService $signup;
	private SignupPage $signup_page;
	private LoginPage $login_page;
	private LoginRouter $router;
	private PasswordResetPage $reset_page;
	private LoginBranding $branding;
	private AccountPage $account;
	private AccountRest $account_rest;
	private AccountRedirect $account_redirect;
	private BrandedEmails $emails;
	private Avatars $avatars;
	private Garage $garage;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings         = new Settings();
		$this->store            = new CredentialStore();
		$this->challenges       = new ChallengeStore();
		$this->limiter          = new RateLimiter();
		$this->service          = new WebAuthnService( $this->settings, $this->store );
		$this->rest             = new RestController( $this->settings, $this->store, $this->challenges, $this->limiter, $this->service );
		$this->login_form       = new LoginForm( $this->settings );
		$this->profile          = new ProfileManager( $this->settings, $this->store, $this->rest );
		$this->invites          = new InviteService( $this->settings, $this->store );
		$this->invite_admin     = new InviteAdmin( $this->invites, $this->store );
		$this->setup_page       = new SetupPage( $this->invites, $this->profile, $this->store, $this->settings );
		$this->pending          = new PendingSignupStore();
		$this->signup           = new SignupService( $this->settings, $this->invites, $this->pending, $this->limiter );
		$this->signup_page      = new SignupPage( $this->signup, $this->invites, $this->settings );
		$this->login_page       = new LoginPage( $this->settings, $this->signup );
		$this->router           = new LoginRouter( $this->settings );
		$this->reset_page       = new PasswordResetPage( $this->settings, $this->limiter );
		$this->branding         = new LoginBranding( $this->settings, $this->invites );
		$this->account          = new AccountPage( $this->settings, $this->store, $this->profile );
		$this->account_rest     = new AccountRest( $this->limiter, $this->settings );
		$this->account_redirect = new AccountRedirect( $this->settings );
		$this->emails           = new BrandedEmails( $this->invites );
		$this->avatars          = new Avatars();
		$this->garage           = new Garage( $this->settings );
		$this->admin            = new Admin( $this->settings, $this->store, $this->signup );
	}

	public function boot(): void {
		load_plugin_textdomain( 'riviantrackr-passkeys', false, dirname( plugin_basename( RTPK_FILE ) ) . '/languages' );

		add_action( 'admin_init', [ Installer::class, 'maybe_upgrade' ] );
		add_action( Installer::PURGE_EVENT, [ $this->pending, 'purge_expired' ] );
		add_action( 'rest_api_init', [ $this->rest, 'register_routes' ] );
		add_action( 'rest_api_init', [ $this->signup_page, 'register_routes' ] );
		add_action( 'rest_api_init', [ $this->account_rest, 'register_routes' ] );
		add_action( 'deleted_user', [ $this->store, 'delete_for_user' ] );
		add_action( 'wp_set_password', [ AccountService::class, 'on_password_set' ], 10, 2 );
		add_action( 'update_option_' . Settings::OPTION, [ $this->settings, 'flush' ] );
		add_action( 'update_option_' . SignupService::OPTION, [ $this->signup, 'flush' ] );

		$this->login_form->register_hooks();
		$this->profile->register_hooks();
		$this->setup_page->register_hooks();
		$this->signup_page->register_hooks();
		$this->login_page->register_hooks();
		$this->router->register_hooks();
		$this->reset_page->register_hooks();
		$this->branding->register_hooks();
		$this->account->register_hooks();
		$this->account_redirect->register_hooks();
		$this->emails->register_hooks();
		$this->avatars->register_hooks();
		$this->garage->register_hooks();

		if ( is_admin() ) {
			$this->admin->register_hooks();
			$this->invite_admin->register_hooks();
		}
	}

	public function settings(): Settings {
		return $this->settings;
	}

	public function credentials(): CredentialStore {
		return $this->store;
	}

	public function invites(): InviteService {
		return $this->invites;
	}

	public function signup(): SignupService {
		return $this->signup;
	}
}
