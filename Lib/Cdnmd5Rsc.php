<?php
/**
 * Rackspace Cloud Files Adaptor for Cdnmd5
 *
 * see Cdnmd5 for all documentation
 *
 * git submodule add git://github.com/rackspace/php-opencloud.git app/vendors/php-opencloud
 */
Class Cdnmd5Rsc {

	/**
	 * Config passed in from Cdnmd5
	 */
	public $config = array(
		'vendor' => /* __DIR__ */ '../vendors/php-opencloud/lib/php-opencloud.php',
		'CDN' => array(
			'type' => 'Rscdns',
			// the CDN container
			'container' => 'cdnmd5',
			// the domain should be the domain to the CDN
			//   this could be a cname alias or the CDN provided domain
			//   NOTE: https URLs and http URLs may differ
			'http' => 'http://xxxxxxxx.rackcdn.com',
			//   NOTE: https URLs may not work with a cname (certs)
			'https' => 'https://yyyyyyyy.rackcdn.com',
			// auth information for API
			'auth' => array(
				'username' => 'aaaaaaa',
				'key' => '*******',
				'account' => '*****',
			),
			'region' => 'ORD',
		),
	);

	/**
	 * Log of actions taken
	 */
	public $log = array();

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
			$instance[0] = new Cdnmd5Rsc();
		}
		if (empty($config)) {
			// nothing
		} elseif (is_string($config)) {
			// string config is treated like a container name
			$instance[0]->config['CDN']['container'] = $config;
		} elseif (is_array($config)) {
			// merge in config array if passed
			$instance[0]->config = Set::merge( $instance[0]->config, $config );
		}
		return $instance[0];
	}

	/**
	 * setup and do the file transfer, or skip if the file already exists
	 * (unless forced)
	 *
	 * @param string $sourcefile full path to file
	 * @param string $target_filename basename
	 * @param array $config or string $container
	 * @param boolean $force false
	 * @return boolean
	 */
	public static function transfer($sourcefile, $target_filename, $config, $force = false) {
		$_this = Cdnmd5Rsc::getInstance($config);
		$container = $_this->container();
		if ($_this->fileExists($container, $target_filename) && empty($force)) {
			// file already exists...
			return true;
		}
		// file doesn't exist... send it
		return $_this->sendFile($container, $sourcefile, $target_filename);
	}

	/**
	 * init the API, get and return the container
	 *
	 * @param string $containerName (if empty, get from config, default)
	 * @return object $container
	 */
	private function container($containerName = null) {
		// initialize defines for the vendor lib
		if (!defined('RAXSDK_TIMEOUT')) {
			define('RAXSDK_TIMEOUT', 600);
		}
		$_this = Cdnmd5Rsc::getInstance();
		// load the vendor lib
		$vendor = $this->config['vendor'];
		if (!class_exists('ClassLoader')) {
			require_once(__DIR__ . DS . $vendor);
		}
		// initialize API
		$auth = array_merge(array(
			'authurl' => RACKSPACE_US,
			'username' => 'unknown',
			'key' => 'unknown',
		), $_this->config['CDN']['auth']);
		$config =  $_this->config['CDN'];
		// establish our credentials
		$connection = new \OpenCloud\Rackspace($auth['authurl'], array( 'username' => $auth['username'], 'apiKey' => $auth['key'] ));
		// now, connect to the ObjectStore service
		$objstore = $connection->ObjectStore('cloudFiles', $config['region']);
		// now get the container
		if (empty($containerName)) {
			// container name now based on the config (normal)
			$containerName = $config['container'];
		}
		try {
			// try to assume the container exists, and simply load it
			//   this is faster and usually the case
			$container = $objstore->Container($containerName);
		} catch (Exception $e) {
			// didn't work?  list all containers and make/load as needed
			//   slower but should always work
			$container = $_this->_getOrMakeContainer($objstore, $containerName);
		}
		// return the container
		return $container;
	}

	/**
	 * Get or Make the container
	 *
	 * @param object $objstore
	 * @param array $config
	 * @return object $container
	 */
	private function _getOrMakeContainer($objstore, $containerName) {
		// list all containers
		$cdncontainers = array();
		$cdnlist = $objstore->CDN()->ContainerList();
		while($cdncontainer = $cdnlist->Next()) {
			$cdncontainers[] = $cdncontainer->name;
		}
		if (in_array($containerName, $cdncontainers)) {
			// existing container
			$container = $objstore->Container($containerName);
		} else {
			// create a new container
			$container = $objstore->Container();
			$container->Create(array('name' => $containerName));
			$cdnversion = $container->PublishToCDN();
		}
		return $container;
	}

	/**
	 * a super-quick function to determine if a filename exists in a container
	 *
	 * @param object $container
	 * @param string $filename or $prefix
	 * @return boolean
	 */
	private function fileExists($container, $filename) {
		$files = Cdnmd5Rsc::findFiles($container, $filename);
		return (in_array($filename, $files));
	}

	/**
	 * a quick and simple search/find for a filename or prefix
	 *
	 * @param object $container
	 * @param string $filename or $prefix
	 * @return array $files (details, each node should have name, size, type)
	 */
	private function findFiles($container, $filename) {
		$list = $container->ObjectList(array('prefix' => $filename));
		$files = array();
		while($o = $list->Next()) {
			$files[] = $o->name;
		}
		return $files;
	}

	/**
	 * a quick and simple search/find for a filename or prefix
	 *
	 * @param object $container
	 * @param string $filename or $prefix
	 * @return array $files (details, each node should have name, size, type)
	 */
	private function findFilesWithDetails($container, $filename) {
		$list = $container->ObjectList(array('prefix' => $filename));
		$files = array();
		while($o = $list->Next()) {
			$files[] = array(
				'name' => $o->name,
				'size' => $o->bytes,
				'type' => $o->content_type,
			);
		}
		return $files;
	}

	/**
	 * Actually sends the file via the API to RSC
	 *
	 * @param object $container
	 * @param string $sourcefile full path to file
	 * @param string $target_filename basename
	 * @return boolean
	 */
	private function sendFile($container, $sourcefile, $target_filename) {
		$o = $container->DataObject();
		/*if (strpos($sourcefile, 'css')!==false) {
			$content = file_get_contents($sourcefile);
			debug(compact('sourcefile', 'content', 'target_filename'));
		}*/
		$params = array(
			'name' => $target_filename,
		);
		$parts = explode('.', $sourcefile);
		$ext = array_pop($parts);
		if ($ext == 'css') {
			$params['content_type'] = 'text/css';
		} elseif ($ext == 'js') {
			$params['content_type'] = 'text/javascript';
		}
		// CORS header (WIP, configurable?)
		$params['extra_headers']['Access-Control-Allow-Origin'] = '*';
		// create & upload, send the file
		$o->Create($params, $sourcefile);
		return true;
	}

	/**
	 * get the details for a file inside a container
	 * (doens't verify file exists first)
	 *
	 * @param object $container
	 * @param string $filename
	 * @return array $details
	 */
	private function getFileDetails($container, $filename) {
		$o = $container->DataObject($filename);
		return array(
			'name' => $o->name,
			'size' => $o->bytes,
			'type' => empty($o->type) ? null:  $o->type,
			'cdnurl' =>  $o->CDNUrl(),
			'publicurl' =>  $o->PublicURL(),
			'hash' => $o->hash,
			'last_modified' => $o->last_modified,
			'content_type' => $o->content_type,
		);
	}

	/**
	 * We create many, many versions of files with this class/approach
	 * This function walks through all files on the CDN
	 * and if the md5hash doesn't exist right now in the app
	 * and if the file is older than $olderThan (strtotime)
	 * then we delete it from the CDN
	 *
	 * @param string $olderThan (strtotime)
	 * @param array $excludeHashes list of current md5hashes, will not delete these
	 * @param array $config
	 * @return int $numberOfDeletedFiles
	 */
	public static function purge($olderThan = '-3 months', $excludeHashes = array(), $config = array()) {
		$_this = Cdnmd5Rsc::getInstance($config);
		$container = $_this->container();
		$list = $container->ObjectList();
		$files = array();
		$olderThanEpoch = strtotime($olderThan);
		while($o = $list->Next()) {
			if (in_array($o->hash, $excludeHashes)) {
				$_this->log[] = "purge skipped: hash " . $o->hash;
				continue;
			}
			if (strtotime($o->last_modified) > $olderThanEpoch) {
				$_this->log[] = "purge skipped: age " . date('c', strtotime($o->last_modified)) . " > " . date('c', $olderThanEpoch) . " for " . $o->name;
				continue;
			}
			if ($o->Delete()) {
				$files[] = $o->name;
				$_this->log[] = "purged file " . $o->name;
			}
		}
		return count($files);
	}

    /**
     * Method used to delete a file from CDN based on filepath
     *
     * @param null $filepath
     * @param array $config
     * @return bool
     */
    public static function delete($filepath = null, $config = array()) {

        $_this = Cdnmd5Rsc::getInstance($config);
        $container = $_this->container();
        $fileObject = self::getFileObject($container, $filepath);

        if (empty($fileObject)) {
            return false;
        }

        if ($fileObject->Delete()) {
            return true;
        }

        return false;
    }


    /**
     * Method used to return details on for a file using a public CDN filepath
     *
     * @param null $filepath
     * @param array $config
     * @return false|mixed
     */
    public static function details($filepath = null, $config = array()) {

        $_this = Cdnmd5Rsc::getInstance($config);
        $container = $_this->container();
        $fileObject = self::getFileObject($container, $filepath);

        if (empty($fileObject)) {
            return false;
        }

        return $fileObject;
    }

    /**
     * Method used to retrieve file data from CDN as object based on filename
     *
     * @param $container
     * @param $filename
     * @return mixed|null
     */
    private function getFileObject($container, $filename) {

        try {

            return $container->DataObject($filename);

        } catch (\Exception $e) {

            return null;

        }

    }

}

