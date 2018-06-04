<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2013 Dmitry Dulepov <dmitry.dulepov@gmail.com>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

namespace DmitryDulepov\DdGooglesitemap\Scheduler;

use DmitryDulepov\DdGooglesitemap\Helper\ConfigurationHelper;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * This class provides a scheduler task to create sitemap index as required
 * by the Google sitemap protocol.
 *
 * @author Dmitry Dulepov <dmitry.dulepov@gmail.com>
 * @see http://support.google.com/webmasters/bin/answer.py?hl=en&answer=71453
 */
class Task extends \TYPO3\CMS\Scheduler\Task\AbstractTask {

	const DEFAULT_FILE_PATH = 'typo3temp/dd_googlesitemap';

	/** @var string */
	private $baseUrl;

	/** @var string */
	protected $eIdScriptUrl;

	/** @var string */
	protected $indexFilePath;

	/** @var int */
	protected $maxUrlsPerSitemap = 50000;

	/** @var bool */
	protected $renderAllLanguages = false;

	/** @var string */
	private $sitemapFileFormat;

	/** @var array|bool */
	private $authHeader;

	/** @var int */
	private $offset;

	/**
	 * Creates the instance of the class. This call initializes the index file
	 * path to the random value. After the task is configured, the user may
	 * change the file and the file name will be serialized with the task and
	 * used later.
	 *
	 * @see __sleep
	 */
	public function __construct() {
		parent::__construct();
		$this->indexFilePath = self::DEFAULT_FILE_PATH . '/' . GeneralUtility::getRandomHexString(24) . '.xml';
	}

	/**
	 * Reconstructs some variables after the object is unserialized.
	 *
	 * @return void
	 */
	public function __wakeup() {
		$this->buildSitemapFileFormat();
		$this->getAuthHeader();
		$this->buildBaseUrl();
	}

