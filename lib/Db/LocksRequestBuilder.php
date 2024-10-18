<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Db;

use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Model\FileLock;
use OCA\FilesLock\Service\ConfigService;
use OCA\FilesLock\Tools\Exceptions\RowNotFoundException;
use OCA\FilesLock\Tools\Traits\TArrayTools;

/**
 * Class LocksRequestBuilder
 *
 * @package OCA\FilesLock\Db
 */
class LocksRequestBuilder extends CoreRequestBuilder {
	use TArrayTools;


	/** @var ConfigService */
	private $configService;


	public function __construct(ConfigService $configService) {
		$this->configService = $configService;
	}

	/**
	 * Base of the Sql Insert request
	 *
	 * @return CoreQueryBuilder
	 */
	protected function getLocksInsertSql(): CoreQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_LOCKS);

		return $qb;
	}


	/**
	 * Base of the Sql Update request
	 *
	 * @return CoreQueryBuilder
	 */
	protected function getLocksUpdateSql(): CoreQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_LOCKS);

		return $qb;
	}


	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @return CoreQueryBuilder
	 */
	protected function getLocksSelectSql(): CoreQueryBuilder {
		$qb = $this->getQueryBuilder();

		$qb->select('l.id', 'l.user_id', 'l.file_id', 'l.token', 'l.creation', 'l.type', 'l.ttl', 'l.owner')
			->from(self::TABLE_LOCKS, 'l');

		$qb->setDefaultSelectAlias('l');

		return $qb;
	}


	/**
	 * Base of the Sql Delete request
	 *
	 * @return CoreQueryBuilder
	 */
	protected function getLocksDeleteSql(): CoreQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_LOCKS);

		return $qb;
	}


	/**
	 * @param CoreQueryBuilder $qb
	 *
	 * @return FileLock
	 * @throws LockNotFoundException
	 */
	protected function getLockFromRequest(CoreQueryBuilder $qb): FileLock {
		/** @var FileLock $result */
		try {
			$result = $qb->getRow([$this, 'parseLockSelectSql']);
		} catch (RowNotFoundException $e) {
			throw new LockNotFoundException($e->getMessage());
		}

		return $result;
	}


	/**
	 * @param CoreQueryBuilder $qb
	 *
	 * @return FileLock[]
	 */
	public function getLocksFromRequest(CoreQueryBuilder $qb): array {
		/** @var FileLock[] $result */
		$result = $qb->getRows([$this, 'parseLockSelectSql']);

		return $result;
	}


	/**
	 * @param array $data
	 *
	 * @return FileLock
	 */
	public function parseLockSelectSql(array $data): FileLock {
		$lock = new FileLock($this->configService->getTimeoutSeconds());
		$lock->importFromDatabase($data);

		return $lock;
	}
}
