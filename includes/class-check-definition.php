<?php
/**
 * Declarative description of one diagnostic check.
 *
 * Register checks on the "pihc_register_checks" action:
 *
 *   add_action( 'pihc_register_checks', function ( Diagnostic_Registry $registry ) {
 *       $registry->add( new Check_Definition( array(
 *           'id'       => 'custom.my_check',
 *           'name'     => 'My check',
 *           'category' => Check_Definition::CATEGORY_CONFIGURATION,
 *           'callback' => function ( Diagnostic_Context $context ): Diagnostic_Result { ... },
 *           'requires' => array( Diagnostic_Context::REQUIRES_SECRET_KEY ),
 *       ) ) );
 *   } );
 *
 * See docs/DEVELOPER.md.
 *
 * @package PaystackHealthChecker
 */

namespace PaystackHealthChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Check metadata plus the callable that performs it.
 */
final class Check_Definition {

	public const CATEGORY_ENVIRONMENT   = 'environment';
	public const CATEGORY_CONFIGURATION = 'configuration';
	public const CATEGORY_CONNECTIVITY  = 'connectivity';
	public const CATEGORY_WEBHOOKS      = 'webhooks';
	public const CATEGORY_WOOCOMMERCE   = 'woocommerce';
	public const CATEGORY_PAYMENTS      = 'payments';

	/**
	 * Check ID (dot-separated, unique).
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Human-readable name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Category constant.
	 *
	 * @var string
	 */
	private $category;

	/**
	 * What the check verifies, shown in the UI.
	 *
	 * @var string
	 */
	private $description;

	/**
	 * Callable: fn( Diagnostic_Context ): Diagnostic_Result.
	 *
	 * @var callable
	 */
	private $callback;

	/**
	 * Prerequisite identifiers (Diagnostic_Context::REQUIRES_*).
	 *
	 * @var string[]
	 */
	private $requires;

	/**
	 * Whether the check runs as part of "Run diagnostics".
	 *
	 * @var bool
	 */
	private $in_full_run;

	/**
	 * Whether the check makes outbound HTTP requests (rate limited).
	 *
	 * @var bool
	 */
	private $network;

	/**
	 * Documentation URL.
	 *
	 * @var string
	 */
	private $doc_url;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $args id, name, category, callback, description, requires, in_full_run, network, doc_url.
	 * @throws \InvalidArgumentException When required arguments are missing.
	 */
	public function __construct( array $args ) {
		if ( empty( $args['id'] ) || ! preg_match( '/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', (string) $args['id'] ) ) {
			throw new \InvalidArgumentException( 'Check IDs must look like "category.name".' );
		}
		if ( empty( $args['name'] ) || empty( $args['category'] ) || ! isset( $args['callback'] ) || ! is_callable( $args['callback'] ) ) {
			throw new \InvalidArgumentException( 'A check needs a name, a category and a callable.' );
		}

		$this->id          = (string) $args['id'];
		$this->name        = (string) $args['name'];
		$this->category    = (string) $args['category'];
		$this->callback    = $args['callback'];
		$this->description = (string) ( $args['description'] ?? '' );
		$this->requires    = array_values( array_map( 'strval', (array) ( $args['requires'] ?? array() ) ) );
		$this->in_full_run = (bool) ( $args['in_full_run'] ?? true );
		$this->network     = (bool) ( $args['network'] ?? false );
		$this->doc_url     = (string) ( $args['doc_url'] ?? '' );
	}

	/**
	 * Check ID.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Category.
	 */
	public function category(): string {
		return $this->category;
	}

	/**
	 * Description.
	 */
	public function description(): string {
		return $this->description;
	}

	/**
	 * Prerequisites.
	 *
	 * @return string[]
	 */
	public function requires(): array {
		return $this->requires;
	}

	/**
	 * Whether the check is part of the full run.
	 */
	public function in_full_run(): bool {
		return $this->in_full_run;
	}

	/**
	 * Whether the check makes outbound requests.
	 */
	public function is_network(): bool {
		return $this->network;
	}

	/**
	 * Documentation URL.
	 */
	public function doc_url(): string {
		return $this->doc_url;
	}

	/**
	 * Runs the check callback.
	 *
	 * @param Diagnostic_Context $context Context.
	 * @return mixed Expected to be a Diagnostic_Result; the manager validates it.
	 */
	public function run( Diagnostic_Context $context ) {
		return call_user_func( $this->callback, $context );
	}
}
