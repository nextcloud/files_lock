<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Tests\Feature;

use OC\Files\View;
use OCA\DAV\Connector\Sabre\ServerFactory;
use OCA\FilesLock\Db\LocksRequest;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\File;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use OCP\Files\Mount\IMountManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IPreview;
use OCP\IRequest;
use OCP\ITagManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Auth\Backend\BackendInterface as AuthBackend;
use Sabre\HTTP\Request;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\Response;
use Sabre\HTTP\ResponseInterface;

class StaticAuthBackend implements AuthBackend {
	public function __construct(
		private readonly string $user,
	) {
	}

	public function check(RequestInterface $request, ResponseInterface $response): array {
		return [true, 'principals/users/' . $this->user];
	}

	public function challenge(RequestInterface $request, ResponseInterface $response) {
	}
}

class CapturingSapi {
	private ?Response $response = null;

	public function __construct(
		private readonly Request $request,
	) {
	}

	public function getRequest(): Request {
		return $this->request;
	}

	public function sendResponse(Response $response): void {
		$copy = fopen('php://temp', 'r+');
		$body = $response->getBody();
		if (is_string($body)) {
			fwrite($copy, $body);
		} elseif (is_resource($body)) {
			stream_copy_to_stream($body, $copy);
		} elseif (is_callable($body)) {
			ob_start();
			$body();
			fwrite($copy, (string)ob_get_clean());
		}
		rewind($copy);
		$this->response = new Response($response->getStatus(), $response->getHeaders(), $copy);
	}

	public function getResponse(): Response {
		return $this->response;
	}
}

/**
 * Native WebDAV LOCK/UNLOCK, If-header validation and X-User-Lock through a
 * real Sabre server built by the DAV app.
 */
#[Group(name: 'DB')]
class DavLockTest extends LockTestCase {
	private const string LOCK_BODY = '<D:lockinfo xmlns:D="DAV:"><D:lockscope><D:exclusive/></D:lockscope><D:locktype><D:write/></D:locktype><D:owner>%s</D:owner></D:lockinfo>';

	private ServerFactory $serverFactory;

	protected function setUp(): void {
		parent::setUp();
		$this->serverFactory = new ServerFactory(
			\OCP\Server::get(IConfig::class),
			\OCP\Server::get(LoggerInterface::class),
			\OCP\Server::get(IDBConnection::class),
			\OCP\Server::get(IUserSession::class),
			\OCP\Server::get(IMountManager::class),
			\OCP\Server::get(ITagManager::class),
			$this->createMock(IRequest::class),
			\OCP\Server::get(IPreview::class),
			\OCP\Server::get(IEventDispatcher::class),
			\OCP\Server::get(IFactory::class)->get('dav'),
		);
	}

	/**
	 * @param array<string, string> $headers
	 */
	private function request(string $user, string $method, string $path, ?string $body = null, array $headers = []): Response {
		$this->loginAsUser($user);
		$this->lockService()->clearCache();
		$view = new View('/' . $user . '/files');
		$server = $this->serverFactory->createServer(false, '/', 'dummy', new \Sabre\DAV\Auth\Plugin(new StaticAuthBackend($user)), fn (): \OC\Files\View => $view);
		$stream = null;
		if ($body !== null) {
			$stream = fopen('php://temp', 'r+');
			fwrite($stream, $body);
			rewind($stream);
		}
		$request = new Request($method, $path, $headers, $stream);
		$sapi = new CapturingSapi($request);
		$server->sapi = $sapi;
		$server->httpRequest = $request;
		$server->exec();
		// a refused request never reaches Sabre's afterMethod, so the transactional
		// file lock taken for PUT stays behind; every real request is its own process
		\OCP\Server::get(ILockingProvider::class)->releaseAll();
		$this->lockService()->clearCache();
		return $sapi->getResponse();
	}

	private function body(Response $response): string {
		$body = $response->getBody();
		if (is_resource($body)) {
			rewind($body);
			return (string)stream_get_contents($body);
		}
		return (string)$body;
	}

	private function nativeLock(string $user, string $path, string $owner = 'client', array $headers = []): Response {
		return $this->request($user, 'LOCK', $path, sprintf(self::LOCK_BODY, $owner), $headers + ['Content-Type' => 'application/xml']);
	}

