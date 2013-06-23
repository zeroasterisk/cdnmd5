<?php
/**
 * This is a test for the Cdnmd5Rsc Lib
 *   (an adaptor extension of Cdnmd5 Lib)
 *
 */
App::Import('Lib', 'Cdnmd5.Cdnmd5');

class Cdnmd5Test extends CakeTestCase {

	// set in startTest
	public $path_config = null;
	public $testfilepath = null;
	public $testfilehash = null;

	/**
	 * setup tests
	 */
	public function startTest() {
	}

	/**
	 * tear down test
	 */
	public function endTest() {
	}

	// TODO: build out tests specific to RSC adaptor
}
