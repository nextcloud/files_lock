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
	private int $timeout = 1800;
	private int $creation = 0;
	/** @var ILock::TYPE_* */
	private int $lockType = ILock::TYPE_USER;
	private ?string $displayName = null;
	private string $owner = '';
	private int $scope = ILock::LOCK_EXCLUSIVE;

	public function __construct(int $timeout = 1800) {
		$this->timeout = $timeout;
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

	public function getOwner(): string {
		return $this->userId;
	}

	public function setUserId(string $userId): self {
		$this->userId = $userId;

		return $this;
	}

	public function getFileId(): int {
		return $this->fileId;
	}

	public function setFileId(int $fileId): self {
		$this->fileId = $fileId;

		return $this;
	}

	public function getToken(): string {
		return $this->token;
	}

	public function setToken(string $token): self {
		$this->token = $token;

		return $this;
	}

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

	public function getCreatedAt(): int {
		return $this->creation;
	}

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



	public function importFromDatabase(array $data): self {
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

	/**
	 * @param string $k
	 * @param array $arr
	 * @param string $default
	 *
	 * @return string
	 */
	protected function get(string $k, array $arr, string $default = ''): string {
		if (!array_key_exists($k, $arr)) {
			$subs = explode('.', $k, 2);
			if (sizeof($subs) > 1) {
				if (!array_key_exists($subs[0], $arr)) {
					return $default;
				}

				$r = $arr[$subs[0]];
				if (!is_array($r)) {
					return $default;
				}

				return $this->get($subs[1], $r, $default);
			} else {
				return $default;
			}
		}

		if ($arr[$k] === null || !is_string($arr[$k]) && (!is_int($arr[$k]))) {
			return $default;
		}

		return (string)$arr[$k];
	}


	/**
	 * @param string $k
	 * @param array $arr
	 * @param int $default
	 *
	 * @return int
	 */
	protected function getInt(string $k, array $arr, int $default = 0): int {
		if (!array_key_exists($k, $arr)) {
			$subs = explode('.', $k, 2);
			if (sizeof($subs) > 1) {
				if (!array_key_exists($subs[0], $arr)) {
					return $default;
				}

				$r = $arr[$subs[0]];
				if (!is_array($r)) {
					return $default;
				}

				return $this->getInt($subs[1], $r, $default);
			} else {
				return $default;
			}
		}

		if ($arr[$k] === null) {
			return $default;
		}

		return intval($arr[$k]);
	}
}
