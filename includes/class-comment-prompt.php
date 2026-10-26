<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asks signed-out visitors to sign in or create an account from the
 * WordPress comment form. Hooks core's comment_form() only, so it works with
 * any theme that uses it: a card at the top of the form when anyone may
 * comment, and the same card in place of "You must be logged in to post a
 * comment" when the site requires an account. Both buttons bring the visitor
 * back to the post's comment form afterwards.
 */
final class CommentPrompt {

	public const STYLE = 'rtpk-comments';

	public function __construct(
		private Settings $settings,
		private SignupService $signup
	) {}

	public function register_hooks(): void {
		if ( ! $this->settings->prompts_commenters() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue' ] );
		add_action( 'comment_form_top', [ $this, 'render_top' ] );
		add_filter( 'comment_form_defaults', [ $this, 'filter_defaults' ] );
	}

	// ---- decision --------------------------------------------------------

	/**
	 * Where "Create a free account" leads, or '' when nobody can join. Pure.
	 *
	 * The plugin's join page carries the return address so the new member
	 * comes back after passkey setup. Core registration, when it is open
	 * instead, has no way to carry it.
	 *
	 * @param string $join_page        Join page URL, or ''.
	 * @param bool   $signups          Whether open signup is on.
	 * @param string $registration_url Core registration URL when "Anyone can register" is on, else ''.
	 * @param string $return_to        Where to come back to, or ''.
	 */
	public static function join_link( string $join_page, bool $signups, string $registration_url, string $return_to ): string {
		if ( $signups && '' !== $join_page ) {
			return '' !== $return_to ? add_query_arg( 'redirect_to', rawurlencode( $return_to ), $join_page ) : $join_page;
		}
		return $registration_url;
	}

	/**
	 * The card's markup. Pure.
	 *
	 * @param string $sign_in_url Sign-in URL, already carrying the return address.
	 * @param string $join_url    "Create a free account" URL, or '' to leave it out.
	 * @param bool   $required    Whether the site only lets members comment.
	 * @param bool   $light       Light theme.
	 */
	public static function card( string $sign_in_url, string $join_url, bool $required, bool $light ): string {
		$join = '' !== $join_url;
		if ( $required ) {
			$title = __( 'Sign in to comment', 'riviantrackr-passkeys' );
			$text  = $join
				? __( 'Comments are open to members. Create a free account and sign in with Face ID, Touch ID or Windows Hello, no password to remember.', 'riviantrackr-passkeys' )
				: __( 'Comments are open to members. Sign in to join the conversation.', 'riviantrackr-passkeys' );
		} else {
			$title = __( 'Join the conversation', 'riviantrackr-passkeys' );
			$text  = $join
				? __( 'Comment under your own name and photo, skip the fields below, and sign in with Face ID or Touch ID instead of a password.', 'riviantrackr-passkeys' )
				: __( 'Sign in to comment under your own name and photo and skip the name and email fields.', 'riviantrackr-passkeys' );
		}

		$buttons = '';
		if ( $join ) {
			$buttons .= '<a class="rtpk-comment-cta__btn is-primary" href="' . esc_url( $join_url ) . '">' . esc_html__( 'Create a free account', 'riviantrackr-passkeys' ) . '</a>';
		}
		$buttons .= '<a class="rtpk-comment-cta__btn' . ( $join ? '' : ' is-primary' ) . '" href="' . esc_url( $sign_in_url ) . '">' . esc_html__( 'Sign in', 'riviantrackr-passkeys' ) . '</a>';

		return '<aside class="rtpk-comment-cta' . ( $light ? ' is-light' : '' ) . '">'
			. '<span class="rtpk-comment-cta__icon" aria-hidden="true">' . AccountPage::icon( 'comment' ) . '</span>'
			. '<div class="rtpk-comment-cta__text"><p class="rtpk-comment-cta__title">' . esc_html( $title ) . '</p><p class="rtpk-comment-cta__desc">' . esc_html( $text ) . '</p></div>'
			. '<div class="rtpk-comment-cta__actions">' . $buttons . '</div>'
			. '</aside>';
	}

	// ---- hooks -----------------------------------------------------------

	/**
	 * Load the card's stylesheet in the head of posts whose comment form
	 * will show it, so it does not paint unstyled first.
	 */
	public function maybe_enqueue(): void {
		if ( ! is_user_logged_in() && is_singular() && comments_open() ) {
			$this->enqueue_style();
		}
	}

	/**
	 * The card above the fields for signed-out visitors (comment_form_top).
	 */
	public function render_top(): void {
		if ( is_user_logged_in() ) {
			return;
		}
		echo $this->markup( false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in card().
	}

	/**
	 * The card instead of "You must be logged in to post a comment" when
	 * comments need an account (comment_form_defaults).
	 *
	 * @param array<string,mixed> $defaults comment_form() defaults.
	 * @return array<string,mixed>
	 */
	public function filter_defaults( $defaults ): array {
		$defaults = is_array( $defaults ) ? $defaults : [];
		if ( ! is_user_logged_in() && get_option( 'comment_registration' ) ) {
			$card = $this->markup( true );
			if ( '' !== $card ) {
				$defaults['must_log_in'] = $card;
			}
		}
		return $defaults;
	}

	/**
	 * The card for the current post, after the owner's filter.
	 */
	private function markup( bool $required ): string {
		$post_id = (int) get_the_ID();
		if ( $post_id <= 0 && is_singular() ) {
			// comment_form() called outside the loop.
			$post_id = (int) get_queried_object_id();
		}
		$permalink = $post_id > 0 ? (string) get_permalink( $post_id ) : '';
		$return_to = '' !== $permalink ? $permalink . '#respond' : '';
		$join      = self::join_link(
			$this->settings->register_page_url(),
			$this->signup->enabled(),
			get_option( 'users_can_register' ) ? wp_registration_url() : '',
			$return_to
		);

		/**
		 * Colour scheme of the comment form card: "dark" (default) or "light".
		 *
		 * @param string $theme   "dark" or "light".
		 * @param int    $post_id The post whose comment form is showing.
		 */
		$light = 'light' === apply_filters( 'riviantrackr_passkeys_comment_prompt_theme', 'dark', $post_id );
		$html  = self::card( wp_login_url( $return_to ), $join, $required, $light );

		/**
		 * Filter the comment form card. Return '' to hide it on a post.
		 *
		 * @param string $html     Card markup.
		 * @param int    $post_id  The post whose comment form is showing.
		 * @param bool   $required Whether the site only lets members comment.
		 */
		$html = (string) apply_filters( 'riviantrackr_passkeys_comment_prompt', $html, $post_id, $required );
		if ( '' !== $html ) {
			$this->enqueue_style();
		}
		return $html;
	}

	private function enqueue_style(): void {
		if ( ! wp_style_is( self::STYLE, 'registered' ) ) {
			$file = 'assets/passkeys-comments' . RTPK_ASSET_SUFFIX . '.css';
			wp_register_style( self::STYLE, RTPK_URL . $file, [], Helpers::asset_version( $file ) );
		}
		wp_enqueue_style( self::STYLE );
	}
}
