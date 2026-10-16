# Developer guide: adding a diagnostic check

Every check is a `Check_Definition` in the `Diagnostic_Registry`. The dashboard, category tabs, full run, single re-runs, history, trend comparison and exports all read from the registry, so a registered check appears everywhere automatically.

## 1. Register the check

From another plugin or a mu-plugin:

```php
use PaystackHealthChecker\Check_Definition;
use PaystackHealthChecker\Diagnostic_Context;
use PaystackHealthChecker\Diagnostic_Registry;
use PaystackHealthChecker\Diagnostic_Result;

add_action( 'pihc_register_checks', function ( Diagnostic_Registry $registry ) {
	$registry->add( new Check_Definition( array(
		'id'          => 'custom.callback_page',          // "category.name", unique
		'name'        => 'Payment callback page published',
		'category'    => Check_Definition::CATEGORY_CONFIGURATION, // decides the tab it appears on
		'description' => 'Shown before the check has run.',
		'callback'    => 'my_callback_page_check',
		'requires'    => array( Diagnostic_Context::REQUIRES_INTEGRATION ),
		'in_full_run' => true,    // false = manual only ("Run this check")
		'network'     => false,   // true if it makes outbound requests
		'doc_url'     => 'https://example.com/docs/callback-page',
	) ) );
} );
```

Built-in checks register the same way, in `includes/checks/class-*-checks.php`.

Categories: `CATEGORY_ENVIRONMENT`, `CATEGORY_CONFIGURATION`, `CATEGORY_CONNECTIVITY`, `CATEGORY_WEBHOOKS`, `CATEGORY_WOOCOMMERCE`, `CATEGORY_PAYMENTS`.

## 2. Declare prerequisites

`requires` lists conditions that must hold before the callback runs. If one is unmet, the manager records **SKIPPED** with an explanation and **never calls your callback**.

| Constant | Meaning |
|---|---|
| `Diagnostic_Context::REQUIRES_WOOCOMMERCE` | WooCommerce is active |
| `Diagnostic_Context::REQUIRES_INTEGRATION` | A Paystack integration was found or selected |
| `Diagnostic_Context::REQUIRES_SECRET_KEY` | The integration has a secret key for its active environment |
| `Diagnostic_Context::REQUIRES_GATEWAY` | WooCommerce is active and the integration is a WooCommerce gateway |
| `Diagnostic_Context::REQUIRES_WEBHOOK_URL` | The integration's webhook URL is known |

Use prerequisites instead of returning SKIPPED yourself whenever the reason is "this does not apply here". Return SKIPPED from the callback only when something is not observable at run time.

## 3. Return a result

The callback receives the per-run `Diagnostic_Context` and returns a `Diagnostic_Result`:

```php
function my_callback_page_check( Diagnostic_Context $context ): Diagnostic_Result {
	$page = get_page_by_path( 'payment-complete' );

	if ( ! $page ) {
		return Diagnostic_Result::fail(
			'The payment callback page does not exist.',            // summary: one sentence, what is wrong
			array(
				'explanation' => 'Customers returning from Paystack land on a 404.', // why it matters
				'evidence'    => array( 'looked_for' => '/payment-complete' ),       // sanitized facts
				'next_steps'  => array( 'Create the page or update the callback URL.' ),
				'kb'          => 'missing_webhooks',   // optional Troubleshooting entry ID
			)
		);
	}

	return Diagnostic_Result::pass( 'The payment callback page exists.', array(
		'evidence' => array( 'page_id' => $page->ID, 'status' => $page->post_status ),
	) );
}
```

### Choosing a status

| Use | When |
|---|---|
| `pass()` | The requirement is satisfied. |
| `warning()` | A **potential** problem: risky configuration, or evidence that suggests but does not prove a fault. Say what is still unknown. |
| `fail()` | Your evidence **confirms** the problem. If you're not sure, use `warning()`. |
| `skipped()` | The check could not be performed (not observable, unsafe). Skipped results never affect the score. |

### Rules

- **Evidence must be sanitized and minimal.** The manager redacts evidence (keys, bearer tokens, e-mails, card-like numbers, sensitive key names such as `secret`, `email`, `customer`, `authorization`) before storing it. Don't rely on that: don't put secrets or customer data in evidence at all. Name evidence keys after facts (`key_environment`), not after secrets (`secret_key_prefix`), or the redactor will hide them.
- **Be read-only.** Never save orders, options or gateway settings in a check.
- **Network access:** only call Paystack through `$context->api()` (fixed base URL, documented endpoints), or the site's own URLs after `Security::is_own_site_url()`. Never request a user-supplied URL. Reuse expensive probes with `$context->remember( 'key', fn )` or `$context->authentication_probe()`, so a run makes each request once.
- **Exceptions are contained.** The manager turns them into SKIPPED ("could not be completed"), but expected failures should be results, not exceptions.
- The manager adds the check ID, name, category, timestamp and duration. Don't set those yourself.

## 4. Add an integration adapter (optional)

To support another Paystack plugin, implement `Integrations\Integration_Adapter`, or extend `Integrations\Gateway_Adapter` for WooCommerce gateways, and add it through the filter:

```php
add_filter( 'pihc_integration_adapters', function ( array $adapters ) {
	array_unshift( $adapters, new My_Paystack_Plugin_Adapter() );
	return $adapters;
} );
```

All existing checks then work for that integration: keys, environment, webhook URL and handler, log sources, currencies, and HPOS/Blocks declarations through `plugin_basename()`.

## 5. Add tests

Integration tests extend `PaystackHealthChecker\Tests\PIHC_Test_Case` (see `tests/integration/class-pihc-test-case.php`):

```php
class CallbackPageCheckTest extends PIHC_Test_Case {

	public function test_missing_page_fails(): void {
		$this->use_official();                    // or use_pwcg() / use_manual()

		$result = $this->run_check( 'custom.callback_page' );   // runs through the real manager

		$this->assertStatus( Diagnostic_Result::FAIL, $result );
		$this->assertNotEmpty( $result->next_steps() );
	}

	public function test_api_based_check(): void {
		$this->use_official();
		$this->mock_api( self::timeout_ok() );    // or self::response( 401, [...] ), WP_Error, callable
		$this->mock_loopback( self::response( 200, '' ) );

		// ... run, then assert on $this->requests (every outbound request is recorded)
	}
}
```

- Unmocked HTTP requests fail the test, so tests can never reach Paystack.
- Cover at least: PASS, each FAIL/WARNING branch, the SKIPPED prerequisite path, and that no secret or customer data appears in `wp_json_encode( $result->to_array() )`.
- If the check makes requests, add its expected host to the reasoning in `SecurityAndExportTest::test_all_outbound_requests_go_only_to_paystack_or_this_site` (it runs every registered check).
- Run `vendor/bin/phpunit` and `vendor/bin/phpcs` before submitting.

## 6. Add a troubleshooting entry (optional)

```php
add_filter( 'pihc_troubleshooting_entries', function ( array $entries ) {
	$entries['callback_page'] = array(
		'title'    => 'Callback page missing',
		'symptoms' => '...',
		'causes'   => array( '...' ),
		'confirm'  => array( '...' ),
		'fix'      => array( '...' ),
	);
	return $entries;
} );
```

Then reference it with `'kb' => 'callback_page'` in your results.
