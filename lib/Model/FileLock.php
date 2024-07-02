<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Model;

use JsonSerializable;
use OCA\FilesLock\Tools\Db\IQueryRow;
use OCA\FilesLock\Tools\Traits\TArrayTools;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
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

	private ?string $displayName = null;

	private string $owner = '';
	private $scope = ILock::LOCK_EXCLUSIVE;

	/**
	 * FileLock constructor.
	 *
	 * @param int $timeout
	 */
	public function __construct(int $timeout = 1800) {
		$this->timeout = $timeout;
		$this->creation = \OC::$server->get(ITimeFactory::class)->getTime();
	}

	public static function fromLockScope(LockContext $lockScope, int $timeout): FileLock {
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

	public function getDepth(): int {
		return ILock::LOCK_DEPTH_ZERO;
	}

	public function getScope(): int {
		return $this->scope;
	}

	public function setScope(int $scope): self {
		$this->scope = $scope;

		return $this;
	}

	public function getType(): int {
		return $this->lockType;
	}

	public function setLockType(int $lockType): self {
		$this->lockType = $lockType;
		return $this;
	}

	public function setDisplayName(?string $displayName): self {
		$this->displayName = $displayName;
		return $this;
	}

	public function getDisplayName(): ?string {
		return $this->displayName;
	}

	/**
	 * @return LockInfo
	 */
	public function toLockInfo(): LockInfo {
		$lock = new LockInfo();
		$lock->owner = $this->getDisplayName();
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
		$this->setDisplayName($this->get('owner', $data));

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
		$this->setDisplayName($this->get('owner', $data));
	}


	/**
	 * @return array
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'uri' => $this->getUri(),
			'userId' => $this->getOwner(),
			'displayName' => $this->getDisplayName(),
			'fileId' => $this->getFileId(),
			'token' => $this->getToken(),
			'eta' => $this->getETA(),
			'creation' => $this->getCreatedAt(),
			'type' => $this->getType(),
		];
	}

	public function __toString(): string {
		return $this->getToken();
	}
}
