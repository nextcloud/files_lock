<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\FilesLock\DAV;

use OCA\DAV\Connector\Sabre\CachingTree;
use OCA\DAV\Connector\Sabre\Directory;
use OCA\DAV\Connector\Sabre\FakeLockerPlugin;
use OCA\DAV\Connector\Sabre\File;
use OCA\DAV\Connector\Sabre\FilesPlugin;
use OCA\DAV\Connector\Sabre\ObjectTree;
use OCA\FilesLock\AppInfo\Application;
use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Exceptions\UnauthorizedUnlockException;
use OCA\FilesLock\Model\FileLock;
use OCA\FilesLock\Service\FileService;
use OCA\FilesLock\Service\LockService;
use OCP\AppFramework\Http;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use OCP\Files\Lock\OwnerLockedException;
use OCP\Files\Node;
use OCP\IUserSession;
use Sabre\DAV\Exception\LockTokenMatchesRequestUri;
use Sabre\DAV\INode;
use Sabre\DAV\Locks\Plugin as SabreLockPlugin;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

class LockPlugin extends SabreLockPlugin {
	private LockService $lockService;
	private FileService $fileService;
	private IUserSession $userSession;

	public function __construct(LockService $lockService, FileService $fileService, IUserSession $userSession) {
		$this->lockService = $lockService;
		$this->fileService = $fileService;
		$this->userSession = $userSession;
	}

	public function initialize(Server $server) {
		$fakePlugin = $server->getPlugins()[FakeLockerPlugin::class] ?? null;
		if ($fakePlugin) {
			$server->removeListener('method:LOCK', [$fakePlugin, 'fakeLockProvider']);
			$server->removeListener('method:UNLOCK', [$fakePlugin, 'fakeUnlockProvider']);
			$server->removeListener('propFind', [$fakePlugin, 'propFind']);
			$server->removeListener('validateTokens', [$fakePlugin, 'validateTokens']);
		}

		$absolute = false;
		switch (get_class($server->tree)) {
			case ObjectTree::class:
				$absolute = false;
				break;

			case CachingTree::class:
				$absolute = true;
				break;
		}
		$this->locksBackend = new LockBackend($this->fileService, $this->lockService, $absolute);
		$server->on('propFind', [$this, 'customProperties']);
		parent::initialize($server);
	}

	private function cacheDirectory(Directory $directory): void {
		$children = $directory->getChildren();

		$ids = [];
		foreach ($children as $child) {
			if (!($child instanceof File || $child instanceof Directory)) {
				continue;
			}

			$id = $child->getId();
			if ($id === null) {
				continue;
			}

			$ids[] = (string)$id;
		}

		$ids[] = (string)$directory->getId();
		// the lock service will take care of the caching
		$this->lockService->getLockForNodeIds($ids);
	}

