<?php
/**
 * @package WPENC
 * @version 0.5.0
 * @author Felix Arntz <felix-arntz@leaves-and-love.net>
 */

namespace WPENC\Core;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

if ( ! class_exists( 'WPENC\Core\Challenge' ) ) {
	/**
	 * This class validates challenges for a domain.
	 *
	 * @internal
	 * @since 0.5.0
	 */
	final class Challenge {
		public static function validate( $domain, $account_key_details ) {
			$client = Client::get();

			$response = $client->auth( $domain );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$challenge = array_reduce( $response['challenges'], function( $v, $w ) {
				if ( $v ) {
					return $v;
				}
				if ( 'http-01' === $w['type'] ) {
					return $w;
				}
				return false;
			});

			if ( ! $challenge ) {
				return new WP_Error( 'no_challenge_available', sprintf( __( 'No HTTP challenge available. Original response: %s', 'wp-encrypt' ), json_encode( $response ) ) );
			}

			$location = $client->get_last_location();

			$directory = Util::get_letsencrypt_challenges_dir_path();
			$token_path = $directory . '/' . $challenge['token'];

			if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0755, true ) ) {
				return new WP_Error( 'challenge_cannot_create_dir', sprintf( __( 'Could not create challenge directory <code>%s</code>. Please check your filesystem permissions.', 'wp-encrypt' ), $directory ) );
			}

			$header = array(
				'e'		=> Util::base64_url_encode( $account_key_details['rsa']['e'] ),
				'kty'	=> 'RSA',
				'n'		=> Util::base64_url_encode( $account_key_details['rsa']['n'] ),
			);
			$data = $challenge['token'] . '.' . Util::base64_url_encode( hash( 'sha256', json_encode( $header ), true ) );

			if ( false === file_put_contents( $token_path, $data ) ) {
				return new WP_Error( 'challenge_cannot_write_file', sprintf( __( 'Could not write challenge to file <code>%s</code>. Please check your filesystem permissions.', 'wp-encrypt' ), $token_path ) );
			}
			chmod( $token_path, 0644 );

			$token_uri = Util::get_letsencrypt_challenges_dir_url() . '/' . $challenge['token'];

			if ( $data !== trim( @file_get_contents( $token_uri ) ) ) {
				return new WP_Error( 'challenge_self_failed', __( 'Challenge self check failed.', 'wp-encrypt' ) );
			}

			$result = $client->challenge( $token_uri, $challenge['token'], $data );

			$done = false;

			do {
				if ( empty( $result['status'] ) || 'invalid' === $result['status'] ) {
					return new WP_Error( 'challenge_remote_failed', __( 'Challenge remote check failed.', 'wp-encrypt' ) );
				}

				$done = 'pending' !== $result['status'];
				if ( ! $done ) {
					sleep( 1 );
				}

				$result = $client->request( $location, 'GET' );
			} while ( ! $done );

			@unlink( $token_path );

			return true;
		}
	}
}