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
 * Expiry is modelled by a single absolute timestamp: null means the lock never
 * expires, any other value is the unix time at which it stops being valid.
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

	private ?int $expiresAt = null;

	private int $lockType = ILock::TYPE_USER;

	private ?string $displayName = null;
	private int $scope = ILock::LOCK_EXCLUSIVE;

	public function __construct() {
		$this->creation = Server::get(ITimeFactory::class)->getTime();
	}

	/**
	 * The lock never expires until a caller sets an expiry on it.
	 */
	public static function fromLockScope(LockContext $lockScope): FileLock {
		$lock = new FileLock();
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

	/**
	 * Lifetime of the lock in seconds counted from its creation, ETA_INFINITE when it never expires.
	 */
	#[\Override]
	public function getTimeout(): int {
		if ($this->expiresAt === null) {
			return self::ETA_INFINITE;
		}
		return max(0, $this->expiresAt - $this->creation);
	}

	/**
	 * @param int $timeout lifetime in seconds counted from creation, <= 0 for a lock that never expires
	 */
	public function setTimeout(int $timeout): self {
		$this->expiresAt = $timeout > 0 ? $this->creation + $timeout : null;

		return $this;
	}

	public function getExpiresAt(): ?int {
		return $this->expiresAt;
	}

	public function setExpiresAt(?int $expiresAt): self {
		$this->expiresAt = $expiresAt;

		return $this;
	}

	public function isInfinite(): bool {
		return $this->expiresAt === null;
	}

	public function isExpired(int $now): bool {
		return $this->expiresAt !== null && $this->expiresAt <= $now;
	}

	/**
	 * Seconds until the lock expires, 0 when it is already expired, ETA_INFINITE when it never expires.
	 */
	public function getETA(): int {
		if ($this->expiresAt === null) {
			return self::ETA_INFINITE;
		}
		$eta = $this->expiresAt - Server::get(ITimeFactory::class)->getTime();
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
		$lock->timeout = $this->isInfinite() ? LockInfo::TIMEOUT_INFINITE : $this->getETA();
		$lock->created = $this->getCreatedAt();
		$lock->scope = LockInfo::EXCLUSIVE;
		$lock->depth = 0;
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
		$this->setExpiresAt(isset($data['expires_at']) ? (int)$data['expires_at'] : null);
		$this->setDisplayName($data['owner'] ?? '');
		// rows written before the scope was persisted on insert hold 0, which is
		// not a valid scope; those locks are exclusive like every other one
		$scope = (int)($data['scope'] ?? 0);
		$this->setScope(in_array($scope, [ILock::LOCK_EXCLUSIVE, ILock::LOCK_SHARED], true) ? $scope : ILock::LOCK_EXCLUSIVE);

		return $this;
	}

	/**
	 * Import a lock from the properties a remote DAV storage reports.
	 */
	public function import(array $data): void {
		$this->setId((int)($data['id'] ?? 0));
		$this->setUri((string)($data['uri'] ?? ''));
		$this->setUserId((string)($data['userId'] ?? $data['user_id'] ?? ''));
		$this->setFileId((int)($data['fileId'] ?? $data['file_id'] ?? 0));
		$this->setToken((string)($data['token'] ?? ''));
		$this->setCreation((int)($data['creation'] ?? 0));
		$this->setLockType((int)($data['type'] ?? ILock::TYPE_USER));
		$this->setTimeout((int)($data['ttl'] ?? 0));
		$this->setDisplayName((string)($data['displayName'] ?? $data['owner'] ?? ''));
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
			'expiresAt' => $this->getExpiresAt(),
			'type' => $this->getType(),
		];
	}

	public function __toString(): string {
		return $this->getToken();
	}
}
