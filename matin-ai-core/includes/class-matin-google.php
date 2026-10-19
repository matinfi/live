<?php
defined( 'ABSPATH' ) || exit;

/**
 * Google sign-in (OpenID Connect authorization-code flow).
 * Enabled only when the toggle, client id and client secret are all set.
 * Redirect URI to register in Google Cloud Console: {site}/my-account/?matin_oauth=google_callback
 */
final class Matin_AI_Google {
	private const STATE_PREFIX = 'matin_oauth_state_';

	public static function init() : void {
		add_action( 'template_redirect', [ __CLASS__, 'handle' ] );
	}

	public static function enabled() : bool {
		$o = Matin_AI_Admin::get_options();
		return ! empty( $o['google_login_enabled'] ) && '' !== (string) $o['google_client_id'] && '' !== self::client_secret();
	}

	public static function start_url() : string {
		return add_query_arg( 'matin_oauth', 'google_start', home_url( '/my-account/' ) );
	}

	public static function handle() : void {
		if ( empty( $_GET['matin_oauth'] ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_GET['matin_oauth'] ) );
		if ( 'google_start' === $action ) {
			self::start();
		} elseif ( 'google_callback' === $action ) {
			self::callback();
		}
	}

	private static function start() : void {
		if ( ! self::enabled() ) {
			wp_safe_redirect( add_query_arg( 'matin_notice', 'google_off', home_url( '/my-account/' ) ) );
			exit;
		}
		$state = wp_generate_password( 40, false, false );
		set_transient( self::STATE_PREFIX . $state, 1, 10 * MINUTE_IN_SECONDS );
		$url = add_query_arg( [
			'client_id'     => Matin_AI_Admin::get_options()['google_client_id'],
			'redirect_uri'  => self::redirect_uri(),
			'response_type' => 'code',
			'scope'         => 'openid email profile',
			'state'         => $state,
			'prompt'        => 'select_account',
		], 'https://accounts.google.com/o/oauth2/v2/auth' );
		wp_redirect( $url );
		exit;
	}

	private static function callback() : void {
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$valid = $state && get_transient( self::STATE_PREFIX . $state );
		delete_transient( self::STATE_PREFIX . $state );
		if ( ! $valid || ! $code || ! self::enabled() ) {
			self::fail( 'google_failed' );
		}
		$claims = self::exchange_code( $code );
		if ( ! $claims || empty( $claims['email'] ) || empty( $claims['sub'] ) ) {
			self::fail( 'google_failed' );
		}
		$user = get_user_by( 'email', sanitize_email( $claims['email'] ) );
		if ( ! $user ) {
			$name     = sanitize_text_field( (string) ( $claims['name'] ?? '' ) );
			$username = sanitize_user( current( explode( '@', $claims['email'] ) ), true ) ?: 'user';
			if ( username_exists( $username ) ) {
				$username .= wp_generate_password( 4, false, false );
			}
			$user_id = wp_create_user( $username, wp_generate_password( 32 ), $claims['email'] );
			if ( is_wp_error( $user_id ) ) {
				self::fail( 'google_failed' );
			}
			wp_update_user( [ 'ID' => $user_id, 'display_name' => $name ?: $username, 'first_name' => $name ] );
			$user = get_user_by( 'id', $user_id );
		}
		update_user_meta( $user->ID, 'matin_google_sub', sanitize_text_field( $claims['sub'] ) );
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		wp_safe_redirect( home_url( '/my-account/' ) );
		exit;
	}

	/** @return array<string, mixed>|null Verified id_token claims. */
	private static function exchange_code( string $code ) : ?array {
		$response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
			'timeout' => 15,
			'body'    => [
				'code'          => $code,
				'client_id'     => Matin_AI_Admin::get_options()['google_client_id'],
				'client_secret' => self::client_secret(),
				'redirect_uri'  => self::redirect_uri(),
				'grant_type'    => 'authorization_code',
			],
		] );
		if ( is_wp_error( $response ) ) {
			Matin_AI_Logger::log( 'error', 'google', 'درخواست توکن Google ناموفق بود.' );
			return null;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['id_token'] ) ) {
			Matin_AI_Logger::log( 'error', 'google', 'Google توکن معتبر برنگرداند.', [ 'status' => wp_remote_retrieve_response_code( $response ) ] );
			return null;
		}
		// tokeninfo validates signature, expiry and issuer on Google's side.
		$info = wp_remote_get( add_query_arg( 'id_token', $body['id_token'], 'https://oauth2.googleapis.com/tokeninfo' ), [ 'timeout' => 15 ] );
		if ( is_wp_error( $info ) ) {
			return null;
		}
		$claims = json_decode( (string) wp_remote_retrieve_body( $info ), true );
		$ok = is_array( $claims )
			&& ( $claims['aud'] ?? '' ) === Matin_AI_Admin::get_options()['google_client_id']
			&& in_array( $claims['email_verified'] ?? false, [ true, 'true' ], true )
			&& (int) ( $claims['exp'] ?? 0 ) > time()
			&& in_array( $claims['iss'] ?? '', [ 'accounts.google.com', 'https://accounts.google.com' ], true );
		return $ok ? $claims : null;
	}

	private static function client_secret() : string {
		if ( defined( 'MATIN_GOOGLE_CLIENT_SECRET' ) ) {
			return (string) MATIN_GOOGLE_CLIENT_SECRET;
		}
		return Matin_AI_Secrets::decrypt( (string) ( Matin_AI_Admin::get_options()['google_client_secret'] ?? '' ), Matin_AI_Messaging::secret_material() );
	}

	private static function redirect_uri() : string {
		return add_query_arg( 'matin_oauth', 'google_callback', home_url( '/my-account/' ) );
	}

	private static function fail( string $notice ) : void {
		wp_safe_redirect( add_query_arg( 'matin_notice', $notice, home_url( '/my-account/' ) ) );
		exit;
	}
}
