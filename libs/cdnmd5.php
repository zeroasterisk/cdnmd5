<?php
/**
 * File Assets need to be stored on a CDN
 *   but each "specific" version of the files need to be accessed,
 *   and sometimes we are going forward in time, and backward in time,
 *   all versions need to be available, uniquely
 *
 * As such, the basic plan is:
 *
 *   on file creation/editing:
 *   before you use this library, create the file...
 *   then use this library to:
 *
 *   1) make a md5sum of the file ($md5hash)
 *   2) store the md5sum of the file in a config file (commited to git)
 *   3) make a copy of the file renamed to "${filename}_${md5hash}.${ext}"
 *   4) transfer the renamed copy to the CDN
 *
 * At the time of rendering the file:
 *
 *   Cdnmd5::url($filename) ==> http://domain/filename_md5hash.ext
 *
 *   This function looks up the md5hash stored in the config file and returns
 *   the URL to the CDN for the file...
 *
 *   If we are in development mode: Configure::read('Cdnmd5.disabled') == true
 *     OR
 *   If that md5hash doesn't exist for some reason (or is empty) we try to just
 *     load the "real" file from the local repository.
 *
 * Installation:
 *
 *
 * If using with Rackspace Files CDN:
 *
 *   git submodule add git://github.com/zeroasterisk/php-opencloud.git app/vendors/php-opencloud
 *
 * If using with Amazon Web Services, S3:
 *
 *   ((TODO))
 *
 *
 * Usage:
 *
 * After you create your CDNable assets (AssetCompress, Closure, Uglify, CssMin, etc)
 *
 * App::Import('Lib', 'Cdnmd5.Cdnmd5');
 * Cdnmd5::process(APP . $fullPathToFile);
 *
 * And how to render it in your Views
 *
 * App::Import('Lib', 'Cdnmd5.Cdnmd5');
 * Cdnmd5::url($webrootRelativePathTofile);
 *
 */