	private function tokenOf(Response $response): string {
		$header = (string)$response->getHeader('Lock-Token');
		self::assertMatchesRegularExpression('/^<opaquelocktoken:.+>$/', $header);
		return substr($header, strlen('<opaquelocktoken:'), -1);
	}

	private function lockProps(string $user, string $path): array {
		$response = $this->request($user, 'PROPFIND', $path, '<d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns"><d:prop><nc:lock/><nc:lock-owner/><nc:lock-owner-type/><nc:lock-timeout/><nc:lock-token/><d:lockdiscovery/></d:prop></d:propfind>', ['Depth' => '0']);
		self::assertSame(207, $response->getStatus());
		$body = $this->body($response);
		$props = [];
		foreach (['lock', 'lock-owner', 'lock-owner-type', 'lock-timeout', 'lock-token'] as $prop) {
			preg_match('#<nc:' . $prop . '>([^<]*)</nc:' . $prop . '>#', $body, $m);
			$props[$prop] = $m[1] ?? null;
		}
		$props['lockdiscovery'] = str_contains($body, '<d:activelock>');
		return $props;
	}

	public function testNativeLockLifecycle(): void {
		$this->setLockTimeoutMinutes(-1);
		$this->toTheFuture(0);
		$file = $this->loginAndGetUserFolder(self::USER1)->newFile('native.txt', 'AAA');

		$response = $this->nativeLock(self::USER1, '/native.txt');
		self::assertSame(200, $response->getStatus());
		$token = $this->tokenOf($response);
		self::assertStringContainsString('<d:timeout>Infinite</d:timeout>', $this->body($response));
		$stored = $this->storedLock($file->getId());
		self::assertSame(self::USER1, $stored?->getOwner());
		self::assertSame(ILock::TYPE_TOKEN, $stored?->getType());
		self::assertSame($token, $stored?->getToken());
		self::assertNull($stored?->getExpiresAt());
		self::assertSame(self::USER1, $stored?->getDisplayName(), 'display name comes from the user, not from the client');

		// refresh with a timeout
		$response = $this->request(self::USER1, 'LOCK', '/native.txt', null, ['If' => '(<opaquelocktoken:' . $token . '>)', 'Timeout' => 'Second-1200']);
		self::assertSame(200, $response->getStatus());
		self::assertStringContainsString('<d:timeout>Second-1200</d:timeout>', $this->body($response));
		self::assertSame($this->time + 1200, $this->storedLock($file->getId())?->getExpiresAt());
		self::assertSame(1, $this->lockRowCount($file->getId()));

		// creator writes with the token, not without it
		self::assertSame(423, $this->request(self::USER1, 'PUT', '/native.txt', 'BBB')->getStatus());
		self::assertContains($this->request(self::USER1, 'PUT', '/native.txt', 'CCC', ['If' => '(<opaquelocktoken:' . $token . '>)'])->getStatus(), [200, 204]);
		self::assertSame('CCC', $this->rootFolder->getUserFolder(self::USER1)->get('native.txt')->getContent());

		// wrong token, then the right one
		self::assertSame(409, $this->request(self::USER1, 'UNLOCK', '/native.txt', null, ['Lock-Token' => '<opaquelocktoken:does-not-exist>'])->getStatus());
		self::assertSame(1, $this->lockRowCount($file->getId()));
		self::assertSame(204, $this->request(self::USER1, 'UNLOCK', '/native.txt', null, ['Lock-Token' => '<opaquelocktoken:' . $token . '>'])->getStatus());
		self::assertSame(0, $this->lockRowCount($file->getId()));
	}

