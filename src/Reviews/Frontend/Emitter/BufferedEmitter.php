<?php
/**
 * Shared emitter plumbing.
 *
 * @package Hallie
 */

declare(strict_types=1);

namespace Hallie\Reviews\Frontend\Emitter;

use Hallie\Reviews\Frontend\ReviewSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the payload until the page is finished, then prints it.
 *
 * Every emitter works the same way — the page declares what it rendered, the markup goes
 * out in the footer — so only the shape of the graph is left to subclasses.
 */
abstract class BufferedEmitter implements SchemaEmitter {

	/** After StructuredData::publish(), which runs at 10. */
	private const int FOOTER_PRIORITY = 20;

	private ?ReviewSchema $schema = null;

	final public function id(): string {
		return static::ID;
	}

	public function register(): void {
		add_action( 'wp_footer', $this->render( ... ), self::FOOTER_PRIORITY );
	}

	final public function emit( ReviewSchema $schema ): void {
		$this->schema = $schema;
	}

	final public function render(): void {
		if ( ! $this->has_something_to_publish() ) {
			return;
		}

		// JSON_HEX_TAG matters even though core now refuses to set a script body containing
		// </script>: without it, a single review quoting that string would make the whole
		// graph vanish rather than be published.
		wp_print_inline_script_tag(
			(string) wp_json_encode( $this->build( $this->schema ), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ),
			array( 'type' => 'application/ld+json' )
		);
	}

	/**
	 * The graph to print.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function build( ReviewSchema $schema ): array;

	protected function has_something_to_publish(): bool {
		return $this->schema instanceof ReviewSchema && ! $this->schema->is_empty();
	}
}