Class Cdnmd5 {
	/**
	 * This default config can be overwritten by app/config/cdnmd5.php
	 * It is cached onto the object at first use
	 *
	 * It can also be passed into several methods,
	 *   which overwrites the cached config
	 */
	public $config = array(
		// config for the views, the url() method
		'Presentation' => array(
			// setting this to true at any point, disables the url() method only
			//   a good way to develop locally before sending to CDN
			'disabled' => false,
			// setting this to true bypasses logic for figuring out if you are on
			//   http or https
			'alwaysUseHttps' => false,
		),
		// config for the config files/dirs
		'Config' => array(
			// path to the config directory where you want to store the md5 hashes
			'path' => /* APP */ 'config/cdnmd5/',
		),
		'CDN' => array(
			'type' => 'RSC',
			// the CDN container
			'container' => 'cdnmd5',
			// the domain should be the domain to the CDN
			//   this could be a cname alias or the CDN provided domain
			//   NOTE: https URLs and http URLs may differ
			'http' => 'http://aaaaaaaa.rackcdn.com',
			//   NOTE: https URLs may not work with a cname (certs)
			'https' => 'https://bbbbbbbb.rackcdn.com',
			// auth information for API
			'auth' => array(
				'username' => 'yourusername',
				'key' => 'yourapikey',
				//'account' => 'youraccountnumber', // not needed anymore
			),
		),
		// configuration for purging/cleanup
		'Purge' => array(
			// what's the default age of old files to start considering
			// NOTE: this would never purge any files with current md5hashes
			//   (regardless of age)
			// NOTE: thi sis not called automatically, you would have to call
			//   the purge method manually or on a cron
			'defaultOlderThan' => '-6 months',
		),
	);

	/**
	 * Returns a singleton instance of the class.
	 * This alows easy access via static method calls,
	 * but with an instantiated object
	 *
	 * @return CLASS instance
	 * @access public
	 */
	public static function &getInstance($config = array()) {
		static $instance = array();
		if (!$instance) {
			$instance[0] =& new Cdnmd5();
		}
		$instance[0]->_setConfig($config);
		return $instance[0];
	}

	/**
	 * Inject configuations and cleanup as needed
	 * (public version, easy to call statically)
	 *
	 * @param array $config
	 * @return boolean
	 */
	public static function setConfig($config = array()) {
		$_this = Cdnmd5::getInstance($config);
		return is_object($_this);
	}

	/**
	 * Inject configuations and cleanup as needed
	 * (private method which actually does the work)
	 *
	 * @param array $config or string $containerName
	 * @return boolean
	 */
	private function _setConfig($config = array()) {
		// insert the file config
		if (empty($this->config['loaded'])) {
			Configure::load('cdnmd5');
			$_config = Configure::read('Cdnmd5');
			if (is_array($_config)) {
				$this->config = Set::merge($this->config, $_config);
			}
			$this->config['loaded'] = true;
		}
		// add/overwrite config
		if (!empty($config) && is_string($config)) {
			// passed in a string, treat it as a container name
			$this->config['CDN']['container'] = $config;
		}
		if (!empty($config) && is_array($config)) {
			$this->config = Set::merge($this->config, $config);
		}
		// cleanup path_config to be a full APP decendant path
		$this->config['Config']['path'] =  APP . trim(trim(trim(str_replace(APP, '/', $this->config['Config']['path'])), '/')) . '/';
		// inject disabled or "developer mode" if configured independantly
		$disabled = Configure::read('Cdnmd5.Presentation.disabled');
		if (!empty($disabled)) {
			$this->config['Presentation']['disabled'] = true;
		}
		return true;
	}

	/**
	 * We don't funk funky characters...
	 * also we don't want paths, only basenames
	 *
	 * @param string $filepath or $filename
	 * @return string $filename (cleaned)
	 */
	public function cleanFilename($filepath) {
		$filename = basename($filepath);
		// remove any possible query string: filename.ext?soemthing=1
		$filenameparts = explode('?', $filename);
		$filename = array_shift($filenameparts);
		// clean off any non-clean characters
		$filename = trim(preg_replace('#[^0-9a-zA-Z\_\-\.]+#', '_', trim($filename)), '_');
		// split ext
		extract(Cdnmd5::splitFilename($filename));
		// verify ext & filenamebase
		if (empty($ext)) {
			throw new OutOfBoundsException('Cdnmd5::getCdnfilename - empty file extension');
		}
		if (empty($filenamebase)) {
			throw new OutOfBoundsException('Cdnmd5::getCdnfilename - empty file filenamebase');
		}
		return "{$filenamebase}.{$ext}";
	}

	/**
	 * the URL function returns a URL, but it could be a webroot relative
	 * version of a full file path (if not getting from CDN)...
	 *
	 * @param string $filepath (FULL path or webroot relative path)
	 * @return string $filepath (webroot relative path)
	 */
	public static function cleanWWW($filepath) {
		// quick exit, if filename only, no path
		if (strpos($filepath, '/') === false) {
			return $filepath;
		}
		if (strpos($filepath, APP) !== false) {
			// replace any FULL path with a /
			$filepath = str_replace(WWW_ROOT, '/', $filepath);
			$filepath = str_replace(APP, '/', $filepath);
		}
		// doublecheck because of craziness on __FILE__ pathing
		//   (dirty hackery for unit testing)
		$BASE = dirname(dirname(__FILE__)) . '/';
		if (strpos($filepath, $BASE) !== false) {
			// replace any FULL path with a /
			$filepath = str_replace($BASE, '/', $filepath);
		}
		if (strpos($filepath, 'webroot/') !== false) {
			// we only care about any part of the path after webroot
			$parts = explode('webroot/', $filepath);
			$filepath = array_pop($parts);
		}
		return '/' . trim($filepath, '/');
	}

	/**
	 * This is a super-simple helper to extract the filenamebase and ext
	 *
	 * @param string $filename
	 * @return array compact('ext', 'filenamebase')
	 */
	public function splitFilename($filename) {
		$filenameparts = explode('.', $filename);
		$ext = array_pop($filenameparts);
		$filenamebase = implode('.', $filenameparts);
		return compact('ext', 'filenamebase');
	}

	/**
	 * Translate a filepath (or just a filename) into a full path to
	 *
	 * @param string $filepath or $filename
	 * @return string $configFilePath full path to file containing md5hash
	 */
	public static function getConfigFile($filepath) {
		$_this = Cdnmd5::getInstance();
		return $_this->config['Config']['path'] . $_this->cleanFilename($filepath) . '.md5';
	}

	/**
	 * Calculate the md5 sum for a file & store it as a config file
	 *
	 * @param string $filepath (full PHP path to a file)
	 * @return string $md5hash
	 */
	public static function makeHash($filepath) {
		if (!is_file($filepath)) {
			throw new OutOfBoundsException('Cdnmd5::makeHash - not a valid file');
		}
		$_this = Cdnmd5::getInstance();
		// make hash for file
		$md5hash = md5(file_get_contents($filepath));
		// store to path_md5config / ${filename}.md5
		$config_file = $_this->getConfigFile($filepath);
		if (!is_dir(dirname($config_file))) {
			if (mkdir(dirname($config_file))) {
				debug(compact('config_file'));
				throw new OutOfBoundsException('Cdnmd5::makeHash - unable to make directory for "path_config"');
			}
		}
		if (!file_put_contents($config_file, $md5hash)) {
			debug(compact('config_file'));
			throw new OutOfBoundsException('Cdnmd5::makeHash - unable to write file');
		}
		return $md5hash;
	}


	/**
	 * Lookup the md5hash for a filename from the config file
	 *
	 * @param string $filepath (full PHP path to a file)
	 * @return string $md5hash or false
	 */
	public static function getHash($filepath) {
		$_this = Cdnmd5::getInstance();
		$config_file = $_this->getConfigFile($filepath);
		if (!is_file($config_file)) {
			return false;
		}
		return file_get_contents($config_file);
	}

	/**
	 * Simple means for converting a filepath into a cdn filename (with hash)
	 *
	 * @param string $filepath (full PHP path to a file)
	 * @return string $cdnfilename or false if no hash
	 */
	public static function getCdnfilename($filepath) {
		$_this = Cdnmd5::getInstance();
		$md5hash = $_this->getHash($filepath);
		if (empty($md5hash)) {
			return false;
		}
		$filename = $_this->cleanFilename($filepath);
		extract($_this->splitFilename($filename));
		// ^ sets filenamebase & ext
		return "{$filenamebase}_{$md5hash}.{$ext}";
	}

	/**
	 * Returns if request is HTTPS or not.
	 * @return boolean true if HTTPS, false if not.
	 */
	public static function isHttps(){
		return (
			(isset($_SERVER['HTTP_X_REMOTE_PROTOCOL']) && !empty($_SERVER['HTTP_X_REMOTE_PROTOCOL'])) ||
			(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']=='on') ||
			(isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT']==443));
	}

	# ----------------------------------
	# Main Callable Methods
	# ----------------------------------

	/**
	 * Determine the URL to return for any filepath
	 *   Called from a view or layout
	 *   Translates a webroot relative path to a CDN url
	 *
	 * input the full webroot relative path, so that if the CDN hash doesn't
	 * exist, or you are in development mode, you can still get the file
	 * (loaded locally)
	 *
	 * @param string $filepath (webroot relative)
	 * @return string $url
	 */
	public static function url($filepath, $protocol = null) {
		$_this = Cdnmd5::getInstance();
		if (is_array($filepath)) {
			foreach (array_keys($filepath) as $key) {
				$filepath[$key] = $_this->url($filepath[$key]);
			}
			return $filepath;
		}
		if (empty($filepath) || !is_string($filepath)) {
			return false;
		}
		if ($_this->config['Presentation']['disabled']) {
			return $_this->cleanWWW($filepath);
		}
		$cdnfilename = $_this->getCdnfilename($filepath);
		if (empty($cdnfilename)) {
			return $_this->cleanWWW($filepath);
		}
		if (empty($protocol) || empty($_this->config['CDN'][$protocol])) {
			$protocol = 'http';
			if ($_this->config['Presentation']['alwaysUseHttps'] || $_this->isHttps()) {
				$protocol = 'https';
			}
		}
		$start = $_this->config['CDN'][$protocol];
		return "{$start}/{$cdnfilename}";
	}

	/**
	 * Convienence shortcut to make a hash for a filepath and transfer it -> CDN
	 *
	 * @param string $filepath
	 * @param array $config (optionally pass in config to reset)
	 * @return bool
	 */
	public static function process($filepath, $config = array()) {
		$_this = Cdnmd5::getInstance($config);
		$_this->makeHash($filepath);
		return $_this->transfer($filepath);
	}

	/**
	 * Transfer the file to the CDN
	 *   Abstracted to CDN provider
	 *
	 * @param string $filepath
	 * @param array $config (optionally pass in config to reset)
	 * @return bool
	 */
	public static function transfer($filepath, $config = array()) {
		if (!is_file($filepath)) {
			throw new OutOfBoundsException('Cdnmd5::transfer - not a valid file');
		}
		$_this = Cdnmd5::getInstance($config);
		$cdnfilename = $_this->getCdnfilename($filepath);
		if (empty($cdnfilename)) {
			// ok - maybe we don't yet have a md5hash for this file
			$_this->makeHash($filepath);
			$cdnfilename = $_this->getCdnfilename($filepath);
		}
		if (empty($cdnfilename)) {
			debug(compact('filepath', 'cdnfilename'));
			throw new OutOfBoundsException('Cdnmd5::transfer - unable to setup cdn filename');
		}
		if ($_this->config['CDN']['type'] == 'RSC') {
			require_once(dirname(__file__) . '/cdnmd5_rsc.php');
			return Cdnmd5Rsc::transfer($filepath, $cdnfilename, $_this->config);
		}
		if ($_this->config['CDN']['type'] == 'S3') {
			throw new OutOfBoundsException('Cdnmd5::transfer - sorry, we have not built S3 support yet :(');
		}
		throw new OutOfBoundsException('Cdnmd5::transfer - unknown CDN type');
	}

	/**
	 * We create many, many versions of files with this class/approach
	 * This function walks through all files on the CDN
	 * and if the md5hash doesn't exist right now in the app
	 * and if the file is older than $olderThan (strtotime)
	 * then we delete it from the CDN
	 *
	 * @param string $olderThan (strtotime)
	 * @param array $config
	 * @return int $numberOfDeletedFiles
	 */
	public static function purge($olderThan = null, $config = array()) {
		$_this = Cdnmd5::getInstance($config);
		// validate olderThanEpoch
		$defaultOlderThan = $_this->config['Purge']['defaultOlderThan'];
		if (empty($olderThan)) {
			$olderThan = $defaultOlderThan;
		}
		$olderThanEpoch = strtotime($olderThan);
		if ($olderThan > time()) {
			// date in future... reset to defaultOlderThan
			$olderThanEpoch = strtotime($defaultOlderThan);
		}
		$olderThan = date('c', $olderThanEpoch);
		// list all "valid" md5hashes
		$md5hashes = array();
		$ConfigDir = new Folder($_this->config['Config']['path']);
		$dir = $ConfigDir->read();
		foreach ($dir[1] as $filename) {
			$md5hashes[] = file_get_contents($ConfigDir->path . $filename);
		}
		if ($_this->config['CDN']['type'] == 'RSC') {
			require_once(dirname(__file__) . '/cdnmd5_rsc.php');
			return Cdnmd5Rsc::purge($olderThan, $md5hashes, $_this->config);
		}
		if ($_this->config['CDN']['type'] == 'S3') {
			throw new OutOfBoundsException('Cdnmd5::purge - sorry, we have not built S3 support yet :(');
		}
		throw new OutOfBoundsException('Cdnmd5::purge - unknown CDN type');
	}
}

