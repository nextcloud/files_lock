<?php declare(strict_types=1);


/**
 * Files_Lock - Temporary Files Lock
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2019
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
 *
 */


namespace OCA\FilesLock\Model;


use OCA\FilesLock\Tools\Db\IQueryRow;
use OCA\FilesLock\Tools\Traits\TArrayTools;
use JsonSerializable;
use Sabre\DAV\Locks\LockInfo;

/**
 * Class FileLock
 *
 * @package OCA\FilesLock\Service
 */
class FileLock implements IQueryRow, JsonSerializable {


	use TArrayTools;


	/** @var int */
	private $id = 0;

	/** @var string */
	private $userId = '';

	/** @var string */
	private $uri = '';

	/** @var string */
	private $token = '';

	/** @var int */
	private $fileId = 0;

	/** @var int */
	private $timeout = 1800;

	/** @var int */
	private $creation = 0;


	/**
	 * FileLock constructor.
	 *
	 * @param int $timeout
	 */
	public function __construct(int $timeout = 1800) {
		$this->timeout = $timeout;
		$this->creation = time();
	}


	/**
	 * @return int
	 */
	public function getId(): int {
		return $this->id;
	}

	/**
	 * @param int $id
	 *
	 * @return FileLock
	 */
	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getUri(): string {
		return $this->uri;
	}

	/**
	 * @param string $uri
	 *
	 * @return FileLock
	 */
	public function setUri(string $uri): self {
		$this->uri = $uri;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getUserId(): string {
		return $this->userId;
	}

	/**
	 * @param string $userId
	 *
	 * @return FileLock
	 */
	public function setUserId(string $userId): self {
		$this->userId = $userId;

		return $this;
	}


	/**
	 * @return int
	 */
	public function getFileId(): int {
		return $this->fileId;
	}

	/**
	 * @param int $fileId
	 *
	 * @return FileLock
	 */
	public function setFileId(int $fileId): self {
		$this->fileId = $fileId;

		return $this;
	}


	/**
	 * @return string
	 */
	public function getToken(): string {
		return $this->token;
	}

	/**
	 * @param string $token
	 *
	 * @return FileLock
	 */
	public function setToken(string $token): self {
		$this->token = $token;

		return $this;
	}


	/**
	 * @return int
	 */
	public function getTimeout(): int {
		return $this->timeout;
	}

	/**
	 * @param int $timeout
	 *
	 * @return FileLock
	 */
	public function setTimeout(int $timeout): self {
		$this->timeout = $timeout;

		return $this;
	}

	/**
	 * @return int
	 */
	public function getETA(): int {
		$end = $this->getCreation() + $this->getTimeout();
		$eta = $end - time();

		return ($eta < 1) ? 0 : $eta;
	}

	/**
	 * @return int
	 */
	public function getCreation(): int {
		return $this->creation;
	}

	/**
	 * @param int $creation
	 *
	 * @return FileLock
	 */
	public function setCreation(int $creation): self {
		$this->creation = $creation;

		return $this;
	}


	/**
	 * @return LockInfo
	 */
	public function toLockInfo(): LockInfo {
		$lock = new LockInfo();
		$lock->owner = $this->getUserId();
		$lock->token = $this->getToken();
		$lock->timeout = $this->getTimeout();
		$lock->created = $this->getCreation();
		$lock->scope = LockInfo::EXCLUSIVE;
		$lock->depth = 1;
		$lock->uri = $this->getUri();

		return $lock;
	}


	/**
	 * @param array $data
	 *
	 * @return IQueryRow
	 */
	public function importFromDatabase(array $data):IQueryRow {
		$this->setId($this->getInt('id', $data));
		$this->setUserId($this->get('user_id', $data));
		$this->setFileId($this->getInt('file_id', $data));
		$this->setToken($this->get('token', $data));
		$this->setCreation($this->getInt('creation', $data));

		return $this;
	}


	/**
	 * @param array $data
	 */
	public function import(array $data) {
		$this->setId($this->getInt('id', $data));
		$this->setUri($this->get('uri', $data));
		$this->setUserId($this->get('userId', $data));
		$this->setFileId($this->getInt('fileId', $data));
		$this->setToken($this->get('token', $data));
		$this->setCreation($this->getInt('creation', $data));
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return [
			'id'       => $this->getId(),
			'uri'      => $this->getUri(),
			'userId'   => $this->getUserId(),
			'fileId'   => $this->getFileId(),
			'token'    => $this->getToken(),
			'eta'      => $this->getETA(),
			'creation' => $this->getCreation()
		];
	}

}

