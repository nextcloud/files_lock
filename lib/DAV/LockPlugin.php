<?php

namespace OCA\FilesLock\DAV;

use OCA\DAV\Connector\Sabre\CachingTree;
use OCA\DAV\Connector\Sabre\Node as SabreNode;
use OCA\DAV\Connector\Sabre\ObjectTree;
use OCA\FilesLock\AppInfo\Application;
use OCA\FilesLock\Model\FileLock;
use OCA\FilesLock\Service\AppLockService;
use OCA\FilesLock\Service\FileService;
use OCA\FilesLock\Service\LockService;
use OCP\DirectEditing\IManager;
use OCP\DirectEditing\RegisterDirectEditorEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserManager;
use OCP\IUserSession;
use Sabre\DAV\INode;
use Sabre\DAV\Locks\Plugin;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

class LockPlugin extends Plugin {

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

	/**
	 * @param PropFind $propFind
	 * @param INode $node
	 *
	 * @return void
	 */
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

			if ($lock !== false && $lock->getLockType() === FileLock::LOCK_TYPE_USER) {
				return $lock->getUserId();
			}

			return null;
		});

		$propFind->handle(Application::DAV_PROPERTY_LOCK_TIME, function () use ($nodeId) {
			$lock = $this->lockService->getLockForNodeId($nodeId);

			if ($lock !== false) {
				return $lock->getCreation();
			}

			return null;
		});

		$propFind->handle(Application::DAV_PROPERTY_LOCK_OWNER_DISPLAYNAME, function () use ($nodeId) {
			$lock = $this->lockService->getLockForNodeId($nodeId);

			if ($lock === false) {
				return null;
			}

			if ($lock->getLockType() === FileLock::LOCK_TYPE_APP) {
				return \OC::$server->get(AppLockService::class)->getAppName($lock->getUserId());
			}

			$user = $this->userManager->get($lock->getUserId());
			if ($user !== null) {
				return $user->getDisplayName();
			}

			return null;
		});

		$propFind->handle(Application::DAV_PROPERTY_LOCK_OWNER_TYPE, function () use ($nodeId) {
			$lock = $this->lockService->getLockForNodeId($nodeId);

			if ($lock !== false) {
				return $lock->getLockType();
			}

			return null;
		});

		$propFind->handle(Application::DAV_PROPERTY_LOCK_EDITOR, function () use ($nodeId) {
			$lock = $this->lockService->getLockForNodeId($nodeId);
			if ($lock === false || $lock->getLockType() !== FileLock::LOCK_TYPE_APP) {
				return null;
			}

			return $lock->getUserId();
		});
	}

	public function httpLock(RequestInterface $request, ResponseInterface $response) {
		if ($request->getHeader('X-User-Lock')) {
			$file = $this->fileService->getFileFromAbsoluteUri($this->server->getRequestUri());
			$this->lockService->lockFileAsUser($file, $this->userSession->getUser());
			$response->setHeader('Content-Type', 'application/xml; charset=utf-8');
			//$response->setHeader('Lock-Token', '<opaquelocktoken:'.$lockInfo->token.'>');
			//$response->setStatus($newFile ? 201 : 200);
			//$response->setBody($this->generateLockResponse($lockInfo));
			$response->setStatus(200);
			return false;
		}
		return parent::httpLock($request, $response);
	}

	public function httpUnlock(RequestInterface $request, ResponseInterface $response) {
		if ($request->getHeader('X-User-Lock')) {
			$file = $this->fileService->getFileFromAbsoluteUri($this->server->getRequestUri());
			$this->lockService->unlockFile($file->getId(), $this->userSession->getUser()->getUID());
			$response->setHeader('Content-Type', 'application/xml; charset=utf-8');
			//$response->setHeader('Lock-Token', '<opaquelocktoken:'.$lockInfo->token.'>');
			//$response->setStatus($newFile ? 201 : 200);
			//$response->setBody($this->generateLockResponse($lockInfo));
			$response->setStatus(200);
			return false;
		}
		return parent::httpLock($request, $response);
	}
}