	public function customProperties(PropFind $propFind, INode $node) {
		if (!($node instanceof File) && !($node instanceof Directory)) {
			return;
		}
		if ($node instanceof Directory
			&& $propFind->getDepth() !== 0
			&& !is_null($propFind->getStatus(Application::DAV_PROPERTY_LOCK))
		) {
			$this->cacheDirectory($node);
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

			if ($lock->getType() === ILock::TYPE_APP) {
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

			$this->lockService->injectMetadata($lock);

			return $lock->getDisplayName();
		});

		$propFind->handle(Application::DAV_PROPERTY_LOCK_OWNER_TYPE, function () use ($nodeId) {
			$lock = $this->lockService->getLockForNodeId($nodeId);

			if ($lock === false) {
				return null;
			}

			return $lock->getType();
		});

		$propFind->handle(Application::DAV_PROPERTY_LOCK_EDITOR, function () use ($nodeId) {
			$lock = $this->lockService->getLockForNodeId($nodeId);
			if ($lock === false || $lock->getType() !== ILock::TYPE_APP) {
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

	public function httpLock(RequestInterface $request, ResponseInterface $response) {
		if ($request->getHeader('X-User-Lock')) {
			$lockType = (int)($request->getHeader('X-User-Lock-Type') ?? ILock::TYPE_USER);
			$response->setHeader('Content-Type', 'application/xml; charset=utf-8');

			$file = $this->fileService->getFileFromAbsoluteUri($this->server->getRequestUri());

			try {
				$lockInfo = $this->lockService->lock(new LockContext(
					$file, $lockType, $this->userSession->getUser()->getUID()
				));
				$response->setStatus(200);
				$response->setBody(
					$this->server->xml->write(
						'{DAV:}prop',
						$this->getLockProperties($lockInfo, $file)
					)
				);
			} catch (OwnerLockedException $e) {
				$response->setStatus(423);
				$response->setBody(
					$this->server->xml->write(
						'{DAV:}prop',
						$this->getLockProperties($e->getLock(), $file)
					)
				);
			}

			return false;
		}

		return parent::httpLock($request, $response);
	}

	public function httpUnlock(RequestInterface $request, ResponseInterface $response) {
		if ($request->getHeader('X-User-Lock')) {
			$lockType = (int)($request->getHeader('X-User-Lock-Type') ?? ILock::TYPE_USER);
			$response->setHeader('Content-Type', 'application/xml; charset=utf-8');

			$file = $this->fileService->getFileFromAbsoluteUri($this->server->getRequestUri());

			try {
				$this->lockService->enableUserOverride();
				$this->lockService->unlock(new LockContext(
					$file, $lockType, $this->userSession->getUser()->getUID()
				));
				$response->setStatus(200);
				$response->setBody(
					$this->server->xml->write(
						'{DAV:}prop',
						$this->getLockProperties(null, $file)
					)
				);
			} catch (LockNotFoundException $e) {
				$response->setStatus(Http::STATUS_PRECONDITION_FAILED);
				$response->setBody(
					$this->server->xml->write(
						'{DAV:}prop',
						$this->getLockProperties(null, $file)
					)
				);
			} catch (UnauthorizedUnlockException $e) {
				$lock = $this->lockService->getLockFromFileId($file->getId());
				$response->setStatus(Http::STATUS_LOCKED);
				$response->setBody(
					$this->server->xml->write(
						'{DAV:}prop',
						$this->getLockProperties($lock, $file)
					)
				);
			}

			return false;
		}

		try {
			return parent::httpUnlock($request, $response);
		} catch (LockTokenMatchesRequestUri $e) {
			// Skip logging with wrong lock token
			return false;
		}
	}

	private function getLockProperties(?FileLock $lock, Node $file): array {
		// We need to fetch the node again to get the proper new Etag
		$actingUser = ($file->getOwner() ? $file->getOwner()->getUID() : null) ?? $this->userSession->getUser()->getUID();
		$file = $this->fileService->getFileFromId($actingUser, $file->getId());
		return [
			FilesPlugin::GETETAG_PROPERTYNAME => $file->getEtag(),
			Application::DAV_PROPERTY_LOCK => $lock !== null,
			Application::DAV_PROPERTY_LOCK_OWNER_TYPE => $lock ? $lock->getType() : null,
			Application::DAV_PROPERTY_LOCK_OWNER => $lock ? $lock->getOwner() : null,
			Application::DAV_PROPERTY_LOCK_OWNER_DISPLAYNAME => $lock ? $lock->getDisplayName() : null,
			Application::DAV_PROPERTY_LOCK_EDITOR => $lock ? $lock->getOwner() : null,
			Application::DAV_PROPERTY_LOCK_TIME => $lock ? $lock->getCreatedAt() : null,
			Application::DAV_PROPERTY_LOCK_TIMEOUT => $lock ? $lock->getTimeout() : null,
			Application::DAV_PROPERTY_LOCK_TOKEN => $lock ? $lock->getToken() : null,
		];
	}
}
