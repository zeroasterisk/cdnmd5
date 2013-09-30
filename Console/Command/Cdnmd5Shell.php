<?php
/**
 * Helpful shell to CDNMD5 any file
 *
 */
class Cdnmd5Shell extends Shell{

	/**
	 * Load these models
	 */
	public $uses = array();

	/**
	 * Run all parts and peices
	 */
	public function main() {
		foreach ($this->args as $file) {
			$file = $this->getFile($file);
			if (!is_file($file)) {
				$this->help();
				$this->exit();
				return false;
			}
		}
		$this->process();
	}

	/**
	 * Help
	 */
	public function help() {
		$this->out("CDNMD5 Help");
		$this->out("A helpful function to easily process (hash/transfer) any filepaths");
		$this->out();
		$this->out("  ./cake -plugin cdnmd5 cdnmd5 process filepath [filepath...]");
		$this->out("    pass in at least one valid filepath to this shell");
		$this->out("    it makes a hash config file for it, and then transfers to the CDN");
		$this->out("    making it ready to be used with the helper/url() method");
		$this->out();
	}

	/**
	 * Process files
	 */
	public function process() {
		App::Import('Lib', 'Cdnmd5.Cdnmd5');
		foreach ($this->args as $file) {
			$file = $this->getFile($file);
			if (!is_file($file)) {
				$this->out("Inavlid filepath (can not find the file)");
				$this->out("  {$file}");
				$this->out();
				$this->help();
				$this->exit();
				return false;
			}
			if (Cdnmd5::process($file)) {
				$this->out("Processed: " . basename($file));
				$this->out("  Cdnmd5::url('" . Cdnmd5::cleanWWW($file) . "');");
				$this->out("    " . Cdnmd5::url(Cdnmd5::cleanWWW($file)));
				$this->out();
			} else {
				$this->out("Unable to process: " . basename($file));
				$this->out();
			}
		}
	}

	/**
	 * Validate a file path, if it doesn't exist, try to find it on some
	 * "known" paths
	 *
	 * @param string $file
	 * @return string $file
	 */
	private function getFile($file) {
		if (file_exists($file)) {
			return $file;
		}
		$file = trim($file);
		if (file_exists($file)) {
			return $file;
		}
		$paths = array( WWW_ROOT, APP, __DIR__, dirname(__DIR__), dirname(dirname(__DIR__)), dirname(dirname(dirname(__DIR__))) );
		foreach ($paths as $path) {
			if (file_exists($path . $file)) {
				return $path . $file;
			}
			if (file_exists($path . DS . $file)) {
				return $path . DS . $file;
			}
		}
		// failure, should error later on
		return $file;
	}
}
