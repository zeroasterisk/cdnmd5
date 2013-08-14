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
	public $testfile_js = null;
	public $testfile_js_hash = null;
	public $testfile_css = null;
	public $testfile_css_hash = null;
	public $testfile_img = null;
	public $testfile_img_content = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVQIW2P4zwAAAgEBAFb7bLkAAAAASUVORK5CYII=';


	/**
	 * We need some test files to work with...
	 */
	public function setupTestFiles() {
		$css = '
		.example-urla {
			color: black;
			background: transparent url("/img/cdnmd5test.png") no-repeat center center;
		}
		.example-background-image {
			background-image: url("/img/cdnmd5test.png");
		}
		.example-background-a {
			background-image: url(\'/img/cdnmd5test-a.png\');
		}
		.example-background-b {
			background-image: url("/img/cdnmd5test-b.png");
		}
		.example-background-c {
			background-image: url(/img/cdnmd5test-c.png);
		}
		.example-background-relative {
			background-image: url("../img/cdnmd5test-relative.png");
		}
		.example-background-missing {
			background-image: url("/img/cdnmd5test-missing-file.png");
		}
		';
		$this->testfile_css = WWW_ROOT . 'css' . DS . 'cdnmd5test.css';
		file_put_contents($this->testfile_css, $css);
		$this->testfile_css_hash = md5(file_get_contents($this->testfile_css));

		$js = '
		blah = "blah blah";
		var stuff = function() { alert(\'yo\'); };
		junk = ' . rand() . time() . ';
		';
		$this->testfile_js = WWW_ROOT . 'js' . DS . 'cdnmd5test.js';
		file_put_contents($this->testfile_js, $js);
		$this->testfile_js_hash = md5(file_get_contents($this->testfile_js));

		$this->testfile_img = WWW_ROOT . 'img' . DS . 'cdnmd5test.png';
		$testfile_img_content = base64_decode($this->testfile_img_content);
		file_put_contents($this->testfile_img, $testfile_img_content);
		file_put_contents(str_replace('.png', '-a.png', $this->testfile_img), $testfile_img_content);
		file_put_contents(str_replace('.png', '-b.png', $this->testfile_img), $testfile_img_content);
		file_put_contents(str_replace('.png', '-c.png', $this->testfile_img), $testfile_img_content);
		file_put_contents(str_replace('.png', '-relative.png', $this->testfile_img), $testfile_img_content);
	}

	/**
	 * setup tests
	 */
	public function startTest() {
		$_this = Cdnmd5::getInstance();
		$this->Config_path = $_this->config['Config']['path'];
		$this->setupTestFiles();
	}

	/**
	 * tear down test
	 */
	public function endTest() {
		@unlink($this->testfile_js);
		@unlink($this->testfile_css);
		/*
		@unlink($this->testfile_img);
		@unlink(str_replace('.png', '-a.png', $this->testfile_img));
		@unlink(str_replace('.png', '-b.png', $this->testfile_img));
		@unlink(str_replace('.png', '-c.png', $this->testfile_img));
		@unlink(str_replace('.png', '-relative.png', $this->testfile_img));
		 */
		@unlink(Cdnmd5::getConfigFile($this->testfile_js));
		@unlink(Cdnmd5::getConfigFile($this->testfile_css));
		@unlink(Cdnmd5::getConfigFile($this->testfile_img));
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
	 *
	 */
	public function text_getFullPath() {
		$expect = $input = WWW_ROOT . 'index.php';
		// simple passthrough, no cleanFilename or anything
		$this->assertEqual($expect, Cdnmd5::getFullPath($input));
		$input = Cdnmd5::cleanWWW($expect);
		$this->assertEqual($expect, Cdnmd5::getFullPath($input));
		$this->assertFalse(Cdnmd5::getFullPath($input . 'no-exist'));
	}

	/**
	 */
	public function test_getConfigFile() {
		$expect = $this->Config_path . 'something-cool_here.png.md5';
		$input = 'something-cool_here.png';
		$this->assertEqual($expect, Cdnmd5::getConfigFile($input));
		$input = 'somepath/something-cool_here.png';
		$expect = $this->Config_path . 'somepath_something-cool_here.png.md5';
		$this->assertEqual($expect, Cdnmd5::getConfigFile($input));
		$input = '/any/old/path/something-cool_here.png?blah-blah=blah';
		$expect = $this->Config_path . 'any_old_path_something-cool_here.png.md5';
		$this->assertEqual($expect, Cdnmd5::getConfigFile($input));
		$input = APP . 'special-path/something-cool!here.png';
		$expect = $this->Config_path . 'special-path_something-cool_here.png.md5';
		$this->assertEqual($expect, Cdnmd5::getConfigFile($input));
		$input = WWW_ROOT . 'special-path/something-cool!here.png';
		$expect = $this->Config_path . 'special-path_something-cool_here.png.md5';
		$this->assertEqual($expect, Cdnmd5::getConfigFile($input));
		$input = APP . 'View/special-path/something-cool!here.png';
		$expect = $this->Config_path . 'special-path_something-cool_here.png.md5';
		$this->assertEqual($expect, Cdnmd5::getConfigFile($input));
	}

	/**
	 */
	public function test_makeHash() {
		$this->assertTrue(file_exists($this->testfile_js));
		$this->assertFalse(file_exists(Cdnmd5::getConfigFile($this->testfile_js)));
		$this->assertEqual($this->testfile_js_hash, Cdnmd5::makeHash($this->testfile_js));
		$this->assertTrue(file_exists(Cdnmd5::getConfigFile($this->testfile_js)));
		$this->assertEqual($this->testfile_js_hash, file_get_contents(Cdnmd5::getConfigFile($this->testfile_js)));
	}

	/**
	 *
	 */
	public function test_getHash() {
		$this->assertTrue(file_exists($this->testfile_js));
		// hash doesn't exist
		$this->assertFalse(file_exists(Cdnmd5::getConfigFile($this->testfile_js)));
		$this->assertFalse(Cdnmd5::getHash($this->testfile_js));
		// make hash
		$this->assertEqual($this->testfile_js_hash, Cdnmd5::makeHash($this->testfile_js));
		$this->assertTrue(file_exists(Cdnmd5::getConfigFile($this->testfile_js)));
		$this->assertEqual($this->testfile_js_hash, Cdnmd5::getHash($this->testfile_js));
	}

	/**
	 *
	 */
	public function test_getCdnfilename_js() {
		$input = $this->testfile_js;
		$this->assertFalse(Cdnmd5::getCdnfilename($input));
		$this->assertEqual($this->testfile_js_hash, Cdnmd5::makeHash($this->testfile_js));
		$expect = Cdnmd5::cleanWWW($this->testfile_js);
		$expect = str_replace('.js', '_' . $this->testfile_js_hash . '.js', $expect);
		$expect = trim($expect, '/');
		$this->assertEqual($expect, Cdnmd5::getCdnfilename($input));
	}

	/**
	 *
	 */
	public function test_getCdnfilename_css() {
		$input = $this->testfile_css;
		$this->assertFalse(Cdnmd5::getCdnfilename($input));
		$this->assertEqual($this->testfile_css_hash, Cdnmd5::makeHash($this->testfile_css));
		$expect = Cdnmd5::cleanWWW($this->testfile_css);
		$expect = str_replace('.css', '_' . $this->testfile_css_hash . '.css', $expect);
		$expect = trim($expect, '/');
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
		$input = $this->testfile_js;
		$expect_local = '/js/' . basename($input);
		$this->assertEqual($expect_local, Cdnmd5::url($input));
		// make hash
		$this->assertEqual($this->testfile_js_hash, Cdnmd5::makeHash($this->testfile_js));
		$_this = Cdnmd5::getInstance();
		$expect = $_this->config['CDN']['http'] . '/' . $_this->getCdnfilename($this->testfile_js);
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
		$this->assertTrue(Cdnmd5::transfer($this->testfile_js));
	}

	/**
	 * Currently only setup on RSC
	 */
	public function test_purge() {
		// ensure THIS file exists as a valid hash
		$this->assertEqual($this->testfile_js_hash, Cdnmd5::makeHash($this->testfile_js));
		$purgedFilesInt = Cdnmd5::purge('-1 sec');
		$this->assertEqual(0, $purgedFilesInt);
		// remove THIS file as a valid hash
		@unlink(Cdnmd5::getConfigFile($this->testfile_js));
		$purgedFilesInt = Cdnmd5::purge('-1 sec');
		// NOTE: this may not work, if this file was modified and uploaded in
		// less than 1 sec... (hash/modified timstampes will differ)
		//   also there are a few hours timezone discrepancies...
		#$this->assertEqual(1, $purgedFilesInt);
		// TODO: idea for better test, upload some "trash" files and ensure those are purged
	}

	/**
	 *
	 *
	 */
	public function test_parseUrlsFromCss() {
		// parse out the urls from CSS content
		$expect = array(
			'/img/cdnmd5test.png',
			'/img/cdnmd5test-a.png',
			'/img/cdnmd5test-b.png',
			'/img/cdnmd5test-c.png',
			'../img/cdnmd5test-relative.png',
			'/img/cdnmd5test-missing-file.png',
		);
		$content = file_get_contents($this->testfile_css);
		$this->assertEqual($expect, Cdnmd5::parseUrlsFromCSS($content));
		$expect = array();
		$this->assertEqual($expect, Cdnmd5::parseUrlsFromCSS(null));
		$this->assertEqual($expect, Cdnmd5::parseUrlsFromCSS(true));
		$this->assertEqual($expect, Cdnmd5::parseUrlsFromCSS(false));
		$this->assertEqual($expect, Cdnmd5::parseUrlsFromCSS(''));
		$this->assertEqual($expect, Cdnmd5::parseUrlsFromCSS('.example-css { color: funky; }'));
	}

	/**
	 *
	 */
	public function test_cssFilepathNormalize() {
		$basedir = dirname(Cdnmd5::cleanWWW($this->testfile_css));
		$input = '/img/cdnmd5test.png';
		$expect = $input;
		$this->assertEqual($expect, Cdnmd5::cssFilepathNormalize($input, $basedir));
		$input = 'img/cdnmd5test.png';
		$expect = '/css/img/cdnmd5test.png';
		$this->assertEqual($expect, Cdnmd5::cssFilepathNormalize($input, $basedir));
		$input = '../img/cdnmd5test.png';
		$expect = '/css/../img/cdnmd5test.png';
		$this->assertEqual($expect, Cdnmd5::cssFilepathNormalize($input, $basedir));
		// no validation for exising file in this method
		$input = '/img/image-does-not-exist.png';
		$expect = $input;
		$this->assertEqual($expect, Cdnmd5::cssFilepathNormalize($input, $basedir));
	}

	/**
	 *
	 *
	 */
	public function test_translate() {
		// do nothing for js or any other type of files
		$this->assertEqual($this->testfile_js, Cdnmd5::makeTranslation($this->testfile_js));
		// translate css files
		$expect = str_replace('.css', '_translated.css', $this->testfile_css);
		$this->assertEqual($expect, Cdnmd5::makeTranslation($this->testfile_css));
	}
}

