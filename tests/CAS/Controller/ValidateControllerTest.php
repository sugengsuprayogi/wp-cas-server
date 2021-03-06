<?php

use Cassava\CAS;
use Cassava\Exception\RequestException;
use Cassava\Exception\TicketException;

/**
 * @coversDefaultClass \Cassava\CAS\Controller\ValidateController
 */
class TestWPCASControllerValidate extends WPCAS_UnitTestCase {

	private $server;
	private $controller;

	/**
	 * Setup a test method for the WPCASControllerValidate class.
	 */
	function setUp() {
		parent::setUp();
		$this->server     = new CAS\Server();
		$this->controller = new CAS\Controller\ValidateController( $this->server );
	}

	/**
	 * Finish a test method for the WPCASControllerValidate class.
	 */
	function tearDown() {
		parent::tearDown();
		unset( $this->server );
		unset( $this->controller );
	}

	/**
	 * @covers ::__construct
	 */
	function test_construct () {
		$this->assertTrue( is_a( $this->controller, '\Cassava\CAS\Controller\BaseController' ),
			'ValidateController extends BaseController.' );
	}

	/**
	 * @runInSeparateProcess
	 * @covers ::validate
	 */
	function test_validate () {

		/**
		 * No service.
		 */
		$args = array(
			'service' => '',
			'ticket'  => 'ticket',
			);

		$this->assertEquals( $this->controller->handleRequest( $args ), "no\n\n",
			"Error on empty service." );

		/**
		 * No ticket.
		 */
		$args = array(
			'service' => 'http://test.local/',
			'ticket'  => '',
			);

		$this->assertEquals( $this->controller->handleRequest( $args ), "no\n\n",
			"Error on empty ticket." );

		/**
		 * Invalid ticket.
		 */
		$args = array(
			'service' => 'http://test.local/',
			'ticket'  => 'bad-ticket',
			);

		$this->assertEquals( $this->controller->handleRequest( $args ), "no\n\n",
			"Error on invalid ticket." );

		/**
		 * Valid ticket.
		 */
		$service = 'http://test/';
		$user_id = $this->factory->user->create();

		wp_set_current_user( $user_id );

		try {
			$login = new CAS\Controller\LoginController( $this->server );
			$login->handleRequest( array( 'service' => $service ) );
		}
		catch (WPDieException $message) {
			parse_str( parse_url( $this->redirect_location, PHP_URL_QUERY ), $query );
		}

		$args = array(
			'service' => $service,
			'ticket'  => $query['ticket'],
			);

		$user = get_user_by( 'id', $user_id );

		$this->assertEquals( "yes\n" . $user->user_login . "\n", $this->controller->handleRequest( $args ),
			"Valid ticket." );

		Cassava\Options::set( 'allow_ticket_reuse', 1 );

		$this->assertEquals( "yes\n" . $user->user_login . "\n", $this->controller->handleRequest( $args ),
			"Tickets may reused." );

		Cassava\Options::set( 'allow_ticket_reuse', 0 );

		$this->assertEquals( "no\n\n", $this->controller->handleRequest( $args ),
			"Tickets may not be reused." );

		$this->markTestIncomplete( "Test support for the optional 'pgtUrl' and 'renew' parameters." );
	}

}

