<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\DAV;

use OCA\DAV\Connector\Sabre\Directory;
use OCA\DAV\Connector\Sabre\FakeLockerPlugin;
use OCA\DAV\Connector\Sabre\File;
use OCA\DAV\Connector\Sabre\FilesPlugin;
use OCA\DAV\Connector\Sabre\Node as SabreNode;
use OCA\FilesLock\AppInfo\Application;
use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Exceptions\NotFileException;
use OCA\FilesLock\Exceptions\UnauthorizedUnlockException;
use OCA\FilesLock\Model\FileLock;
use OCA\FilesLock\Service\LockService;
use OCP\AppFramework\Http;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use OCP\Files\Lock\OwnerLockedException;
use OCP\Files\Node;
use OCP\IUserSession;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\Locked;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\INode;
use Sabre\DAV\Locks\Plugin as SabreLockPlugin;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

class LockPlugin extends SabreLockPlugin {
	private const array SUPPORTED_LOCK_TYPES = [ILock::TYPE_USER, ILock::TYPE_APP, ILock::TYPE_TOKEN];

	public function __construct(
		private readonly LockService $lockService,
		private readonly IUserSession $userSession,
	) {
	}

	#[\Override]
	public function initialize(Server $server): void {
		$fakePlugin = $server->getPlugins()[FakeLockerPlugin::class] ?? null;
		if ($fakePlugin) {
			$server->removeListener('method:LOCK', [$fakePlugin, 'fakeLockProvider']);
			$server->removeListener('method:UNLOCK', [$fakePlugin, 'fakeUnlockProvider']);
			$server->removeListener('propFind', [$fakePlugin, 'propFind']);
			$server->removeListener('validateTokens', [$fakePlugin, 'validateTokens']);
		}

		$this->locksBackend = new LockBackend(
			$this->lockService,
			fn (string $uri): Node => $this->resolveNode($uri),
			$this->userSession,
		);
		$server->on('propFind', $this->customProperties(...));
		parent::initialize($server);
	}

	/**
	 * Resolve a request uri through the DAV tree, whichever tree the server uses.
	 *
	 * @throws NotFound
	 */
	private function resolveNode(string $uri): Node {
		$node = $this->server->tree->getNodeForPath($uri);
		if (!$node instanceof SabreNode) {
			throw new NotFound('Resource is not a file system node');
		}
		return $node->getNode();
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

			$ids[] = (int)$id;
		}

