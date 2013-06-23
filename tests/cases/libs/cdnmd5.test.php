<?php
/**
 * This is a test for the Cdnmd5 Lib
 * It requires funcitonality on the Cdnmd5Rsc Lib
 *   (an adaptor extension of this)
 *
 */
App::Import('Lib', 'Cdnmd5.Cdnmd5');

class Cdnmd5Test extends CakeTestCase {

	// set in startTest
	public $Config_path = null;
	public $testfilepath = null;
	public $testfilehash = null;

	/**
	 * setup tests
	 */
	public function startTest() {
		$_this = Cdnmd5::getInstance();
		$this->Config_path = $_this->config['Config']['path'];
		$this->testfilepath = __FILE__;
		$this->testfilehash = md5(file_get_contents(__FILE__));
	}

	/**
	 * tear down test
	 */
	public function endTest() {
		@unlink(Cdnmd5::getConfigFile($this->testfilepath));
		$_this = Cdnmd5::getInstance();
		$_this->config['disabled'] = false;
	}

	/**
	 */
	public function test_setConfig() {
		$_this = Cdnmd5::getInstance();
		$_this->setConfig(array('newkey' => 'newval', 'CDN' => array('container' => 'unittests')));
		$this->assertEqual('newval', $_this->config['newkey']);
		$this->assertEqual('unittests', $_this->config['CDN']['container']);
		// verify that the path gets prefixed with the APP path
		$this->assertTrue(false === strpos($_this->config['Config']['path'], 'cruft' . APP));
		$this->assertFalse(false === strpos($_this->config['Config']['path'], APP));
		$this->assertEqual('/', substr($_this->config['Config']['path'], 0, 1));
		$this->assertEqual('/', substr($_this->config['Config']['path'], -1));
	}

	/**
	 */
	public function test_cleanFilename() {
		$input = 'something-cool_here.png';
		$this->assertEqual('something-cool_here.png', Cdnmd5::cleanFilename($input));
		$input = 'something-cool_here.png?blah-blah=blah';
		$this->assertEqual('something-cool_here.png', Cdnmd5::cleanFilename($input));
		$input = 'something-cool!here.png';
		$this->assertEqual('something-cool_here.png', Cdnmd5::cleanFilename($input));
		$input = 'something-cool here.png';
		$this->assertEqual('something-cool_here.png', Cdnmd5::cleanFilename($input));
		$input = "something-cool\n\%\$^*(*)#$%here.png";
		$this->assertEqual('something-cool_here.png', Cdnmd5::cleanFilename($input));
		$input = 'something-cool%20here.png';
		$this->assertEqual('something-cool_20here.png', Cdnmd5::cleanFilename($input));
	}

	/**
	 *
	 */
	public function text_cleanWWW() {
		// simple passthrough, no cleanFilename or anything
		$this->assertEqual('something!cool.png', Cdnmd5::cleanWWW('something!cool.png'));
		// but we do strip off APP & WWW_ROOT paths
		$this->assertEqual('/path/file.png', Cdnmd5::cleanWWW(APP . 'path/file.png'));
		$this->assertEqual('/path/file.png', Cdnmd5::cleanWWW(WWW_ROOT . 'path/file.png'));
		// and we also strip off '/webroot/' and anything before it
		$this->assertEqual('/path/file.png', Cdnmd5::cleanWWW('/something/webroot/path/file.png'));
		$this->assertEqual('/path/file.png', Cdnmd5::cleanWWW(WWW_ROOT . 'some/webroot/webroot/path/file.png'));
	}

	/**
	 */
	public function test_getConfigFile() {
		$expect = $this->Config_path . 'something-cool_here.png.md5';
		$input = 'something-cool_here.png';
		$this->assertEqual($expect, Cdnmd5::getConfigFile($input));
		$input = 'somepath/something-cool_here.png';
		$this->assertEqual($expect, Cdnmd5::getConfigFile($input));
		$input = '/any/old/path/something-cool_here.png?blah-blah=blah';
		$this->assertEqual($expect, Cdnmd5::getConfigFile($input));
		$input = APP . 'special-path/something-cool!here.png';
		$this->assertEqual($expect, Cdnmd5::getConfigFile($input));
		$input = $this->Config_path . 'something-cool!here.png';
		$this->assertEqual($expect, Cdnmd5::getConfigFile($input));
	}