	/**
	 * This is the main method that is called when a task is executed
	 * It MUST be implemented by all classes inheriting from this one
	 * Note that there is no error handling, errors and failures are expected
	 * to be handled and logged by the client implementations.
	 * Should return true on successful execution, false on error.
	 *
	 * @return boolean    Returns true on successful execution, false on error
	 * @throws \InvalidArgumentException
	 */
	public function execute() {
		$indexFilePathTemp = PATH_site . $this->indexFilePath . '.tmp';
		$indexFile = fopen($indexFilePathTemp, 'wt');
		fwrite($indexFile, '<?xml version="1.0" encoding="UTF-8"?>' . chr(10));
		fwrite($indexFile, '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . chr(10));

		$eIDscripts = $this->expandEidUriWithLanguages($this->getEIdScriptUrls());
		foreach ($eIDscripts as $eIdIndex => $eIdScriptUrl) {
			$this->offset = 0;
			$currentFileNumber = 1;
			$lastFileHash = '';
			do {
				$index = MathUtility::canBeInterpretedAsInteger($eIdIndex) ? sprintf('%05d', $eIdIndex +  1) : $eIdIndex;
				$sitemapFileName = sprintf($this->sitemapFileFormat, $index, $currentFileNumber++);
				$this->buildSitemap($eIdScriptUrl, $sitemapFileName);

				$isSitemapEmpty = $this->isSitemapEmpty($sitemapFileName);
				$currentFileHash = $isSitemapEmpty ? -1 : md5_file(PATH_site . $sitemapFileName);
				$stopLoop = $isSitemapEmpty || ($currentFileHash == $lastFileHash);

				if ($stopLoop) {
					@unlink(PATH_site . $sitemapFileName);
				}
				else {
					fwrite($indexFile, '<sitemap><loc>' . htmlspecialchars($this->makeSitemapUrl($sitemapFileName)) . '</loc></sitemap>' . chr(10));
					$lastFileHash = $currentFileHash;
				}
			} while (!$stopLoop);
		}

		fwrite($indexFile, '</sitemapindex>' . chr(10));
		fclose($indexFile);

		@unlink(PATH_site . $this->indexFilePath);
		rename($indexFilePathTemp, PATH_site . $this->indexFilePath);

		return true;
	}

	/**
	 * This method is designed to return some additional information about the task,
	 * that may help to set it apart from other tasks from the same class
	 * This additional information is used - for example - in the Scheduler's BE module
	 * This method should be implemented in most task classes
	 *
	 * @return	string	Information to display
	 */
	public function getAdditionalInformation() {
		/** @noinspection PhpUndefinedMethodInspection */
		$format = $GLOBALS['LANG']->sL('LLL:EXT:dd_googlesitemap/locallang.xml:scheduler.extra_info');
		return sprintf($format, $this->getIndexFileUrl());
	}

	/**
	 * Sets the url of the eID script. This is called from the task
	 * configuration inside scheduler.
	 *
	 * @return string
	 * @see tx_ddgooglesitemap_additionalfieldsprovider
	 */
	public function getEIdScriptUrl() {
		return $this->eIdScriptUrl;
	}

	/**
	 * @return array
	 */
	private function getEIdScriptUrls() {
		$eIDscripts = GeneralUtility::trimExplode(chr(10), $this->eIdScriptUrl);
		if (!empty($eIDscripts)) {
			$scripts = array();
			foreach ($eIDscripts as $key => $script) {
				if (strpos($script, '=>') > 0) {
					list($key, $script) = GeneralUtility::trimExplode('=>', $script);
				}

				$scripts[$key] = $script;
			}

			$eIDscripts = $scripts;
		}

		return $eIDscripts;
	}

	/**
	 * Returns the index file path. This is called from the task
	 * configuration inside scheduler.
	 *
	 * @return string
	 * @see tx_ddgooglesitemap_additionalfieldsprovider
	 */
	public function getIndexFilePath() {
		return $this->indexFilePath;
	}

	/**
	 * Obtains the number of urls per sitemap. This is called from the task
	 * configuration inside scheduler.
	 *
	 * @return int
	 * @see tx_ddgooglesitemap_additionalfieldsprovider
	 */
	public function getMaxUrlsPerSitemap() {
		return $this->maxUrlsPerSitemap;
	}

	/**
	 * Sets the URl of the eID script. This is called from the task
	 * configuration inside scheduler.
	 *
	 * @param $url
	 * @see tx_ddgooglesitemap_additionalfieldsprovider
	 */
	public function setEIdScriptUrl($url) {
		$this->eIdScriptUrl = $url;
	}

	/**
	 * Sets the URL of the eID script. This is called from the task
	 * configuration inside scheduler.
	 *
	 * @param string $path
	 * @see tx_ddgooglesitemap_additionalfieldsprovider
	 */
	public function setIndexFilePath($path) {
		$this->indexFilePath = $path;
	}

	/**
	 * Sets the number of URLs per sitemap. This is called from the task
	 * configuration inside scheduler.
	 *
	 * @param int $maxUrlsPerSitemap
	 * @see tx_ddgooglesitemap_additionalfieldsprovider
	 */
	public function setMaxUrlsPerSitemap($maxUrlsPerSitemap) {
		$this->maxUrlsPerSitemap = $maxUrlsPerSitemap;
	}

	/**
	 * Should rendering all languages
	 *
	 * @return bool
	 */
	public function isRenderAllLanguages() {
		return $this->renderAllLanguages;
	}

	/**
	 * Enabling rendering all languages
	 *
	 * @param bool $renderAllLanguages
	 */
	public function setRenderAllLanguages($renderAllLanguages) {
		$this->renderAllLanguages = (bool)$renderAllLanguages;
	}

	/**
	 * Removes language parameters from url and unique url's
	 *
	 * @param array $eIdUris
	 * @return array
	 */
	protected function stripLanguage(array $eIdUris) {
		if ($this->isRenderAllLanguages() && !empty($eIdUris)) {
			foreach ($eIdUris as $key => $eIdScript) {
				if (strpos($eIdScript, '?') === false) {
					continue;
				}

				list($path, $query) = explode('?', $eIdScript, 2);
				$query = array_filter(
					explode('&', $query),
					function ($paramSegment) {
						return strpos($paramSegment, 'L=') !== 0;
					}
				);

				// sort parameters
				sort($query);

				$eIdUris[$key] = $path . '?' . implode('&', $query);
			}

			$eIdUris = array_unique($eIdUris);
		}

		return $eIdUris;
	}

	/**
	 * @param array $eIdUris
	 * @return array
	 * @throws \InvalidArgumentException
	 */
	protected function expandEidUriWithLanguages(array $eIdUris) {
		if ($this->isRenderAllLanguages() && !empty($eIdUris)) {
			$eIdUris = $this->stripLanguage($eIdUris);

			/** @var \DmitryDulepov\DdGooglesitemap\Helper\SysLanguageHelper $instance */
			$instance     = GeneralUtility::makeInstance('DmitryDulepov\\DdGooglesitemap\\Helper\\SysLanguageHelper');
			$languageUids = array_keys($instance->getSysLanguages());

			$uris = array();
			if (!empty($languageUids)) {
				foreach ($languageUids as $language) {
					foreach ($eIdUris as $eScriptName => $eIdScript) {
						// remove possible anchor, which make no sense
						$anchorFree = explode('#', $eIdScript, 2);
						$uri        = rtrim($anchorFree[0], '&') . '&L=' . $language;

						if(MathUtility::canBeInterpretedAsInteger($eScriptName)) {
							$uris[] = $uri;
						} else {
							$uris[$eScriptName . '-' . $language] = $uri;
						}
					}
				}

				$eIdUris = $uris;
			}
		}

		return $eIdUris;
	}

	/**
	 * Creates a base url for sitemaps.
	 *
	 * @return void
	 */
	protected function buildBaseUrl() {
		$eIdScriptUrl = $this->getEIdScriptUrls();
		$urlParts = parse_url(reset($eIdScriptUrl));
		$this->baseUrl = $urlParts['scheme'] . '://';
		if ($urlParts['user']) {
			$this->baseUrl .= $urlParts['user'];
			if ($urlParts['pass']) {
				$this->baseUrl .= ':' . $urlParts['pass'];
			}
			$this->baseUrl .= '@';
		}
		$this->baseUrl .= $urlParts['host'];
		if ($urlParts['port']) {
			$this->baseUrl .= ':' . $urlParts['port'];
		}
		$this->baseUrl .= '/';
	}

	/**
	 * Builds the sitemap.
	 *
	 * @param string $eIdScriptUrl
	 * @param string $sitemapFileName
	 * @see tx_ddgooglesitemap_additionalfieldsprovider
	 */
	protected function buildSitemap($eIdScriptUrl, $sitemapFileName) {
		$url = $eIdScriptUrl . sprintf('&offset=%d&limit=%d', $this->offset, $this->maxUrlsPerSitemap);

		$report = array();
		$content = GeneralUtility::getUrl($url, 0, $this->getAuthHeader(), $report);

		if ($content) {
			file_put_contents(PATH_site . $sitemapFileName, $content);
			$this->offset += $this->maxUrlsPerSitemap;
		} else {
			$message = sprintf('Failed to request sitemap URL "%s": %s', $url, $report['message']);

			/** @var $logger LoggerInterface */
			$logger = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Log\\LogManager')->getLogger(__CLASS__);
			$logger->error($message, $report);

			/** @var $flashMessage \TYPO3\CMS\Core\Messaging\FlashMessage */
			$flashMessage = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Messaging\\FlashMessage', $message, '', FlashMessage::ERROR);
			/** @var $flashMessageService \TYPO3\CMS\Core\Messaging\FlashMessageService */
			$flashMessageService = GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Messaging\\FlashMessageService');
			/** @var $defaultFlashMessageQueue \TYPO3\CMS\Core\Messaging\FlashMessageQueue */
			$defaultFlashMessageQueue = $flashMessageService->getMessageQueueByIdentifier();
			$defaultFlashMessageQueue->enqueue($flashMessage);
		}
	}

	/**
	 * @return array|bool
	 * @throws \InvalidArgumentException
	 */
	protected function getAuthHeader() {
		if($this->authHeader === null) {
			/** @var ConfigurationHelper $configurationHelper */
			$configurationHelper = GeneralUtility::makeInstance('DmitryDulepov\\DdGooglesitemap\\Helper\\ConfigurationHelper');

			if($configurationHelper->isOnlySchedulerMode()) {
				$this->authHeader = array(
					'Authorization: Bearer ' . sha1($configurationHelper->getSchedulerModeToken())
				);
			}
			else {
				$this->authHeader = false;
			}
		}

		return $this->authHeader;
	}

	/**
	 * Creates the format string for the sitemap files.
	 *
	 * @return void
	 */
	protected function buildSitemapFileFormat() {
		$fileParts = pathinfo($this->indexFilePath);
		$directoryPrefix = ($fileParts['dirname'] === '.'? '' : $fileParts['dirname'] . '/');
		$this->sitemapFileFormat = $directoryPrefix . $fileParts['filename'] . '_sitemap_%s_%05d.xml';
	}

	/**
	 * Returns the index file url.
	 *
	 * @return string
	 */
	protected function getIndexFileUrl() {
		return $this->baseUrl . $this->indexFilePath;
	}

	/**
	 * Checks if the current sitemap has no entries. The function reads a chunk
	 * of the file, which is large enough to have a '<url>' token in it and
	 * examines the chunk. If the token is not found, than the sitemap is either
	 * empty or corrupt.
	 *
	 * @param string $sitemapFileName
	 * @return bool
	 */
	protected function isSitemapEmpty($sitemapFileName) {
		$result = TRUE;

		$fileDescriptor = @fopen(PATH_site . $sitemapFileName, 'rt');
		if ($fileDescriptor) {
			$chunkSizeToCheck = 10240;
			$testString = fread($fileDescriptor, $chunkSizeToCheck);
			fclose($fileDescriptor);
			$result = (strpos($testString, '<url>') === FALSE);
		}

		return $result;
	}

	/**
	 * Creates a url to the sitemap.
	 *
	 * @param string $siteMapPath
	 * @return string
	 */
	protected function makeSitemapUrl($siteMapPath) {
		return $this->baseUrl . $siteMapPath;
	}
}
