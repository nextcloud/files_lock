<?php

namespace OCA\FilesLock\Service;

use OCA\FilesLock\Exceptions\NotFileException;
use OCA\FilesLock\Model\FileLock;
use OCP\App\IAppManager;
use OCP\DirectEditing\IManager;
use OCP\DirectEditing\RegisterDirectEditorEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Node;
use OCP\PreConditionNotMetException;

class AppLockService {

	private LockService $lockService;
	private IAppManager $appManager;
	private ConfigService $configService;
	private IManager $directEditingManager;
	private IEventDispatcher $eventDispatcher;

	private ?string $appInScope = null;
	private ?array $directEditors = null;

	public function __construct(LockService $lockService, IAppManager $appManager, ConfigService $configService, IManager $directEditingManager, IEventDispatcher $eventDispatcher) {
		$this->lockService = $lockService;
		$this->appManager = $appManager;
		$this->configService = $configService;
		$this->directEditingManager = $directEditingManager;
		$this->eventDispatcher = $eventDispatcher;
	}

	public function lockFileAsApp(Node $file, string $appId): FileLock {
		if (!$this->appManager->isEnabledForUser($appId)) {
			throw new PreConditionNotMetException('App is not enabled for the user');
		}

		if ($file->getType() !== Node::TYPE_FILE) {
			throw new NotFileException('Must be a file, seems to be a folder.');
		}

		$lock = new FileLock($this->configService->getTimeoutSeconds());
		$lock->setLockType(FileLock::LOCK_TYPE_APP);
		$lock->setUserId($appId);
		$lock->setFileId($file->getId());

		$this->lockService->lock($lock);

		return $lock;
	}

	public function unlockFileAsApp(Node $file, string $appId): FileLock {
		if (!$this->appManager->isEnabledForUser($appId)) {
			throw new PreConditionNotMetException('App is not enabled for the user');
		}

		if ($file->getType() !== Node::TYPE_FILE) {
			throw new NotFileException('Must be a file, seems to be a folder.');
		}

		$lock = new FileLock($this->configService->getTimeoutSeconds());
		$lock->setLockType(FileLock::LOCK_TYPE_APP);
		$lock->setUserId($appId);
		$lock->setFileId($file->getId());

		$this->lockService->unlock($lock);

		return $lock;
	}

	public function executeInAppScope(string $appId, callable $callback): void {
		if ($this->appInScope) {
			throw new PreConditionNotMetException('Could not obtain app scope as already in use by ' . $this->appInScope);
		}

		try {
			$this->appInScope = $appId;
			$callback();
		} finally {
			$this->appInScope = null;
		}
	}

	public function getAppInScope(): ?string {
		return $this->appInScope;
	}

	public function getAppName(string $appId): ?string {
		$appInfo = $this->appManager->getAppInfo($appId);
		return $appInfo['name'] ?? null;
	}

	public function getDirectEditorForAppId(string $appId): ?string {
		if (!$this->directEditors) {
			$this->eventDispatcher->dispatchTyped(new RegisterDirectEditorEvent($this->directEditingManager));
			$this->directEditors = $this->directEditingManager->getEditors();
		}
		$editor = current(array_filter($this->directEditors, function ($editor) use ($appId) {
			return $editor->getId() === $appId;
		}));
		return $editor ? $editor->getId() : null;
	}

}
