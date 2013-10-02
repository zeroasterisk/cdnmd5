<?php
/**
 * Configuration File for Cdnmd5
 * EXAMPLE
 *
 * cp app/plugins/cdnmd5/config/cdnmd5.example.php app/config/cdnmd5.php
 *
 * Edit to match your CDN and needs
 */

$config = array(
	'Cdnmd5' => array(
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
			'path' => /* APP */ 'Config/cdnmd5/',
		),
		// configuration for the CDN
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
	),
);


