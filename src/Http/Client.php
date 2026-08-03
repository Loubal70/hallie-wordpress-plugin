<?php
/**
 * HTTP client for JSON providers.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Http;

use Hallie\Reviews\Exception\ProviderException;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal JSON client built on the WordPress HTTP API.
 *
 * Conditional requests are delegated to EtagStore; this class only speaks HTTP and
 * turns what comes back into either a Response or a typed failure.
 */
final class Client {

	private const int TIMEOUT = 15;

	private const int DEFAULT_RETRY_DELAY = MINUTE_IN_SECONDS;

	public function __construct(
		private readonly string $base_url,
		private readonly string $token = '',
		private readonly EtagStore $validators = new EtagStore(),
	) {}

	/**
	 * Perform a conditional GET and decode the JSON body.
	 *
	 * A 304 comes back as a Response flagged `not_modified` rather than as an error:
	 * callers read it as "nothing changed upstream".
	 *
	 * @param string               $path  Path appended to the base URL.
	 * @param array<string, mixed> $query Query arguments.
	 *
	 * @throws ProviderException On transport failure, rate limiting, or an unusable body.
	 */
	public function get( string $path, array $query = array() ): Response {
		$url       = $this->url_for( $path, $query );
		$validator = $this->validators->find_for( $url );

		$raw = wp_remote_get( $url, $this->request_args( $validator ) );

		$this->guard_against_failure( $raw );

		if ( $this->is_unchanged( $raw ) ) {
			return Response::unchanged();
		}

		return $this->to_response( $url, $raw );
	}

	/**
	 * Reject everything that is not a usable answer.
	 *
	 * @param array<string, mixed>|WP_Error $raw Result of the HTTP call.
	 *
	 * @throws ProviderException When the call failed, was throttled, or errored.
	 */
	private function guard_against_failure( array|WP_Error $raw ): void {
		if ( $raw instanceof WP_Error ) {
			throw new ProviderException(
				sprintf(
					/* translators: %s: transport error message. */
					esc_html__( 'Could not reach the review provider: %s', 'hallie' ),
					esc_html( $raw->get_error_message() )
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $raw );

		if ( $this->is_throttled( $status ) ) {
			$delay = $this->retry_delay_of( $raw );

			$message = sprintf(
				/* translators: %d: number of seconds to wait. */
				esc_html__( 'Provider rate limit reached, retrying in %d seconds.', 'hallie' ),
				absint( $delay )
			);

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $delay is an int, the message is escaped.
			throw new ProviderException( esc_html( $message ), $delay );
		}

		if ( $this->is_unchanged( $raw ) || $this->is_successful( $status ) ) {
			return;
		}

		$message = sprintf(
			/* translators: %d: HTTP status code. */
			esc_html__( 'The review provider answered with an unexpected status (%d).', 'hallie' ),
			absint( $status )
		);

		throw new ProviderException( esc_html( $message ) );
	}

	/**
	 * @param string               $url URL the response came from.
	 * @param array<string, mixed> $raw Successful HTTP result.
	 *
	 * @throws ProviderException When the body is not decodable JSON.
	 */
	private function to_response( string $url, array $raw ): Response {
		$payload = json_decode( wp_remote_retrieve_body( $raw ), true );

		if ( ! is_array( $payload ) ) {
			throw new ProviderException(
				esc_html__( 'The review provider returned a response that could not be read.', 'hallie' )
			);
		}

		$this->validators->remember_for( $url, $this->validator_of( $raw ) );

		return new Response( $payload );
	}

	/**
	 * @param array<string, mixed>|WP_Error $raw Result of the HTTP call.
	 */
	private function is_unchanged( array|WP_Error $raw ): bool {
		if ( $raw instanceof WP_Error ) {
			return false;
		}

		return 304 === (int) wp_remote_retrieve_response_code( $raw );
	}

	private function is_successful( int $status ): bool {
		return $status >= 200 && $status < 300;
	}

	private function is_throttled( int $status ): bool {
		return 429 === $status;
	}

	/**
	 * @param array<string, mixed> $raw Throttled HTTP result.
	 */
	private function retry_delay_of( array $raw ): int {
		$requested = (int) wp_remote_retrieve_header( $raw, 'retry-after' );

		return $requested > 0 ? $requested : self::DEFAULT_RETRY_DELAY;
	}

	/**
	 * @param array<string, mixed> $raw Successful HTTP result.
	 */
	private function validator_of( array $raw ): ?string {
		$validator = wp_remote_retrieve_header( $raw, 'etag' );

		if ( ! is_string( $validator ) || '' === $validator ) {
			return null;
		}

		return $validator;
	}

	private function url_for( string $path, array $query ): string {
		$url = rtrim( $this->base_url, '/' ) . '/' . ltrim( $path, '/' );

		if ( array() === $query ) {
			return $url;
		}

		return add_query_arg( $query, $url );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function request_args( ?string $validator ): array {
		return array(
			'timeout' => self::TIMEOUT,
			'headers' => $this->headers_for( $validator ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function headers_for( ?string $validator ): array {
		$headers = array(
			'Accept'     => 'application/json',
			'User-Agent' => 'Hallie/' . HALLIE_VERSION . '; ' . home_url( '/' ),
		);

		if ( '' !== $this->token ) {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}

		if ( null !== $validator ) {
			$headers['If-None-Match'] = $validator;
		}

		return $headers;
	}
}
