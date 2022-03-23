<?php

namespace OCA\FilesLock\DAV;

use OCA\DAV\Connector\Sabre\CachingTree;
use OCA\DAV\Connector\Sabre\Node as SabreNode;
use OCA\DAV\Connector\Sabre\ObjectTree;
use OCA\FilesLock\AppInfo\Application;
use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Exceptions\UnauthorizedUnlockException;
use OCA\FilesLock\Model\FileLock;
use OCA\FilesLock\Service\FileService;
use OCA\FilesLock\Service\LockService;
use OCP\Files\InvalidPathException;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\OwnerLockedException;
use OCP\Files\NotFoundException;
use OCP\IUserManager;
use OCP\IUserSession;
use Sabre\DAV\INode;
use Sabre\DAV\Locks\Plugin as SabreLockPlugin;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

class LockPlugin extends SabreLockPlugin {

	private LockService $lockService;
	private FileService $fileService;
	private IUserManager $userManager;
	private IUserSession $userSession;

	public function __construct(LockService $lockService, FileService $fileService, IUserManager $userManager, IUserSession $userSession) {
		$this->lockService = $lockService;
		$this->fileService = $fileService;
		$this->userManager = $userManager;
		$this->userSession = $userSession;
	}

	public function initialize(Server $server) {
		$absolute = false;
		switch (get_class($server->tree)) {
			case ObjectTree::class:
				$absolute = false;
				break;

			case CachingTree::class:
				$absolute = true;
				break;
		}
		$this->locksBackend = new LockBackend($server, $this->fileService, $this->lockService, $absolute);
		$server->on('propFind', [$this, 'customProperties']);
		parent::initialize($server);
	}

	public function customProperties(PropFind $propFind, INode $node) {
		if (!$node instanceof SabreNode) {
			return;
		}

		$nodeId = $node->getId();

		$propFind->handle(Application::DAV_PROPERTY_LOCK, function () use ($nodeId) {
			$lock = $this->lockService->getLockForNodeId($nodeId);
			return $lock instanceof FileLock;
		});

		$propFind->handle(Application::DAV_PROPERTY_LOCK_OWNER, function () use ($nodeId) {
			$lock = $this->lockService->getLockForNodeId($nodeId);

			if ($lock === false) {
				return null;
			}

			if ($lock->getLockType() !== ILock::TYPE_USER) {
				return null;
			}

			return $lock->getOwner();
		});

		$propFind->handle(Application::DAV_PROPERTY_LOCK_TIME, function () use ($nodeId) {
			$lock = $this->lockService->getLockForNodeId($nodeId);

			if ($lock === false) {
				return null;
			}

			return $lock->getCreatedAt();
		});

		$propFind->handle(Application::DAV_PROPERTY_LOCK_TIMEOUT, function () use ($nodeId) {
			$lock = $this->lockService->getLockForNodeId($nodeId);

			if ($lock === false) {
				return null;
			}

			return $lock->getTimeout();
		});

		$propFind->handle(Application::DAV_PROPERTY_LOCK_OWNER_DISPLAYNAME, function () use ($nodeId) {
			$lock = $this->lockService->getLockForNodeId($nodeId);

			if ($lock === false) {
				return null;
			}

			if ($lock->getLockType() === ILock::TYPE_APP) {
				return $this->lockService->getAppName($lock->getOwner());
			}

			$user = $this->userManager->get($lock->getOwner());
			if ($user !== null) {
				return $user->getDisplayName();
			}

			return null;
		});

		$propFind->handle(Application::DAV_PROPERTY_LOCK_OWNER_TYPE, function () use ($nodeId) {
			$lock = $this->lockService->getLockForNodeId($nodeId);

			if ($lock === false) {
				return null;
			}

			return $lock->getLockType();
		});

		$propFind->handle(Application::DAV_PROPERTY_LOCK_EDITOR, function () use ($nodeId) {
			$lock = $this->lockService->getLockForNodeId($nodeId);
			if ($lock === false || $lock->getLockType() !== ILock::TYPE_APP) {
				return null;
			}

			return $lock->getOwner();
		});

		$propFind->handle(Application::DAV_PROPERTY_LOCK_TOKEN, function () use ($nodeId) {
			$lock = $this->lockService->getLockForNodeId($nodeId);
			if ($lock === false) {
				return null;
			}

			return $lock->getToken();
		});
	}

	private function getLockDisplayName(ILock $lock): ?string {
		$user = $this->userManager->get($lock->getOwner());
		if ($user !== null) {
			return $user->getDisplayName();
		}

		return null;
	}

	public function httpLock(RequestInterface $request, ResponseInterface $response) {
		if ($request->getHeader('X-User-Lock')) {
			$response->setHeader('Content-Type', 'application/xml; charset=utf-8');

			try {
				$file = $this->fileService->getFileFromAbsoluteUri($this->server->getRequestUri());
				$lockInfo = $this->lockService->lockFileAsUser($file, $this->userSession->getUser());
				$response->setStatus(200);
				$response->setBody(
					$this->server->xml->write(
						'{DAV:}prop',
						$this->getLockProperties($lockInfo)
					)
				);
			} catch (OwnerLockedException $e) {
				$response->setStatus(423);
				$response->setBody(
					$this->server->xml->write(
						'{DAV:}prop',
						$this->getLockProperties($e->getLock())
					)
				);
			}

			return false;
		}

		return parent::httpLock($request, $response);
	}

	public function httpUnlock(RequestInterface $request, ResponseInterface $response) {
		if ($request->getHeader('X-User-Lock')) {
			$response->setHeader('Content-Type', 'application/xml; charset=utf-8');

			try {
				$file = $this->fileService->getFileFromAbsoluteUri($this->server->getRequestUri());
				$this->lockService->unlockFile($file->getId(), $this->userSession->getUser()->getUID());
				$response->setStatus(200);
				$response->setBody(
					$this->server->xml->write(
						'{DAV:}prop',
						$this->getLockProperties(null)
					)
				);
			} catch (LockNotFoundException $e) {
				$response->setStatus(403);
			} catch (UnauthorizedUnlockException $e) {
				$response->setStatus(403);
			} catch (InvalidPathException $e) {
			} catch (NotFoundException $e) {
			}

			return false;
		}

		return parent::httpUnlock($request, $response);
	}

	private function getLockProperties(?FileLock $lock): array {
		return [
			Application::DAV_PROPERTY_LOCK => (bool)$lock,
			Application::DAV_PROPERTY_LOCK_OWNER_TYPE => $lock ? $lock->getLockType() : null,
			Application::DAV_PROPERTY_LOCK_OWNER => $lock ? $lock->getOwner() : null,
			Application::DAV_PROPERTY_LOCK_OWNER_DISPLAYNAME => $lock ? $this->getLockDisplayName($lock) : null,
			Application::DAV_PROPERTY_LOCK_EDITOR => $lock ? $lock->getOwner() : null,
			Application::DAV_PROPERTY_LOCK_TIME => $lock ? $lock->getCreatedAt() : null,
			Application::DAV_PROPERTY_LOCK_TIMEOUT => $lock ? $lock->getTimeout() : null,
			Application::DAV_PROPERTY_LOCK_TOKEN => $lock ? $lock->getToken() : null,
		];
	}
}