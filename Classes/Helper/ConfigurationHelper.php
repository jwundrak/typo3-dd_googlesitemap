<?php
/**
 * Copyright (c) 2008-2018 dotSource GmbH.
 * All rights reserved.
 * http://www.dotsource.de
 *
 * Contributors:
 * Julian Wundrak - initial contents
 */

namespace DmitryDulepov\DdGooglesitemap\Helper;

/**
 * Class ConfigurationHelper
 *
 * @package DmitryDulepov\DdGooglesitemap\Helper
 */
class ConfigurationHelper {

	/**
	 * @var array
	 */
	protected $configuration;

	/**
	 * ConfigurationHelper constructor.
	 */
	public function __construct() {
		$this->configuration = (isset($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['dd_googlesitemap']) ?
			unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['dd_googlesitemap']) : array()
		);
	}

	/**
	 * @return bool
	 */
	public function isOnlySchedulerMode() {
		return isset($this->configuration['onlySchedulerMode']) && (int)$this->configuration['onlySchedulerMode'] === 1;
	}

	/**
	 * @return string
	 */
	public function getSchedulerModeToken() {
		$token = isset($this->configuration['schedulerModeToken']) ? $this->configuration['schedulerModeToken'] : '';
		if(empty($token)) {
			$token = \substr($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'], -12);
		}

		return $token;
	}

	/**
	 * Check headers for bearer token
	 *
	 * @return bool
	 */
	public function isAuthenticationValid() {
		// token could be send as Bearer-Token
		$headers = null;
		if (isset($_SERVER['Authorization'])) {
			$headers = trim($_SERVER["Authorization"]);
		} elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) { //Nginx or fast CGI
			$headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
		} elseif (function_exists('apache_request_headers')) {
			$requestHeaders = apache_request_headers();
			// Server-side fix for bug in old Android versions (a nice side-effect of this fix means we don't care about capitalization for Authorization)
			$requestHeaders = array_combine(
				array_map('ucwords', array_keys($requestHeaders)),
				array_values($requestHeaders)
			);
			if (isset($requestHeaders['Authorization'])) {
				$headers = trim($requestHeaders['Authorization']);
			}
		}

		if (!empty($headers)) {
			if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
				return \trim($matches[1]) === sha1($this->getSchedulerModeToken());
			}
		}
		return false;
	}
}

