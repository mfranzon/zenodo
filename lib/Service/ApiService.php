<?php
/**
 * Zenodo - Publish your work to Zenodo.org
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@pontapreta.net>
 * @copyright 2017
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Zenodo\Service;


use \OCA\Zenodo\Model\iError;
use \OCA\Zenodo\Service\ConfigService;
use \OCA\Zenodo\Service\FileService;
use \OCA\Zenodo\Service\MiscService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

class ApiService {

	const ZENODO_DOMAIN_SANDBOX = 'https://sandbox.zenodo.org/';
	const ZENODO_DOMAIN_PRODUCTION = 'https://zenodo.org/';

	const ZENODO_API_DEPOSITIONS_LIST = 'api/deposit/depositions?';
	const ZENODO_API_DEPOSITIONS_CREATE = 'api/deposit/depositions?';
	const ZENODO_API_DEPOSITIONS_FILES_UPLOAD = 'api/deposit/depositions/%ID%/files?';

	const REQUEST_TYPE_POST = 'post';
	const REQUEST_TYPE_GET = 'get';

	private $configService;
	private $fileService;
	private $miscService;

	private $production = false;
	private $token = '';

	public function __construct(
		ConfigService $configService, FileService $fileService, MiscService $miscService
	) {
		$this->configService = $configService;
		$this->fileService = $fileService;
		$this->miscService = $miscService;
	}


	public function init($production, &$iError = null) {

		if ($iError === null) {
			$iError = new $iError();
		}

		$this->production = $production;
		$this->initToken();

		if ($this->token === '') {
			$iError->setCode(iError::TOKEN_MISSING)
				   ->setMessage(
					   'No token defined for this operation; please contact your Nextcloud administrator'
				   );

			return false;
		}

		return true;
	}

	public function configured() {
		if ($this->token === '') {
			return false;
		}

		return true;
	}

	private function initToken() {

		if ($this->production === true) {
			$this->token =
				$this->configService->getAppValue(ConfigService::ZENODO_TOKEN_PRODUCTION);
		} else {
			$this->token = $this->configService->getAppValue(ConfigService::ZENODO_TOKEN_SANDBOX);
		}
	}


	private function generateUrl($path) {

		if (!$this->configured()) {
			return false;
		}

		if ($this->production === true) {
			$url = self::ZENODO_DOMAIN_PRODUCTION;
		} else {
			$url = self::ZENODO_DOMAIN_SANDBOX;
		}

		return sprintf("%s%saccess_token=%s", $url, $path, $this->token);
	}


	/**
	 * list all depositions from Zenodo
	 *
	 * @param null $iError
	 *
	 * @return bool|mixed
	 */
	public function list_deposition(&$iError = null) {

		if ($iError === null) {
			$iError = new $iError();
		}

		if (!$this->configured()) {
			return false;
		}

		$url = $this->generateURl(self::ZENODO_API_DEPOSITIONS_LIST);
		$result = self::curlIt(
			$url, array(
					'type' => self::REQUEST_TYPE_GET
				)
		);

		return $result;
	}

	/**
	 * Create a new deposition on Zenodo
	 *
	 * @param $metadata
	 * @param null $iError
	 *
	 * @return bool|mixed
	 */
	public function create_deposition($metadata, &$iError = null) {

		if ($iError === null) {
			$iError = new $iError();
		}

		if (!$this->configured()) {
			return false;
		}

		$url = $this->generateURl(self::ZENODO_API_DEPOSITIONS_CREATE);
		$result = self::curlIt(
			$url, array(
					'type'   => self::REQUEST_TYPE_POST,
					'params' => json_encode($metadata)
				)
		);

		if (property_exists($result, 'created')) {
			return $result;
		}

		$iError->setCode($result->status);
		if (sizeof(is_array($result->errors) ) > 0) {
			foreach (is_array($result->errors) as $error) {
				$iError->setMessage($error->field . ' - ' . $error->message);
			}
		}

		return false;
	}


	// Add a file to Deposition
	public function upload_file($depositionid, $fileid, &$iError = null) {

		$files = $this->fileService->getFilesPerFileId($fileid);
		if (sizeof($files) == 0) {
			return false;
		}

		$url = $this->generateURl(
			str_replace('%ID%', $depositionid, self::ZENODO_API_DEPOSITIONS_FILES_UPLOAD)
		);

		foreach ($files as $file) {

			$filepath = FileService::getAbsolutePath($file);
			if (version_compare(PHP_VERSION, '5.5.0') >= 0) {
				$post = array(
					'file' => curl_file_create($filepath),
					'name' => basename($filepath)
				);
			} else {
				$post = array(
					'file' => '@' . $filepath,
					'name' => basename($filepath)
				);
			}

			$result = self::curlIt(
				$url, array(
						'type'         => self::REQUEST_TYPE_POST,
						'params'       => $post,
						'content-type' => 'multipart/form-data'
					)
			);

			if (property_exists($result, 'status') && $result->status === 400) {
				$iError = new iError();
				$iError->setCode($result->status)
					   ->setMessage(
						   'Problems occurs while uploading your document. Contact your administrator'
					   );

				return false;
			}
		}

		return true;
	}


	public static function curlIt($url, $data) {

		$curl = curl_init($url);
		//	curl_setopt($curl, CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt(
			$curl, CURLOPT_HTTPHEADER,
			array(
				sprintf(
					'Content-type: %s',
					(key_exists('content-type', $data)) ? $data['content-type'] : "application/json"
				)
			)
		);

		switch ($data['type']) {

			case self::REQUEST_TYPE_POST:
				curl_setopt($curl, CURLOPT_POST, true);
				if (key_exists('params', $data)) {
					curl_setopt($curl, CURLOPT_POSTFIELDS, $data['params']);
				}
				break;

			case self::REQUEST_TYPE_GET:
//				curl_setopt($curl, CURLOPT_POST, true);
//				curl_setopt($curl, CURLOPT_POSTFIELDS, $data['post']);
				break;
		}

		return json_decode(curl_exec($curl));
	}

}
