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
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockScope;
use Sabre\DAV\Locks\LockInfo;

/**
 * Class FileLock
 *
 * @package OCA\FilesLock\Service
 */
class FileLock implements ILock, IQueryRow, JsonSerializable {


	use TArrayTools;

	public const ETA_INFINITE = -1;

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

	/** @var int */
	private $lockType = ILock::TYPE_USER;


	/**
	 * FileLock constructor.
	 *
	 * @param int $timeout
	 */
	public function __construct(int $timeout = 1800) {
		$this->timeout = $timeout;
		$this->creation = \OC::$server->get(ITimeFactory::class)->getTime();
	}

	public static function fromLockScope(LockScope $lockScope, int $timeout): FileLock {
		$lock = new FileLock($timeout);
		$lock->setUserId($lockScope->getOwner());
		$lock->setLockType($lockScope->getType());
		$lock->setFileId($lockScope->getNode()->getId());
		return $lock;
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
	public function getOwner(): string {
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
		if ($this->getTimeout() <= 0) {
			return self::ETA_INFINITE;
		}
		$end = $this->getCreatedAt() + $this->getTimeout();
		$eta = $end - \OC::$server->get(ITimeFactory::class)->getTime();
		return ($eta < 1) ? 0 : $eta;
	}

	/**
	 * @return int
	 */
	public function getCreatedAt(): int {
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

	public function getLockType(): int {
		return $this->lockType;
	}

	public function setLockType(int $lockType): self {
		$this->lockType = $lockType;
		return $this;
	}


	/**
	 * @return LockInfo
	 */
	public function toLockInfo(): LockInfo {
		$lock = new LockInfo();
		$lock->owner = $this->getOwner();
		$lock->token = $this->getToken();
		$lock->timeout = $this->getTimeout();
		$lock->created = $this->getCreatedAt();
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
		$this->setLockType($this->getInt('type', $data));
		$this->setTimeout($this->getInt('ttl', $data));

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
		$this->setLockType($this->getInt('type', $data));
		$this->setTimeout($this->getInt('ttl', $data));
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return [
			'id'       => $this->getId(),
			'uri'      => $this->getUri(),
			'userId'   => $this->getOwner(),
			'fileId'   => $this->getFileId(),
			'token'    => $this->getToken(),
			'eta'      => $this->getETA(),
			'creation' => $this->getCreatedAt(),
			'type'     => $this->getLockType(),
		];
	}

	public function __toString(): string {
		return $this->getToken();
	}
}