	public function testNativeTimeoutHeaders(): void {
		$this->setLockTimeoutMinutes(30);
		$this->toTheFuture(0);
		$file = $this->loginAndGetUserFolder(self::USER1)->newFile('timeout.txt', 'AAA');

		$response = $this->nativeLock(self::USER1, '/timeout.txt');
		self::assertStringContainsString('<d:timeout>Second-1800</d:timeout>', $this->body($response), 'no header falls back to the configured timeout');
		self::assertSame($this->time + 1800, $this->storedLock($file->getId())?->getExpiresAt());
		self::assertSame('1800', $this->lockProps(self::USER1, '/timeout.txt')['lock-timeout']);
		$this->request(self::USER1, 'UNLOCK', '/timeout.txt', null, ['Lock-Token' => '<opaquelocktoken:' . $this->tokenOf($response) . '>']);

		$response = $this->nativeLock(self::USER1, '/timeout.txt', 'client', ['Timeout' => 'Second-600']);
		self::assertStringContainsString('<d:timeout>Second-600</d:timeout>', $this->body($response));
		self::assertSame($this->time + 600, $this->storedLock($file->getId())?->getExpiresAt());
		self::assertSame('600', $this->lockProps(self::USER1, '/timeout.txt')['lock-timeout']);
		$this->request(self::USER1, 'UNLOCK', '/timeout.txt', null, ['Lock-Token' => '<opaquelocktoken:' . $this->tokenOf($response) . '>']);

		$response = $this->nativeLock(self::USER1, '/timeout.txt', 'client', ['Timeout' => 'Infinite']);
		self::assertStringContainsString('<d:timeout>Infinite</d:timeout>', $this->body($response));
		self::assertNull($this->storedLock($file->getId())?->getExpiresAt());
		// clients read 0 as "never expires"; a negative value lands in the past
		self::assertSame('0', $this->lockProps(self::USER1, '/timeout.txt')['lock-timeout']);
	}

	public function testOwnerMetadataIsNotPersisted(): void {
		$file = $this->loginAndGetUserFolder(self::USER1)->newFile('owner.txt', 'AAA');
		$response = $this->nativeLock(self::USER1, '/owner.txt', '<D:href>Admin (Desktop client)</D:href>');
		self::assertSame(200, $response->getStatus());
		self::assertSame(self::USER1, $this->storedLock($file->getId())?->getDisplayName());
		$this->request(self::USER1, 'UNLOCK', '/owner.txt', null, ['Lock-Token' => '<opaquelocktoken:' . $this->tokenOf($response) . '>']);

		$response = $this->nativeLock(self::USER1, '/owner.txt', str_repeat('x', 5000));
		self::assertSame(200, $response->getStatus());
		self::assertSame(1, $this->lockRowCount($file->getId()));
		self::assertSame(self::USER1, $this->storedLock($file->getId())?->getDisplayName());
	}

	public function testForeignPrincipalCannotUseTheToken(): void {
		$file = $this->sharedFile('foreign.txt');
		$response = $this->nativeLock(self::USER1, '/foreign.txt');
		$token = $this->tokenOf($response);

		self::assertSame($token, $this->lockProps(self::USER2, '/foreign.txt')['lock-token'], 'the token stays discoverable');
		self::assertSame(423, $this->request(self::USER2, 'PUT', '/foreign.txt', 'BBB')->getStatus());
		self::assertSame(423, $this->request(self::USER2, 'PUT', '/foreign.txt', 'BBB', ['If' => '(<opaquelocktoken:' . $token . '>)'])->getStatus(), 'RFC 4918 6.4: the principal must match the lock creator');
		self::assertSame('AAA', $this->rootFolder->getUserFolder(self::USER1)->get('foreign.txt')->getContent());
		self::assertSame(423, $this->request(self::USER2, 'MOVE', '/foreign.txt', null, ['Destination' => '/moved.txt', 'If' => '(<opaquelocktoken:' . $token . '>)'])->getStatus());
		self::assertSame(423, $this->request(self::USER2, 'DELETE', '/foreign.txt', null, ['If' => '(<opaquelocktoken:' . $token . '>)'])->getStatus());
		self::assertSame(1, $this->lockRowCount($file->getId()));
	}

	public function testReadOnlyRecipientCannotReleaseWithThePublishedToken(): void {
		$file = $this->sharedFile('ro-token.txt', 19, 1);
		$response = $this->nativeLock(self::USER2, '/ro-token.txt');
		self::assertSame(200, $response->getStatus());
		$token = $this->tokenOf($response);

		// the token stays discoverable, as RFC 4918 section 6.5 allows
		self::assertSame($token, $this->lockProps(self::USER3, '/ro-token.txt')['lock-token']);

		// but a user who may not write the file may not release its lock
		self::assertSame(403, $this->request(self::USER3, 'UNLOCK', '/ro-token.txt', null, ['Lock-Token' => '<opaquelocktoken:' . $token . '>'])->getStatus());
		self::assertSame(1, $this->lockRowCount($file->getId()));

		// the user it was created for still can
		self::assertSame(204, $this->request(self::USER2, 'UNLOCK', '/ro-token.txt', null, ['Lock-Token' => '<opaquelocktoken:' . $token . '>'])->getStatus());
		self::assertSame(0, $this->lockRowCount($file->getId()));
	}

