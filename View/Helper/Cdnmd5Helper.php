<?php
/**
 * This is a basic helper, if you want to use it
 *
 * NOTE: if you are only using cdnmd5 for a file or two on the layout
 * it may be simpler to not use this helper and instead simply call
 *
 * Cdnmd5::url($webrootRelativePathTofile);
 *
 *
 */
App::Import('Lib', 'Cdnmd5.Cdnmd5');
Class Cdnmd5Helper extends AppHelper {

	public $helpers = array('Html');

	public function url($webrootRelativePathTofile) {
		return Cdnmd5::url($webrootRelativePathTofile);
	}

	public function script($webrootRelativePathTofile) {
		return $this->Html->script(Cdnmd5::url($webrootRelativePathTofile));
	}

	public function css($webrootRelativePathTofile) {
		return $this->Html->css(Cdnmd5::url($webrootRelativePathTofile));
	}
}