		$ids[] = (int)$directory->getId();
		// the lock service will take care of the caching
		$this->lockService->getLockForNodeIds($ids);
		$this->lockService->prefetchRemoteLocks($directory->getNode());
	}

	public function customProperties(PropFind $propFind, INode $node): void {
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

		$propFind->handle(Application::DAV_PROPERTY_LOCK, function () use ($nodeId, $node): bool {
			$lock = $this->lockService->getLockForNodeId($nodeId, $node->getNode());
			return $lock instanceof FileLock;
		});

		$propFind->handle(Application::DAV_PROPERTY_LOCK_OWNER, function () use ($nodeId, $node): ?string {
			$lock = $this->lockService->getLockForNodeId($nodeId, $node->getNode());

			if ($lock === false) {
				return null;
			}

			if ($lock->getType() === ILock::TYPE_APP) {
				return null;
			}

			return $lock->getOwner();
		});

		$propFind->handle(Application::DAV_PROPERTY_LOCK_TIME, function () use ($nodeId, $node): ?int {
			$lock = $this->lockService->getLockForNodeId($nodeId, $node->getNode());

			if ($lock === false) {
				return null;
			}

			return $lock->getCreatedAt();
		});

		$propFind->handle(Application::DAV_PROPERTY_LOCK_TIMEOUT, function () use ($nodeId, $node): ?int {
			$lock = $this->lockService->getLockForNodeId($nodeId, $node->getNode());

			if ($lock === false) {
				return null;
			}

			return $this->davTimeout($lock);
		});

		$propFind->handle(Application::DAV_PROPERTY_LOCK_OWNER_DISPLAYNAME, function () use ($nodeId, $node): ?string {
			$lock = $this->lockService->getLockForNodeId($nodeId, $node->getNode());

			if ($lock === false) {
				return null;
			}

			$this->lockService->injectMetadata($lock);

			return $lock->getDisplayName();
		});

		$propFind->handle(Application::DAV_PROPERTY_LOCK_OWNER_TYPE, function () use ($nodeId, $node): ?int {
			$lock = $this->lockService->getLockForNodeId($nodeId, $node->getNode());

			if ($lock === false) {
				return null;
			}

			return $lock->getType();
		});

		$propFind->handle(Application::DAV_PROPERTY_LOCK_EDITOR, function () use ($nodeId, $node): ?string {
			$lock = $this->lockService->getLockForNodeId($nodeId, $node->getNode());
			if ($lock === false || $lock->getType() !== ILock::TYPE_APP) {
				return null;
			}

			return $lock->getOwner();
		});

		$propFind->handle(Application::DAV_PROPERTY_LOCK_TOKEN, function () use ($nodeId, $node): ?string {
			$lock = $this->lockService->getLockForNodeId($nodeId, $node->getNode());
			if ($lock === false) {
				return null;
			}

			return $lock->getToken();
		});
	}

	/**
	 * Replace Sabre's token-only validation with the application policy: a lock
	 * blocks a modifying request unless the acting principal may write the file
	 * (owner of a user lock, or owner of a token lock presenting its token).
	 *
	 * @param mixed $conditions
	 */
	#[\Override]
	public function validateTokens(RequestInterface $request, &$conditions): void {
		$this->lockService->resetPresentedTokens();
		foreach ($conditions as $condition) {
			foreach ($condition['tokens'] as $token) {
				if (str_starts_with((string)$token['token'], 'opaquelocktoken:')) {
					$this->lockService->presentToken(substr((string)$token['token'], 16));
				}
			}
		}

		$method = $request->getMethod();
		if ($method === 'LOCK') {
			parent::validateTokens($request, $conditions);
			return;
		}

		/** @var LockBackend $backend */
		$backend = $this->locksBackend;
		$mustLocks = [];
		switch ($method) {
			case 'DELETE':
				$mustLocks = $backend->getFileLocks($request->getPath(), true);
				break;
			case 'MKCOL':
			case 'MKCALENDAR':
			case 'PROPPATCH':
			case 'PUT':
			case 'PATCH':
				$mustLocks = $backend->getFileLocks($request->getPath(), false);
				break;
			case 'MOVE':
				$mustLocks = array_merge(
					$backend->getFileLocks($request->getPath(), true),
					$backend->getFileLocks($this->server->calculateUri($request->getHeader('Destination')), false)
				);
				break;
			case 'COPY':
				$mustLocks = $backend->getFileLocks($this->server->calculateUri($request->getHeader('Destination')), false);
				break;
		}

		$byToken = [];
		foreach ($mustLocks as $lock) {
			$byToken[$lock->getToken()] = $lock;
		}

		foreach ($conditions as $kk => $condition) {
			foreach ($condition['tokens'] as $ii => $token) {
				if (!str_starts_with((string)$token['token'], 'opaquelocktoken:')) {
					continue;
				}
				$checkToken = substr((string)$token['token'], 16);
				if (isset($byToken[$checkToken])) {
					$conditions[$kk]['tokens'][$ii]['validToken'] = true;
					continue;
				}
				foreach ($backend->getFileLocks($condition['uri'], false) as $oddLock) {
					if ($oddLock->getToken() === $checkToken) {
						$conditions[$kk]['tokens'][$ii]['validToken'] = true;
						continue 2;
					}
				}
			}
		}

		foreach ($byToken as $lock) {
			if (!$this->lockService->canWrite($lock, null)) {
				throw new Locked($lock->toLockInfo());
			}
		}
	}

	#[\Override]
	public function httpLock(RequestInterface $request, ResponseInterface $response) {
		if ($request->getHeader('X-User-Lock')) {
			$lockType = $this->getRequestedLockType($request);
			$response->setHeader('Content-Type', 'application/xml; charset=utf-8');

			$file = $this->resolveNode($this->server->getRequestUri());
			$user = $this->userSession->getUser();
			if ($user === null) {
				throw new Forbidden('Locking requires an authenticated user');
			}

			$user = $this->userSession->getUser();
			if ($user === null) {
				throw new \LogicException('User not logged in');
			}

			try {
				$lockInfo = $this->lockService->acquire(new LockContext(
					$file, $lockType, $user->getUID()
				));
				$response->setStatus(200);
				$response->setBody(
					$this->server->xml->write(
						'{DAV:}prop',
						$this->getLockProperties($lockInfo, $file)
					)
				);
			} catch (OwnerLockedException $e) {
				$existing = $e->getLock();
				$response->setStatus(423);
				$response->setBody(
					$this->server->xml->write(
						'{DAV:}prop',
						$this->getLockProperties($existing instanceof FileLock ? $existing : null, $file)
					)
				);
			} catch (NotFileException|UnauthorizedUnlockException $e) {
				throw new Forbidden($e->getMessage());
			}

			return false;
		}

		return parent::httpLock($request, $response);
	}

	#[\Override]
	public function httpUnlock(RequestInterface $request, ResponseInterface $response) {
		if ($request->getHeader('X-User-Lock')) {
			$lockType = $this->getRequestedLockType($request);
			$response->setHeader('Content-Type', 'application/xml; charset=utf-8');

			$file = $this->resolveNode($this->server->getRequestUri());
			$user = $this->userSession->getUser();
			if ($user === null) {
				throw new Forbidden('Unlocking requires an authenticated user');
			}

			try {
				$this->lockService->unlock(new LockContext(
					$file, $lockType, $user->getUID()
				));
				$response->setStatus(200);
				$response->setBody(
					$this->server->xml->write(
						'{DAV:}prop',
						$this->getLockProperties(null, $file)
					)
				);
			} catch (LockNotFoundException) {
				$response->setStatus(Http::STATUS_PRECONDITION_FAILED);
				$response->setBody(
					$this->server->xml->write(
						'{DAV:}prop',
						$this->getLockProperties(null, $file)
					)
				);
			} catch (UnauthorizedUnlockException) {
				$lock = $this->lockService->getActiveLock($file->getId());
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

		return parent::httpUnlock($request, $response);
	}

	private function getRequestedLockType(RequestInterface $request): int {
		$header = $request->getHeader('X-User-Lock-Type');
		if ($header === null || $header === '') {
			return ILock::TYPE_USER;
		}
		if (!is_numeric($header) || !in_array((int)$header, self::SUPPORTED_LOCK_TYPES, true)) {
			throw new \Sabre\DAV\Exception\BadRequest('Unsupported lock type');
		}
		return (int)$header;
	}

	private function getLockProperties(?FileLock $lock, Node $file): array {
		if ($lock !== null) {
			$this->lockService->injectMetadata($lock);
		}
		// the lock change updated the etag in the cache, read it back from there
		$etag = $file->getStorage()->getCache()->get($file->getInternalPath())?->getEtag() ?? $file->getEtag();
		return [
			FilesPlugin::GETETAG_PROPERTYNAME => $etag,
			Application::DAV_PROPERTY_LOCK => $lock !== null,
			Application::DAV_PROPERTY_LOCK_OWNER_TYPE => $lock ? $lock->getType() : null,
			Application::DAV_PROPERTY_LOCK_OWNER => $lock ? $lock->getOwner() : null,
			Application::DAV_PROPERTY_LOCK_OWNER_DISPLAYNAME => $lock ? $lock->getDisplayName() : null,
			Application::DAV_PROPERTY_LOCK_EDITOR => $lock?->getType() === ILock::TYPE_APP
				? $lock->getOwner()
				: null,
			Application::DAV_PROPERTY_LOCK_TIME => $lock ? $lock->getCreatedAt() : null,
			Application::DAV_PROPERTY_LOCK_TIMEOUT => $lock ? $this->davTimeout($lock) : null,
			Application::DAV_PROPERTY_LOCK_TOKEN => $lock ? $lock->getToken() : null,
		];
	}

	/**
	 * Lifetime as clients read it: 0 means the lock never expires. A lock that
	 * never expires has a negative lifetime internally, and sending that raw put
	 * the expiry date in the past.
	 */
	private function davTimeout(FileLock $lock): int {
		return max(0, $lock->getTimeout());
	}
}