	public function testNativeLockOverExistingLockIsRefused(): void {
		$file = $this->sharedFile('taken.txt');
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));
		$response = $this->nativeLock(self::USER2, '/taken.txt');
		self::assertSame(423, $response->getStatus());
		self::assertSame(ILock::TYPE_USER, $this->storedLock($file->getId())?->getType());
		self::assertSame(1, $this->lockRowCount($file->getId()));

		// the owner's own user lock is not a fake success either
		$response = $this->nativeLock(self::USER1, '/taken.txt');
		self::assertSame(423, $response->getStatus());
		self::assertSame(ILock::TYPE_USER, $this->storedLock($file->getId())?->getType());
	}

	public function testUserLockCreatorHasTheNativeLifecycle(): void {
		$file = $this->sharedFile('userlock.txt');
		$lock = $this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));

		$props = $this->lockProps(self::USER1, '/userlock.txt');
		self::assertSame('1', $props['lock']);
		self::assertSame($lock->getToken(), $props['lock-token']);
		self::assertTrue($props['lockdiscovery'], 'the creator sees the lock in lockdiscovery');

		self::assertContains($this->request(self::USER1, 'PUT', '/userlock.txt', 'BBB')->getStatus(), [200, 204]);
		self::assertContains($this->request(self::USER1, 'PUT', '/userlock.txt', 'CCC', ['If' => '(<opaquelocktoken:' . $lock->getToken() . '>)'])->getStatus(), [200, 204]);
		self::assertSame(423, $this->request(self::USER2, 'PUT', '/userlock.txt', 'DDD')->getStatus());
		self::assertSame(403, $this->request(self::USER2, 'UNLOCK', '/userlock.txt', null, ['Lock-Token' => '<opaquelocktoken:' . $lock->getToken() . '>'])->getStatus(), 'a user lock is bound to its user');
		self::assertSame(1, $this->lockRowCount($file->getId()));
		self::assertSame(204, $this->request(self::USER1, 'UNLOCK', '/userlock.txt', null, ['Lock-Token' => '<opaquelocktoken:' . $lock->getToken() . '>'])->getStatus());
		self::assertSame(0, $this->lockRowCount($file->getId()));
	}

	public function testXUserLock(): void {
		$file = $this->sharedFile('xuser.txt');
		$folder = $this->loginAndGetUserFolder(self::USER1)->newFolder('xfolder');

		$response = $this->request(self::USER1, 'LOCK', '/xuser.txt', null, ['X-User-Lock' => '1']);
		self::assertSame(200, $response->getStatus());
		self::assertStringContainsString('<nc:lock-owner>' . self::USER1 . '</nc:lock-owner>', $this->body($response));
		self::assertStringContainsString('<nc:lock-timeout>0</nc:lock-timeout>', $this->body($response));

		$response = $this->request(self::USER2, 'LOCK', '/xuser.txt', null, ['X-User-Lock' => '1']);
		self::assertSame(423, $response->getStatus());
		self::assertStringContainsString('<nc:lock-owner>' . self::USER1 . '</nc:lock-owner>', $this->body($response));

		self::assertSame(423, $this->request(self::USER2, 'UNLOCK', '/xuser.txt', null, ['X-User-Lock' => '1'])->getStatus());
		self::assertSame(400, $this->request(self::USER1, 'UNLOCK', '/xuser.txt', null, ['X-User-Lock' => '1', 'X-User-Lock-Type' => '99'])->getStatus());
		self::assertSame(200, $this->request(self::USER1, 'UNLOCK', '/xuser.txt', null, ['X-User-Lock' => '1'])->getStatus());
		self::assertSame(412, $this->request(self::USER1, 'UNLOCK', '/xuser.txt', null, ['X-User-Lock' => '1'])->getStatus());
		self::assertSame(404, $this->request(self::USER1, 'LOCK', '/missing.txt', null, ['X-User-Lock' => '1'])->getStatus());
		self::assertSame(403, $this->request(self::USER1, 'LOCK', '/xfolder', null, ['X-User-Lock' => '1'])->getStatus());
		self::assertSame(0, $this->lockRowCount($folder->getId()));

		// desktop client style token lock: the creator releases it with the header, others do not
		$response = $this->request(self::USER1, 'LOCK', '/xuser.txt', null, ['X-User-Lock' => '1', 'X-User-Lock-Type' => '2']);
		self::assertSame(200, $response->getStatus());
		self::assertSame(ILock::TYPE_TOKEN, $this->storedLock($file->getId())?->getType());
		self::assertSame(423, $this->request(self::USER2, 'UNLOCK', '/xuser.txt', null, ['X-User-Lock' => '1', 'X-User-Lock-Type' => '2'])->getStatus());
		self::assertSame(200, $this->request(self::USER1, 'UNLOCK', '/xuser.txt', null, ['X-User-Lock' => '1', 'X-User-Lock-Type' => '2'])->getStatus());
	}

	public function testAncestorOperationsAreRefusedBeforeMutation(): void {
		$root = $this->loginAndGetUserFolder(self::USER1);
		$dir = $root->newFolder('tree');
		$locked = $dir->newFolder('sub')->newFile('locked.txt', 'important');
		$this->shareWith($dir, self::USER1, self::USER2, 31);
		$this->lockManager->lock(new LockContext($locked, ILock::TYPE_USER, self::USER1));

		self::assertSame(423, $this->request(self::USER2, 'DELETE', '/tree/sub')->getStatus());
		self::assertSame(423, $this->request(self::USER2, 'MOVE', '/tree/sub', null, ['Destination' => '/tree/sub2'])->getStatus());
		self::assertSame(423, $this->request(self::USER2, 'DELETE', '/tree/sub/locked.txt')->getStatus());

		$this->loginAsUser(self::USER1);
		$this->lockService()->clearCache();
		self::assertSame('important', $this->rootFolder->getUserFolder(self::USER1)->get('tree/sub/locked.txt')->getContent());
		self::assertSame(1, $this->lockRowCount($locked->getId()));
		self::assertSame(0, count(\OCP\Server::get(LocksRequest::class)->getLocksBelow($dir->getId())) - 1);
	}

	public function testNativeClientMayLockACollection(): void {
		$folder = $this->loginAndGetUserFolder(self::USER1)->newFolder('coll');
		$folder->newFile('inside.txt', 'AAA');
		$response = $this->nativeLock(self::USER1, '/coll/');
		self::assertSame(200, $response->getStatus());
		$token = $this->tokenOf($response);
		self::assertSame(1, $this->lockRowCount($folder->getId()));
		self::assertSame(403, $this->request(self::USER1, 'LOCK', '/coll/', null, ['X-User-Lock' => '1'])->getStatus(), 'the application paths still refuse folders');
		self::assertSame(204, $this->request(self::USER1, 'UNLOCK', '/coll/', null, ['Lock-Token' => '<opaquelocktoken:' . $token . '>'])->getStatus());
		self::assertSame(0, $this->lockRowCount($folder->getId()));
	}

	public function testOtherUsersLockIsVisibleButNotUsable(): void {
		$file = $this->sharedFile('visible.txt');
		$this->lockManager->lock(new LockContext($file, ILock::TYPE_USER, self::USER1));
		$props = $this->lockProps(self::USER2, '/visible.txt');
		self::assertSame('1', $props['lock']);
		self::assertSame(self::USER1, $props['lock-owner']);
		self::assertSame('0', $props['lock-owner-type']);
		self::assertSame(423, $this->request(self::USER2, 'PROPPATCH', '/visible.txt', '<d:propertyupdate xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:set><d:prop><oc:favorite>1</oc:favorite></d:prop></d:set></d:propertyupdate>')->getStatus());
	}

	#[\Override]
	protected function sharedFile(string $name, int $permissions = 19, ?int $permissionsUser3 = null): File {
		return parent::sharedFile($name, $permissions, $permissionsUser3);
	}
}