	/**
	 */
	public function test_makeHash() {
		$this->assertTrue(file_exists($this->testfilepath));
		$this->assertFalse(file_exists(Cdnmd5::getConfigFile($this->testfilepath)));
		$this->assertEqual($this->testfilehash, Cdnmd5::makeHash($this->testfilepath));
		$this->assertTrue(file_exists(Cdnmd5::getConfigFile($this->testfilepath)));
		$this->assertEqual($this->testfilehash, file_get_contents(Cdnmd5::getConfigFile($this->testfilepath)));
	}

	/**
	 *
	 */
	public function test_getHash() {
		$this->assertTrue(file_exists($this->testfilepath));
		// hash doesn't exist
		$this->assertFalse(file_exists(Cdnmd5::getConfigFile($this->testfilepath)));
		$this->assertFalse(Cdnmd5::getHash($this->testfilepath));
		// make hash
		$this->assertEqual($this->testfilehash, Cdnmd5::makeHash($this->testfilepath));
		$this->assertTrue(file_exists(Cdnmd5::getConfigFile($this->testfilepath)));
		$this->assertEqual($this->testfilehash, Cdnmd5::getHash($this->testfilepath));
	}

	/**
	 *
	 */
	public function test_getCdnfilename() {
		$input = $this->testfilepath;
		$this->assertFalse(Cdnmd5::getCdnfilename($input));
		// make hash
		$this->assertEqual($this->testfilehash, Cdnmd5::makeHash($this->testfilepath));
		$expect = basename($this->testfilepath);
		$expect = str_replace('.php', '_' . $this->testfilehash . '.php', $expect);
		$this->assertEqual($expect, Cdnmd5::getCdnfilename($input));
	}

	/**
	 *
	 */
	public function test_url() {
		// bad inputs
		$this->assertFalse(Cdnmd5::url(false));
		$this->assertFalse(Cdnmd5::url(null));
		$this->assertFalse(Cdnmd5::url(''));
		// missing hash
		$input = $this->testfilepath;
		$expect_local = '/tests/cases/libs/' . basename($input);
		$this->assertEqual($expect_local, Cdnmd5::url($input));
		// make hash
		$this->assertEqual($this->testfilehash, Cdnmd5::makeHash($this->testfilepath));
		$_this = Cdnmd5::getInstance();
		$expect = $_this->config['CDN']['http'] . '/' . $_this->getCdnfilename($this->testfilepath);
		$this->assertEqual($expect, Cdnmd5::url($input));
		// disable (back to local)
		$_this = Cdnmd5::getInstance();
		$_this->config['Presentation']['disabled'] = true;
		$this->assertEqual($expect_local, Cdnmd5::url($input));
	}

	/**
	 * Currently only setup on RSC and the config should be cleaned
	 */
	public function test_transfer() {
		$this->assertTrue(Cdnmd5::transfer($this->testfilepath));
	}

	/**
	 * Currently only setup on RSC
	 */
	public function test_purge() {
		// ensure THIS file exists as a valid hash
		$this->assertEqual($this->testfilehash, Cdnmd5::makeHash($this->testfilepath));
		$purgedFilesInt = Cdnmd5::purge('-1 sec');
		$this->assertEqual(0, $purgedFilesInt);
		// remove THIS file as a valid hash
		@unlink(Cdnmd5::getConfigFile($this->testfilepath));
		$purgedFilesInt = Cdnmd5::purge('-1 sec');
		// NOTE: this may not work, if this file was modified and uploaded in
		// less than 1 sec... (hash/modified timstampes will differ)
		//   also there are a few hours timezone discrepancies...
		#$this->assertEqual(1, $purgedFilesInt);
		// TODO: idea for better test, upload some "trash" files and ensure those are purged
	}
}

