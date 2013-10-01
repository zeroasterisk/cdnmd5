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
 * CSS files have a special "translation" step
 *
 * Since they can contain URLs to other files (images)
 * those URLs are gathered and translated independantly, a copy of the CSS file
 * is created, and it's transfered...  this allows CSS files to be moved to the
 * CDN and all linked images, etc are also transfered along with it, in one
 * simple step, all transfered files are MD5 hashed as well.
 *
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
 * App::uses('Cdnmd5', 'Cdnmd5.Lib');
 * Cdnmd5::process(APP . $fullPathToFile);
 *
 * And how to render it in your Views
 *
 * App::uses('Cdnmd5', 'Cdnmd5.Lib');
 * Cdnmd5::url($webrootRelativePathTofile);
 *
 */

App::uses('Folder', 'Utility');
App::uses('File', 'Utility');
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
			$instance[0] = new Cdnmd5();
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
	 * We support webroot relative paths & simple filenames
	 *
	 * @param string $filepath (FULL path or webroot relative path)
	 * @return string $filepath (webroot relative path)
	 */
	public function cleanPath($filepath) {
		return $this->cleanWWW($filepath);
	}

	/**
	 * Given any WWW path, this will verify the file exists
	 * and return the full path
	 *
	 * @param string $filepath (FULL path or webroot relative path)
	 * @return string $filepath (FULL path)
	 */
	public static function getFullPath($filepath) {
		if (is_file($filepath) && file_exists($filepath)) {
			return $filepath;
		}
		$_this = Cdnmd5::getInstance();
		$filepath = $_this->cleanWWW($filepath);
		$default = WWW_ROOT . trim($filepath , DS);
		$paths = array(
			$default,
			APP . trim($filepath , DS),
			APP . 'Config' . DS . trim($filepath , DS),
			APP . 'tmp' . DS . trim($filepath , DS),
		);
		foreach ($paths as $path) {
			$init = $path;
			$path = $_this->resolvePath($path);
			if (is_file($path) && file_exists($path)) {
				unset($paths, $filepath);
				return $path;
			}
		}
		return $default;
	}

	/**
	 * Translate a relative/recursive URL path like 'css/../img' into 'img'
	 *
	 * @param string $path
	 * @return string $path
	 */
	public static function resolvePath($path) {
		$path = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path);
		$parts = array_filter(explode(DIRECTORY_SEPARATOR, $path), 'strlen');
		$absolutes = array();
		foreach ($parts as $part) {
			if ('.'  == $part) {
				continue;
			} elseif ('..' == $part) {
				array_pop($absolutes);
			} else {
				$absolutes[] = $part;
			}
		}
		$path = implode(DIRECTORY_SEPARATOR, $absolutes);
		if (empty($path)) {
			debug(compact('path', 'init', 'absolutes'));
			throw OutOfBoundsException('Cdnmd5::resolvePath() returned empty');
		}
		return DIRECTORY_SEPARATOR . $path;
	}

	/**
	 * Sometimes we want to know the full details of the filepath but treat is
	 * as a single filename (eg: when writing out a md5 hash version/config file)
	 *
	 * @param string $filepath (FULL path or webroot relative path)
	 * @return string $filename (cleaned)
	 */
	public function cleanPathToFilename($filepath) {
		$filepath = $this->cleanWWW($filepath);
		$filepath = $this->cleanWWWtoFullTranslation($filepath);
		$filepath = str_replace(array('/', '\\'), '_', trim($filepath, '/\\'));
		return $this->cleanFilename($filepath);
	}

	/**
	 * sometimes (due to symlinks/plugins/etc) a WWW path might not be
	 * a full path...  as such, put the WWW path part on the left and
	 * the full path translation on thr right
	 */
	public static function cleanWWWtoFullTranslation($filepath) {
		$_this = Cdnmd5::getInstance();
		if (empty($_this->config['Config']['pathTranslations'])) {
			return $filepath;
		}
		$translations = $_this->config['Config']['pathTranslations'];
		return str_replace(array_keys($translations), array_values($translations), $filepath);
	}


	/**
	 * We don't funk funky characters...
	 * also we don't want paths, only basenames
	 *
	 * @param string $filepath or $filename
	 * @return string $filename (cleaned)
	 */
	public static function cleanFilename($filepath) {
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
			throw new OutOfBoundsException('Cdnmd5::cleanFilename - empty file extension');
		}
		if (empty($filenamebase)) {
			throw new OutOfBoundsException('Cdnmd5::cleanFilename - empty file filenamebase');
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
			$filepath = str_replace(array(
				APP . 'tmp' . DS,
				APP . 'Plugin' . DS,
				APP . 'plugins' . DS,
				APP . 'config' . DS,
				APP . 'Config' . DS,
				APP . 'Controller' . DS,
				APP . 'controllers' . DS,
				APP . 'Model' . DS,
				APP . 'models' . DS,
				APP . 'View' . DS,
				APP . 'views' . DS,
			), '/', $filepath);
			// finally, replace the APP part of the path...
			// just in case it's still there
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
	public static function splitFilename($filename) {
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
		$filepath = $_this->cleanWWW($filepath);
		return $_this->config['Config']['path'] . $_this->cleanPathToFilename($filepath) . '.md5';
	}

	/**
	 * Calculate the md5 sum for a file & store it as a config file
	 *
	 * @param string $filepath (full PHP path to a file)
	 * @return string $md5hash
	 */
	public static function makeHash($filepath) {
		$filepath = Cdnmd5::getFullPath($filepath);
		if (!is_file($filepath)) {
			//debug(compact('init', 'filepath'));
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
		$filepath = Cdnmd5::getFullPath($filepath);
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
		$filepath = $_this->cleanPath($filepath);
		$filepath = trim($filepath, '/');
		extract($_this->splitFilename($filepath));
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
		if ($protocol === false) {
			return $cdnfilename;
		}
		if (empty($protocol) || empty($_this->config['CDN'][$protocol])) {
			$protocol = 'http';
			if ($_this->config['Presentation']['alwaysUseHttps'] || $_this->isHttps()) {
				$protocol = 'https';
			}
		}
		$start = trim($_this->config['CDN'][$protocol], '/');
		$cdnfilename = trim($cdnfilename, '/');
		return "{$start}/{$cdnfilename}";
	}

	/**
	 * Convienence shortcut to
	 * 1) make a hash for a filepath
	 * 2) and transfer it -> CDN
	 * 2.1) translate nested URLs (for CSS files) before transfer
	 *
	 * @param string $filepath
	 * @param array $config (optionally pass in config to reset)
	 * @return boolean
	 */
	public static function process($filepath, $config = array()) {
		$_this = Cdnmd5::getInstance($config);
		$filepath = $_this->getFullPath($filepath);
		if (empty($filepath)) {
			return false;
		}
		$_this->makeHash($filepath);
		return $_this->transfer($filepath);
	}

	/**
	 * Transfer the file to the CDN
	 *   Abstracted to CDN provider
	 *
	 * @param string $filepath
	 * @param array $config (optionally pass in config to reset)
	 * @return boolean
	 */
	public static function transfer($filepath, $config = array()) {
		$filepath = Cdnmd5::getFullPath($filepath);
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
		// do we need to translate the file to an alternate filepath?
		$filepathInit = $filepath;
		$filepath = $_this->makeTranslation($filepath);
		if ($filepath != $filepathInit) {
			// we changed the file path, but we need to the the new hash into
			// the old one...
			$_this->makeHash($filepath);
			// get the two config files, copy the new onto the old
			$configFileInit = Cdnmd5::getConfigFile($filepathInit);
			$configFileNew = Cdnmd5::getConfigFile($filepath);
			copy($configFileNew, $configFileInit);
			// need a new cdnfilename based on the new hash
			$cdnfilename = $_this->getCdnfilename($filepathInit);
		}
		// transfer the file
		if ($_this->config['CDN']['type'] == 'RSC') {
			require_once(dirname(__file__) . '/Cdnmd5Rsc.php');
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
			require_once(dirname(__file__) . '/Cdnmd5Rsc.php');
			return Cdnmd5Rsc::purge($olderThan, $md5hashes, $_this->config);
		}
		if ($_this->config['CDN']['type'] == 'S3') {
			throw new OutOfBoundsException('Cdnmd5::purge - sorry, we have not built S3 support yet :(');
		}
		throw new OutOfBoundsException('Cdnmd5::purge - unknown CDN type');
	}

	/**
	 * CSS files can contain URLs to other files which all need to live
	 * (at the proper URL/path) on the CDN.
	 *
	 * This means that we need to:
	 * 1) extract the URLs from the CSS file content
	 * 2) upload each of them to CDNMD5 URLs of their own
	 * 3) replace the values for each of them
	 * 4) save the "translated" CSS file to an alternate path
	 * 5) transfer the "translated" CSS file, instead of the orig.
	 *
	 * This should all happen before "normal" processing.
	 */
	public static function makeTranslation($filepath) {
		$_this = Cdnmd5::getInstance();
		$filepath = $_this->getFullPath($filepath);
		extract($_this->splitFilename($filepath));
		if ($ext != 'css') {
			return $filepath;
		}
		$init_content = $content = file_get_contents($filepath);
		$urls = $_this->parseUrlsFromCSS($content);
		$basedir = dirname($_this->cleanWWW($filepath));
		foreach ($urls as $url) {
			// translate url to "WWW path"
			$new = $_this->cssFilepathNormalize($url, $basedir);
			// verify that the file exists and get it's full path
			$full = $_this->getFullPath($new);
			if (empty($full)) {
				// unable to find file to transfer (bad CSS?)
				continue;
			}
			// Cdnmd5 process each of these files
			if (!$_this->process($full)) {
				// unable to transfer file (report?)
				continue;
			}
			//$newUrl = $_this->url($full, false);
			$newUrl = $_this->url($full);
			if (empty($newUrl)) {
				// unable to find url of file...
				continue;
			}
			// replace old-->newUrl in content
			//$newUrl = '/' . $newUrl;
			if ($newUrl != $url) {
				$content = str_replace($url, $newUrl, $content);
			}
		}
		// did we actually do anything?
		if ($init_content == $content) {
			// nope, just return the initial filepath
			return $filepath;
		}
		// make a new copy of this file
		$filenamebase .= '_translated';
		$new = "{$filenamebase}.{$ext}";
		file_put_contents($new, $content);
		return $new;
	}

	/**
	 * Given a big string of CSS, extract the image URLs from it
	 *
	 * @param string $content of a css file
	 * @return array $urls of images from that CSS file
	 */
	public static function parseUrlsFromCSS($content) {
		if (!is_string($content) || empty($content)) {
			return array();
		}
		preg_match_all('~\bbackground(-image)?\s*:(.*?)\(\s*(\'|")?(?<image>.*?)\3?\s*\)~i', $content, $matches);
		if (empty($matches['image'])) {
			return array();
		}
		$urls = $matches['image'];
		// clean out all URLs which are not valid URLs (ending in image ext)
		$exts = array('gif', 'png', 'jpg', 'jpeg');
		foreach (array_keys($urls) as $i) {
			// prep "bad" URLs
			$url = trim(trim(trim($urls[$i]), '"\''));
			$url = str_replace('#', '?', $url);
			if (strpos($url, '?')!==false) {
				// remove the "?" and everything after
				$parts = explode('?', $url);
				$url = array_shift($parts);
			}
			// get the ext
			$parts = explode('.', $url);
			$ext = array_pop($parts);
			if (count($parts) < 1 || !in_array($ext, $exts)) {
				unset($urls[$i]);
			}
		}
		// return just the urls, simplified
		return array_values(array_unique($urls));
	}

	/**
	 * A URL from a CSS file can be absolute (webroot)
	 * or it can be relative to the CSS file (basedir)
	 *
	 */
	public static function cssFilepathNormalize($url, $basedir) {
		if (substr($url, 0, 1) == '/') {
			// absolute... should be fine... continue
			return $url;
		}
		// relative url, relative to this CSS file (in $basedir)
		return $basedir . '/' . $url;
	}
}

