<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Model;

use JsonSerializable;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use OCP\Server;
use Sabre\DAV\Locks\LockInfo;

/**
 * Class FileLock
 *
 * @package OCA\FilesLock\Service
 */
class FileLock implements ILock, JsonSerializable {
	public const ETA_INFINITE = -1;

	private int $id = 0;

	private string $userId = '';

	private string $uri = '';

	private string $token = '';

	private int $fileId = 0;

	private int $creation = 0;

	private int $lockType = ILock::TYPE_USER;

	private ?string $displayName = null;
	private int $scope = ILock::LOCK_EXCLUSIVE;

	/**
	 * FileLock constructor.
	 */
	public function __construct(
		private int $timeout = 1800,
	) {
		$this->creation = Server::get(ITimeFactory::class)->getTime();
	}

	public static function fromLockScope(LockContext $lockScope, int $timeout): FileLock {
		$lock = new FileLock($timeout);
		$lock->setUserId($lockScope->getOwner());
		$lock->setLockType($lockScope->getType());
		$lock->setFileId($lockScope->getNode()->getId());
		return $lock;
	}

	public function getId(): int {
		return $this->id;
	}

	public function setId(int $id): self {
		$this->id = $id;

		return $this;
	}

	public function getUri(): string {
		return $this->uri;
	}

	public function setUri(string $uri): self {
		$this->uri = $uri;

		return $this;
	}

	#[\Override]
	public function getOwner(): string {
		return $this->userId;
	}

	public function setUserId(string $userId): self {
		$this->userId = $userId;

		return $this;
	}

	#[\Override]
	public function getFileId(): int {
		return $this->fileId;
	}

	public function setFileId(int $fileId): self {
		$this->fileId = $fileId;

		return $this;
	}

	#[\Override]
	public function getToken(): string {
		return $this->token;
	}

	public function setToken(string $token): self {
		$this->token = $token;

		return $this;
	}

	#[\Override]
	public function getTimeout(): int {
		return $this->timeout;
	}

	public function setTimeout(int $timeout): self {
		$this->timeout = $timeout;

		return $this;
	}

	public function getETA(): int {
		if ($this->getTimeout() <= 0) {
			return self::ETA_INFINITE;
		}
		$end = $this->getCreatedAt() + $this->getTimeout();
		$eta = $end - Server::get(ITimeFactory::class)->getTime();
		return ($eta < 1) ? 0 : $eta;
	}

	#[\Override]
	public function getCreatedAt(): int {
		return $this->creation;
	}

	public function setCreation(int $creation): self {
		$this->creation = $creation;

		return $this;
	}

	#[\Override]
	public function getDepth(): int {
		return ILock::LOCK_DEPTH_ZERO;
	}

	#[\Override]
	public function getScope(): int {
		return $this->scope;
	}

	public function setScope(int $scope): self {
		$this->scope = $scope;

		return $this;
	}

	#[\Override]
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

	public function importFromDatabase(array $data): self {
		$this->setId((int)$data['id']);
		$this->setUserId($data['user_id'] ?? '');
		$this->setFileId((int)$data['file_id']);
		$this->setToken($data['token'] ?? '');
		$this->setCreation((int)$data['creation']);
		$this->setLockType((int)$data['type']);
		$this->setTimeout((int)$data['ttl']);
		$this->setDisplayName($data['owner'] ?? '');

		return $this;
	}

	public function import(array $data): void {
		$this->setId((int)$data['id']);
		$this->setUri($data['uri'] ?? '');
		$this->setUserId($data['user_id']);
		$this->setFileId((int)$data['file_id']);
		$this->setToken($data['token'] ?? '');
		$this->setCreation((int)$data['creation']);
		$this->setLockType((int)$data['type']);
		$this->setTimeout((int)$data['ttl']);
		$this->setDisplayName($data['owner'] ?? '');
	}

	#[\Override]
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
